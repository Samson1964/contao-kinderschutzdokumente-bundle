<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\StatusRechner;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus;

/**
 * Verbindet Datenbank und StatusRechner: Stand einer Person, Matrix für das
 * Backend, Vollständigkeit je Veranstaltung für das Frontend.
 */
class Statusdienst
{
	/**
	 * @param DokumentartRepository   $arten           Die Dokumentarten
	 * @param NachweisRepository      $nachweise       Die Nachweise
	 * @param PersonenRepository      $personen        Mitglieder
	 * @param VeranstaltungRepository $veranstaltungen Veranstaltungen und Teilnahmen
	 * @param Einstellungen           $einstellungen   Gruppen und Vorlauf
	 */
	public function __construct(
		private readonly DokumentartRepository $arten,
		private readonly NachweisRepository $nachweise,
		private readonly PersonenRepository $personen,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly Einstellungen $einstellungen,
	) {
	}

	/**
	 * Liefert einen StatusRechner mit dem eingestellten Vorlauf.
	 *
	 * @return StatusRechner Der Rechner
	 */
	public function rechner(): StatusRechner
	{
		return new StatusRechner($this->einstellungen->vorlaufAblaufTage());
	}

	/**
	 * Liefert die aktiven Dokumentarten.
	 *
	 * @return list<Dokumentart> Die Arten in Anzeigereihenfolge
	 */
	public function arten(): array
	{
		return $this->arten->aktive();
	}

	/**
	 * Berechnet den Stand einer Person zu allen aktiven Arten.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $stichtag Zeitstempel des Stichtags, 0:00 Uhr
	 *
	 * @return array<int, Zellstatus> Stand je Dokumentart-ID
	 */
	public function fuerMitglied(int $mitglied, int $stichtag): array
	{
		return $this->rechner()->zeile($this->arten(), $this->nachweise->fuerMitglied($mitglied), $stichtag);
	}

	/**
	 * Berechnet für jede Veranstaltung eines Mitglieds, was fehlt.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $heute    Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return list<array{veranstaltung: array<string, mixed>, fehlend: list<Dokumentart>}>
	 *         Je noch nicht beendeter Veranstaltung die fehlenden Pflichtarten
	 *         am letzten Veranstaltungstag
	 */
	public function veranstaltungenFuerMitglied(int $mitglied, int $heute): array
	{
		$arten = $this->arten();
		$nachweise = $this->nachweise->fuerMitglied($mitglied);
		$rechner = $this->rechner();
		$ergebnis = array();

		foreach ($this->veranstaltungen->fuerMitglied($mitglied, $heute) as $veranstaltung) {
			$stichtag = StatusRechner::stichtagVeranstaltung((int) $veranstaltung['startDate'], (int) ($veranstaltung['endDate'] ?? 0));
			$ergebnis[] = array(
				'veranstaltung' => $veranstaltung,
				'fehlend'       => $rechner->fehlend($arten, $rechner->zeile($arten, $nachweise, $stichtag)),
			);
		}

		return $ergebnis;
	}

	/**
	 * Berechnet die Matrix für die Übersicht im Backend.
	 *
	 * @param int|null $veranstaltung ID einer Veranstaltung, oder null für alle
	 *                                Mitglieder der Gruppe der
	 *                                freigeschalteten Mitglieder mit Stichtag heute
	 * @param int      $jetzt         Aktueller Zeitpunkt
	 *
	 * @return array{
	 *     arten: list<Dokumentart>,
	 *     stichtag: int,
	 *     veranstaltung: array<string, mixed>|null,
	 *     zeilen: list<array{person: array{id: int, firstname: string, lastname: string, email: string}, geloescht: bool, zellen: array<int, Zellstatus>, vollstaendig: bool, wartet: bool}>,
	 *     zaehler: array{vollstaendig: int, unvollstaendig: int, wartend: int}
	 * }
	 */
	public function matrix(?int $veranstaltung, int $jetzt): array
	{
		$arten = $this->arten();
		$zeile = null === $veranstaltung ? null : $this->veranstaltungen->eine($veranstaltung);

		if (null === $zeile) {
			$stichtag = Kalender::tag($jetzt);
			$personen = $this->personen->mitgliederInGruppen($this->einstellungen->gruppen(), $jetzt);
		} else {
			$stichtag = StatusRechner::stichtagVeranstaltung((int) $zeile['startDate'], (int) ($zeile['endDate'] ?? 0));
			$personen = $this->teilnehmerSortiert((int) $zeile['id']);
		}

		$alleNachweise = $this->nachweise->fuerMitglieder(array_column($personen, 'id'));
		$rechner = $this->rechner();
		$zeilen = array();
		$zaehler = array('vollstaendig' => 0, 'unvollstaendig' => 0, 'wartend' => 0);

		foreach ($personen as $person) {
			$zellen = $rechner->zeile($arten, $alleNachweise[$person['id']] ?? array(), $stichtag);
			$vollstaendig = $rechner->vollstaendig($arten, $zellen);
			$wartet = array() !== array_filter($zellen, static fn (Zellstatus $zelle): bool => $zelle->wartet());

			++$zaehler[$vollstaendig ? 'vollstaendig' : 'unvollstaendig'];
			$zaehler['wartend'] += $wartet ? 1 : 0;

			$zeilen[] = array(
				'person'       => $person,
				'geloescht'    => (bool) ($person['geloescht'] ?? false),
				'zellen'       => $zellen,
				'vollstaendig' => $vollstaendig,
				'wartet'       => $wartet,
			);
		}

		return array(
			'arten'         => $arten,
			'stichtag'      => $stichtag,
			'veranstaltung' => $zeile,
			'zeilen'        => $zeilen,
			'zaehler'       => $zaehler,
		);
	}

	/**
	 * Liefert die Teilnehmer einer Veranstaltung nach Namen sortiert.
	 *
	 * Gelöschte Mitglieder bleiben als leere Person mit ihrer ID und
	 * „geloescht“ stehen, damit die Lücke in der Matrix sichtbar ist.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 *
	 * @return list<array{id: int, firstname: string, lastname: string, email: string, geloescht: bool}>
	 */
	private function teilnehmerSortiert(int $veranstaltung): array
	{
		$personen = array();

		foreach ($this->veranstaltungen->teilnehmer($veranstaltung) as $id) {
			$person = $this->personen->mitglied($id);
			$personen[] = null === $person
				? array('id' => $id, 'firstname' => '', 'lastname' => '', 'email' => '', 'geloescht' => true)
				: $person + array('geloescht' => false);
		}

		usort($personen, static fn (array $a, array $b): int => array($a['lastname'], $a['firstname'], $a['id']) <=> array($b['lastname'], $b['firstname'], $b['id']));

		return $personen;
	}
}
