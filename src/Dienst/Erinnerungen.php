<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Erinnerungsplan;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\StatusRechner;

/**
 * Verschickt die Erinnerungsmails des täglichen Cronjobs.
 *
 * Ein Versandmerker wird nur gesetzt, wenn die Mail tatsächlich hinausging.
 * Scheitert der Versand, versucht es der nächste Lauf erneut.
 */
class Erinnerungen
{
	/**
	 * @param Statusdienst            $status           Arten und Rechner
	 * @param NachweisRepository      $nachweise        Nachweise und Merker
	 * @param PersonenRepository      $personen         Empfänger
	 * @param VeranstaltungRepository $veranstaltungen  Kommende Teilnahmen
	 * @param Einstellungen           $einstellungen    Gruppen und Vorlauf
	 * @param Benachrichtigung        $benachrichtigung Der Mailversand
	 */
	public function __construct(
		private readonly Statusdienst $status,
		private readonly NachweisRepository $nachweise,
		private readonly PersonenRepository $personen,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly Einstellungen $einstellungen,
		private readonly Benachrichtigung $benachrichtigung,
	) {
	}

	/**
	 * Verschickt die fälligen Mails vor und bei Ablauf.
	 *
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Anzahl verschickter Mails
	 */
	public function ablaufVersenden(int $jetzt): int
	{
		$heute = Kalender::tag($jetzt);
		$plan = $this->plan();
		$arten = $this->status->arten();
		$personen = $this->personen->mitgliederInGruppen($this->einstellungen->gruppen(), $jetzt);
		$alleNachweise = $this->nachweise->fuerMitglieder(array_column($personen, 'id'));
		$anzahl = 0;

		foreach ($personen as $person) {
			foreach ($arten as $art) {
				$faellig = $plan->ablauf($alleNachweise[$person['id']][$art->id] ?? array(), $heute);

				if (null === $faellig) {
					continue;
				}

				list($vorlage, $nachweis) = $faellig;
				$gueltigBis = (int) $nachweis->gueltigBis;
				$verschickt = Erinnerungsplan::VOR_ABLAUF === $vorlage
					? $this->benachrichtigung->vorAblauf($person, $art->name, $gueltigBis)
					: $this->benachrichtigung->abgelaufen($person, $art->name, $gueltigBis);

				if ($verschickt) {
					$this->nachweise->aendern($nachweis->id, array(Erinnerungsplan::VOR_ABLAUF === $vorlage ? 'erinnertVorAblauf' : 'erinnertAblauf' => $jetzt));
					++$anzahl;
				}
			}
		}

		return $anzahl;
	}

	/**
	 * Verschickt die Mails über fehlende Unterlagen vor Veranstaltungen.
	 *
	 * Angeschrieben werden nur Teilnehmer, die noch aktive Mitglieder einer
	 * Gruppe der freigeschalteten Mitglieder sind; wer gesperrt oder ausgetreten ist,
	 * bekommt keine Mail.
	 *
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Anzahl verschickter Mails
	 */
	public function veranstaltungenVersenden(int $jetzt): int
	{
		$heute = Kalender::tag($jetzt);
		$plan = $this->plan();
		$rechner = $this->status->rechner();
		$arten = $this->status->arten();
		$gruppen = $this->einstellungen->gruppen();
		$anzahl = 0;

		foreach ($this->veranstaltungen->kommendeTeilnahmen($heute) as $teilnahme) {
			if (!$plan->veranstaltungFaellig((int) $teilnahme['startDate'], $heute, (int) $teilnahme['erinnertAm'])) {
				continue;
			}

			$person = $this->personen->mitglied((int) $teilnahme['member']);

			if (null === $person || !$this->personen->istInGruppen($person['id'], $gruppen, $jetzt)) {
				continue;
			}

			$stichtag = StatusRechner::stichtagVeranstaltung((int) $teilnahme['startDate'], (int) ($teilnahme['endDate'] ?? 0));
			$fehlend = $rechner->fehlend($arten, $rechner->zeile($arten, $this->nachweise->fuerMitglied($person['id']), $stichtag));

			if (array() === $fehlend) {
				continue;
			}

			$namen = array_map(static fn (Dokumentart $art): string => $art->name, $fehlend);

			if ($this->benachrichtigung->veranstaltung($person, $teilnahme, $namen)) {
				$this->veranstaltungen->merkeErinnert((int) $teilnahme['teilnahme'], $jetzt);
				++$anzahl;
			}
		}

		return $anzahl;
	}

	/**
	 * Baut den Erinnerungsplan mit den eingestellten Vorläufen.
	 *
	 * @return Erinnerungsplan Der Plan
	 */
	private function plan(): Erinnerungsplan
	{
		return new Erinnerungsplan($this->einstellungen->vorlaufAblaufTage(), $this->einstellungen->vorlaufVeranstaltungTage());
	}
}
