<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank;

use Doctrine\DBAL\Connection;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\StatusRechner;

/**
 * Liest und schreibt Veranstaltungen und Teilnahmen.
 *
 * Es gibt nur wenige Veranstaltungen. Zeitliche Filter („noch nicht vorbei“)
 * laufen deshalb in PHP über StatusRechner::stichtagVeranstaltung, damit die
 * Regel „letzter Tag, ersatzweise erster Tag“ nur an einer Stelle steht.
 */
class VeranstaltungRepository
{
	/** Teilnahme vom Mitglied selbst angelegt; darf es selbst lösen */
	public const QUELLE_FRONTEND = 'frontend';

	/** Teilnahme vom Prüfer angelegt; im Frontend gesperrt */
	public const QUELLE_BACKEND = 'backend';

	/**
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liefert alle Veranstaltungen, die jüngste zuerst.
	 *
	 * @return list<array<string, mixed>> Zeilen aus tl_kinderschutz_veranstaltung
	 */
	public function alle(): array
	{
		return $this->db->fetchAllAssociative('SELECT * FROM tl_kinderschutz_veranstaltung ORDER BY startDate DESC, id DESC');
	}

	/**
	 * Liefert eine Veranstaltung.
	 *
	 * @param int $id ID der Veranstaltung
	 *
	 * @return array<string, mixed>|null Die Zeile, oder null
	 */
	public function eine(int $id): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz_veranstaltung WHERE id = ?', array($id));

