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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;

/**
 * Liest und schreibt Nachweise in tl_kinderschutz.
 *
 * Nur einfache SQL-Befehle, die MySQL und SQLite (Tests) gleich verstehen,
 * und nur DBAL-Methoden, die es in DBAL 3 (Contao 4.13) und DBAL 4
 * (Contao 5.7) gibt. ID-Listen werden als geprüfte Ganzzahlen eingesetzt,
 * weil ArrayParameterType erst ab DBAL 3.6 existiert.
 */
class NachweisRepository
{
	/**
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liest eine Zeile.
	 *
	 * @param int $id ID des Nachweises
	 *
	 * @return array<string, mixed>|null Die Zeile, oder null, wenn es sie nicht gibt
	 */
	public function zeile(int $id): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz WHERE id = ?', array($id));

		return false === $zeile ? null : $zeile;
	}

	/**
	 * Liest alle Nachweise mehrerer Mitglieder, gruppiert nach Mitglied und Art.
	 *
	 * Datensätze mit tstamp 0 legt Contao beim Klick auf „Neu“ an, bevor sie
	 * gespeichert sind. Sie tragen schon den Vorgabestatus „geprueft“, aber
	 * noch kein Datum und kein „gültig bis“, und zählen deshalb nicht mit.
	 *
	 * @param list<int> $mitglieder IDs der Mitglieder
	 *
	 * @return array<int, array<int, list<Nachweis>>> Mitglieds-ID → Dokumentart-ID
	 *                                                → Nachweise; Mitglieder ohne
	 *                                                Nachweise fehlen
	 */
	public function fuerMitglieder(array $mitglieder): array
	{
		$ids = $this->idListe($mitglieder);

		if ('' === $ids) {
			return array();
		}

		$ergebnis = array();

		foreach ($this->db->fetchAllAssociative('SELECT * FROM tl_kinderschutz WHERE tstamp > 0 AND member IN ('.$ids.') ORDER BY id') as $zeile) {
			$nachweis = Nachweis::ausZeile($zeile);
			$ergebnis[$nachweis->member][$nachweis->dokumentart][] = $nachweis;
		}

		return $ergebnis;
	}

	/**
	 * Liest alle Nachweise eines Mitglieds, gruppiert nach Art.
	 *
	 * @param int $mitglied ID des Mitglieds
	 *
	 * @return array<int, list<Nachweis>> Dokumentart-ID → Nachweise
	 */
	public function fuerMitglied(int $mitglied): array
	{
		return $this->fuerMitglieder(array($mitglied))[$mitglied] ?? array();
	}

	/**
	 * Legt einen Nachweis an.
	 *
	 * @param array<string, mixed> $werte Spalten und Werte; tstamp wird gesetzt
	 *
	 * @return int Die neue ID
	 */
	public function anlegen(array $werte): int
	{
		$this->db->insert('tl_kinderschutz', array_merge(array('tstamp' => time()), $werte));

		return (int) $this->db->lastInsertId();
	}

	/**
	 * Ändert Spalten eines Nachweises.
	 *
	 * @param int                  $id    ID des Nachweises
	 * @param array<string, mixed> $werte Spalten und neue Werte; tstamp wird
	 *                                    nicht verändert, damit die Liste im
	 *                                    Backend nicht bei jedem Merker umsortiert
	 */
	public function aendern(int $id, array $werte): void
	{
		$this->db->update('tl_kinderschutz', $werte, array('id' => $id));
	}

	/**
	 * Liefert die IDs der wartenden Einreichungen einer Person zu einer Art.
	 *
	 * @param int $mitglied    ID des Mitglieds
	 * @param int $dokumentart ID der Dokumentart
	 *
	 * @return list<int> IDs im Status „eingereicht“
	 */
	public function wartende(int $mitglied, int $dokumentart): array
	{
		return array_map('intval', $this->db->fetchFirstColumn(
			'SELECT id FROM tl_kinderschutz WHERE member = ? AND dokumentart = ? AND status = ?',
			array($mitglied, $dokumentart, Nachweis::EINGEREICHT)
		));
	}

	/**
	 * Liefert die IDs aller Nachweise, deren Datei im Papierkorb liegt.
	 *
	 * @param int $grenze Nur Einträge mit papierkorbSeit < Grenze; 0 = alle
	 *
	 * @return list<int> IDs
	 */
	public function imPapierkorb(int $grenze = 0): array
	{
		$sql = "SELECT id FROM tl_kinderschutz WHERE papierkorbSeit > 0 AND datei != ''";
		$parameter = array();

		if ($grenze > 0) {
			$sql .= ' AND papierkorbSeit < ?';
			$parameter[] = $grenze;
		}

		return array_map('intval', $this->db->fetchFirstColumn($sql, $parameter));
	}

	/**
	 * Liefert die IDs der Nachweise eines Mitglieds mit Datei außerhalb des Papierkorbs.
	 *
	 * @param int $mitglied ID des Mitglieds
	 *
	 * @return list<int> IDs
	 */
	public function mitDatei(int $mitglied): array
	{
		return array_map('intval', $this->db->fetchFirstColumn(
			"SELECT id FROM tl_kinderschutz WHERE member = ? AND datei != '' AND papierkorbSeit = 0",
			array($mitglied)
		));
	}

	/**
	 * Zählt die Nachweise einer Dokumentart.
	 *
	 * @param int $dokumentart ID der Dokumentart
	 *
	 * @return int Anzahl, auch ersetzte und abgelehnte
	 */
	public function anzahlFuerArt(int $dokumentart): int
	{
		return (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz WHERE dokumentart = ?', array($dokumentart));
	}

	/**
	 * Setzt eine ID-Liste als geprüfte Ganzzahlen zusammen.
	 *
	 * @param list<int> $ids IDs
	 *
	 * @return string Etwa „3,7,12“, leer bei leerer Liste
	 */
	private function idListe(array $ids): string
	{
		return implode(',', array_unique(array_map('intval', $ids)));
	}
}