		return false === $zeile ? null : $zeile;
	}

	/**
	 * Liefert die Veranstaltungen, die im Frontend zur Anmeldung stehen.
	 *
	 * @param int $heute Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return list<array<string, mixed>> Wählbare, noch nicht beendete
	 *                                    Veranstaltungen, die früheste zuerst
	 */
	public function waehlbare(int $heute): array
	{
		$zeilen = array_values(array_filter(
			$this->db->fetchAllAssociative("SELECT * FROM tl_kinderschutz_veranstaltung WHERE waehlbar = '1' ORDER BY startDate, id"),
			fn (array $zeile): bool => !$this->istVorbei($zeile, $heute)
		));

		return $zeilen;
	}

	/**
	 * Liefert die Veranstaltungen eines Mitglieds samt Herkunft der Zuordnung.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $heute    Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return list<array<string, mixed>> Noch nicht beendete Veranstaltungen mit
	 *                                    zusätzlicher Spalte „quelle“
	 */
	public function fuerMitglied(int $mitglied, int $heute): array
	{
		$zeilen = $this->db->fetchAllAssociative(
			'SELECT v.*, t.quelle FROM tl_kinderschutz_veranstaltung v INNER JOIN tl_kinderschutz_teilnahme t ON t.veranstaltung = v.id WHERE t.member = ? ORDER BY v.startDate, v.id',
			array($mitglied)
		);

		return array_values(array_filter($zeilen, fn (array $zeile): bool => !$this->istVorbei($zeile, $heute)));
	}

	/**
	 * Liefert die Mitglieder einer Veranstaltung.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 *
	 * @return list<int> IDs der Mitglieder
	 */
	public function teilnehmer(int $veranstaltung): array
	{
		return array_map('intval', $this->db->fetchFirstColumn(
			'SELECT member FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ? ORDER BY id',
			array($veranstaltung)
		));
	}

	/**
	 * Gleicht die Teilnehmer einer Veranstaltung mit einer Auswahl im Backend ab.
	 *
	 * Bestehende Zeilen bleiben mit ihrer Herkunft und ihrem Versandmerker
	 * erhalten; neu ausgewählte Mitglieder bekommen „backend“, abgewählte
	 * werden gelöscht, auch selbst angemeldete.
	 *
	 * @param int       $veranstaltung ID der Veranstaltung
	 * @param list<int> $mitglieder    Die ausgewählten Mitglieder
	 * @param int       $jetzt         Aktueller Zeitpunkt
	 */
	public function setzeTeilnehmer(int $veranstaltung, array $mitglieder, int $jetzt): void
	{
		$soll = array_values(array_unique(array_map('intval', $mitglieder)));
		$ist = $this->teilnehmer($veranstaltung);

		foreach (array_diff($ist, $soll) as $mitglied) {
			$this->db->delete('tl_kinderschutz_teilnahme', array('veranstaltung' => $veranstaltung, 'member' => $mitglied));
		}

		foreach (array_diff($soll, $ist) as $mitglied) {
			$this->einfuegen($veranstaltung, $mitglied, self::QUELLE_BACKEND, $jetzt);
		}
	}

	/**
	 * Meldet ein Mitglied im Frontend zu einer Veranstaltung an.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 * @param int $mitglied      ID des Mitglieds
	 * @param int $heute         Zeitstempel des heutigen Tags, 0:00 Uhr
	 * @param int $jetzt         Aktueller Zeitpunkt
	 *
	 * @return bool true, wenn die Anmeldung gilt; false, wenn die Veranstaltung
	 *              nicht wählbar oder vorbei ist. Eine schon bestehende
	 *              Teilnahme bleibt unverändert und zählt als Erfolg.
	 */
	public function anmelden(int $veranstaltung, int $mitglied, int $heute, int $jetzt): bool
	{
		$zeile = $this->eine($veranstaltung);

		if (null === $zeile || '1' !== (string) $zeile['waehlbar'] || $this->istVorbei($zeile, $heute)) {
			return false;
		}

		if (!in_array($mitglied, $this->teilnehmer($veranstaltung), true)) {
			$this->einfuegen($veranstaltung, $mitglied, self::QUELLE_FRONTEND, $jetzt);
		}

		return true;
	}

	/**
	 * Löst eine selbst angelegte Teilnahme.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 * @param int $mitglied      ID des Mitglieds
	 *
	 * @return bool true, wenn eine Zeile gelöscht wurde; false bei einer vom
	 *              Prüfer angelegten oder nicht vorhandenen Teilnahme
	 */
	public function abmelden(int $veranstaltung, int $mitglied): bool
	{
		return $this->db->delete('tl_kinderschutz_teilnahme', array(
			'veranstaltung' => $veranstaltung,
			'member'        => $mitglied,
			'quelle'        => self::QUELLE_FRONTEND,
		)) > 0;
	}

	/**
	 * Liefert die Teilnahmen an Veranstaltungen, die noch nicht begonnen haben.
	 *
	 * @param int $heute Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return list<array<string, mixed>> Zeilen mit teilnahme, member,
	 *                                    erinnertAm, veranstaltung, name,
	 *                                    startDate, endDate
	 */
	public function kommendeTeilnahmen(int $heute): array
	{
		$zeilen = $this->db->fetchAllAssociative(
			'SELECT t.id AS teilnahme, t.member, t.erinnertAm, v.id AS veranstaltung, v.name, v.startDate, v.endDate FROM tl_kinderschutz_teilnahme t INNER JOIN tl_kinderschutz_veranstaltung v ON v.id = t.veranstaltung ORDER BY v.startDate, t.id'
		);

		return array_values(array_filter($zeilen, static fn (array $zeile): bool => Kalender::tag((int) $zeile['startDate']) > $heute));
	}

	/**
	 * Vermerkt den Versand der Veranstaltungsmail an einer Teilnahme.
	 *
	 * @param int $teilnahme ID in tl_kinderschutz_teilnahme
	 * @param int $jetzt     Zeitpunkt des Versands
	 */
	public function merkeErinnert(int $teilnahme, int $jetzt): void
	{
		$this->db->update('tl_kinderschutz_teilnahme', array('erinnertAm' => $jetzt), array('id' => $teilnahme));
	}

	/**
	 * Löscht alle Teilnahmen einer Veranstaltung.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 */
	public function loescheTeilnahmen(int $veranstaltung): void
	{
		$this->db->delete('tl_kinderschutz_teilnahme', array('veranstaltung' => $veranstaltung));
	}

	/**
	 * Fügt eine Teilnahme ein.
	 *
	 * @param int    $veranstaltung ID der Veranstaltung
	 * @param int    $mitglied      ID des Mitglieds
	 * @param string $quelle        QUELLE_FRONTEND oder QUELLE_BACKEND
	 * @param int    $jetzt         Aktueller Zeitpunkt
	 */
	private function einfuegen(int $veranstaltung, int $mitglied, string $quelle, int $jetzt): void
	{
		$this->db->insert('tl_kinderschutz_teilnahme', array(
			'tstamp'        => $jetzt,
			'veranstaltung' => $veranstaltung,
			'member'        => $mitglied,
			'quelle'        => $quelle,
			'angelegtAm'    => $jetzt,
		));
	}

	/**
	 * Sagt, ob eine Veranstaltung vorbei ist.
	 *
	 * @param array<string, mixed> $zeile Zeile mit startDate und endDate
	 * @param int                  $heute Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return bool true, wenn ihr letzter Tag vor heute liegt
	 */
	private function istVorbei(array $zeile, int $heute): bool
	{
		return StatusRechner::stichtagVeranstaltung((int) $zeile['startDate'], (int) ($zeile['endDate'] ?? 0)) < $heute;
	}
}
