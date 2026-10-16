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

/**
 * Liest Mitglieder (tl_member) und Prüfer (tl_user).
 *
 * Die Spalte „groups“ ist in MySQL 8 ein reserviertes Wort und wird deshalb
 * immer über quoteIdentifier() angesprochen. Contao speichert die Gruppen
 * serialisiert; die LIKE-Abfrage ist nur ein Vorfilter, die genaue Prüfung
 * geschieht nach dem Entpacken.
 */
class PersonenRepository
{
	/**
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liefert die aktiven Mitglieder der angegebenen Gruppen.
	 *
	 * Aktiv heißt: nicht gesperrt und innerhalb von „Aktivieren am“ und
	 * „Deaktivieren am“, wie Contao es bei der Anmeldung prüft.
	 *
	 * @param list<int> $gruppen IDs der Mitgliedergruppen
	 * @param int       $jetzt   Aktueller Zeitpunkt
	 *
	 * @return list<array{id: int, firstname: string, lastname: string, email: string}>
	 *         Nach Nachname und Vorname sortiert; leer ohne Gruppen
	 */
	public function mitgliederInGruppen(array $gruppen, int $jetzt): array
	{
		$gruppen = array_values(array_unique(array_map('intval', $gruppen)));

		if (array() === $gruppen) {
			return array();
		}

		$spalte = $this->db->quoteIdentifier('groups');
		$bedingungen = array();
		$parameter = array();

		foreach ($gruppen as $gruppe) {
			$bedingungen[] = $spalte.' LIKE ?';
			$parameter[] = '%"'.$gruppe.'"%';
		}

		$zeilen = $this->db->fetchAllAssociative(
			'SELECT id, firstname, lastname, email, '.$spalte.' AS gruppen, disable, start, stop FROM tl_member WHERE '.implode(' OR ', $bedingungen).' ORDER BY lastname, firstname',
			$parameter
		);

		$ergebnis = array();

		foreach ($zeilen as $zeile) {
			if (!$this->istAktiv($zeile, $jetzt) || array() === array_intersect($gruppen, $this->gruppenAus($zeile['gruppen']))) {
				continue;
			}

			$ergebnis[] = $this->person($zeile);
		}

		return $ergebnis;
	}

	/**
	 * Liefert ein Mitglied, auch ein gesperrtes.
	 *
	 * @param int $id ID des Mitglieds
	 *
	 * @return array{id: int, firstname: string, lastname: string, email: string}|null
	 *         Das Mitglied, oder null, wenn es gelöscht ist
	 */
	public function mitglied(int $id): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT id, firstname, lastname, email FROM tl_member WHERE id = ?', array($id));

		return false === $zeile ? null : $this->person($zeile);
	}

	/**
	 * Sagt, ob ein Mitglied aktiv ist und zu einer der Gruppen gehört.
	 *
	 * @param int       $id      ID des Mitglieds
	 * @param list<int> $gruppen IDs der Mitgliedergruppen
	 * @param int       $jetzt   Aktueller Zeitpunkt
	 *
	 * @return bool true, wenn das Mitglied kinderschutzpflichtig ist
	 */
	public function istInGruppen(int $id, array $gruppen, int $jetzt): bool
	{
		$zeile = $this->db->fetchAssociative(
			'SELECT '.$this->db->quoteIdentifier('groups').' AS gruppen, disable, start, stop FROM tl_member WHERE id = ?',
			array($id)
		);

		return false !== $zeile
			&& $this->istAktiv($zeile, $jetzt)
			&& array() !== array_intersect(array_map('intval', $gruppen), $this->gruppenAus($zeile['gruppen']));
	}

	/**
	 * Sagt, ob ein Mitglied existiert und sich anmelden könnte.
	 *
	 * @param int $id    ID des Mitglieds
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return bool true für ein aktives Mitglied, gleich in welcher Gruppe
	 */
	public function istAktivesMitglied(int $id, int $jetzt): bool
	{
		$zeile = $this->db->fetchAssociative('SELECT disable, start, stop FROM tl_member WHERE id = ?', array($id));

		return false !== $zeile && $this->istAktiv($zeile, $jetzt);
	}

	/**
	 * Nimmt ein Mitglied in eine Gruppe auf.
	 *
	 * Die übrigen Gruppen bleiben; gespeichert wird wie bei Contao eine
	 * serialisierte Liste von Zeichenketten (4.13 und 5.7 gleich).
	 *
	 * Seiteneffekt: schreibt tl_member.groups.
	 *
	 * @param int $id     ID des Mitglieds
	 * @param int $gruppe ID der Gruppe
	 *
	 * @return bool true, wenn die Gruppe ergänzt wurde; false, wenn das
	 *              Mitglied fehlt, schon drin ist oder keine Gruppe übergeben wurde
	 */
	public function inGruppeAufnehmen(int $id, int $gruppe): bool
	{
		$spalte = $this->db->quoteIdentifier('groups');
		$wert = $this->db->fetchOne('SELECT '.$spalte.' FROM tl_member WHERE id = ?', array($id));

		if (false === $wert || $gruppe <= 0) {
			return false;
		}

		$gruppen = $this->gruppenAus($wert);

		if (in_array($gruppe, $gruppen, true)) {
			return false;
		}

		$gruppen[] = $gruppe;
		$this->db->executeStatement('UPDATE tl_member SET '.$spalte.' = ? WHERE id = ?', array(serialize(array_map('strval', $gruppen)), $id));

		return true;
	}

	/**
	 * Liefert die aktiven Mitglieder, die nicht in der Gruppe sind.
	 *
	 * Für die Auswahl beim direkten Freischalten. Gelesen wird die ganze
	 * Mitgliedertabelle; bei einigen Tausend Mitgliedern ist das unkritisch.
	 *
	 * @param int $gruppe ID der Gruppe der freigeschalteten Mitglieder
	 * @param int $jetzt  Aktueller Zeitpunkt
	 *
	 * @return list<array{id: int, firstname: string, lastname: string, email: string}>
	 *         Nach Nachname und Vorname sortiert
	 */
	public function aktiveOhneGruppe(int $gruppe, int $jetzt): array
	{
		$spalte = $this->db->quoteIdentifier('groups');
		$zeilen = $this->db->fetchAllAssociative('SELECT id, firstname, lastname, email, '.$spalte.' AS gruppen, disable, start, stop FROM tl_member ORDER BY lastname, firstname');
		$ergebnis = array();

		foreach ($zeilen as $zeile) {
			if ($this->istAktiv($zeile, $jetzt) && !in_array($gruppe, $this->gruppenAus($zeile['gruppen']), true)) {
				$ergebnis[] = $this->person($zeile);
			}
		}

		return $ergebnis;
	}

	/**
	 * Liefert die E-Mail-Adressen aller Prüfer.
	 *
	 * @param int $hauptadmin ID des Hauptadmins
	 *
	 * @return list<string> Adressen des Hauptadmins und der freigegebenen,
	 *                      nicht gesperrten Benutzer
	 */
	public function prueferAdressen(int $hauptadmin): array
	{
		$zeilen = $this->db->fetchAllAssociative(
			"SELECT email, disable FROM tl_user WHERE (id = ? OR kinderschutzPruefer = '1') AND email != '' ORDER BY id",
			array($hauptadmin)
		);

		return array_values(array_unique(array_column(array_filter($zeilen, static fn (array $zeile): bool => !$zeile['disable']), 'email')));
	}

	/**
	 * Liefert den Namen eines Backend-Benutzers.
	 *
	 * @param int $id ID in tl_user
	 *
	 * @return string Der Name, oder „#ID“, wenn der Benutzer gelöscht ist
	 */
	public function benutzerName(int $id): string
	{
		$name = $this->db->fetchOne('SELECT name FROM tl_user WHERE id = ?', array($id));

		return false === $name ? '#'.$id : (string) $name;
	}

	/**
	 * Setzt den Anzeigenamen „Nachname, Vorname“ zusammen.
	 *
	 * @param array{firstname: string, lastname: string} $person Mitglied
	 *
	 * @return string Der Name
	 */
	public static function name(array $person): string
	{
		return trim($person['lastname'].', '.$person['firstname'], ', ');
	}

	/**
	 * Prüft Sperre und Aktivierungszeitraum eines Mitglieds.
	 *
	 * „disable“ ist in 4.13 ein char(1) ('' oder '1'), in 5.7 ein boolean
	 * (0 oder 1). Die Umwandlung in bool deckt beide ab, auch die Zeichenkette
	 * „0“, die MySQL für den boolean liefert.
	 *
	 * @param array<string, mixed> $zeile Zeile mit disable, start, stop
	 * @param int                  $jetzt Aktueller Zeitpunkt
	 *
	 * @return bool true, wenn sich das Mitglied anmelden könnte
	 */
	private function istAktiv(array $zeile, int $jetzt): bool
	{
		$start = (string) $zeile['start'];
		$stop = (string) $zeile['stop'];

		return !$zeile['disable']
			&& ('' === $start || (int) $start <= $jetzt)
			&& ('' === $stop || (int) $stop > $jetzt);
	}

	/**
	 * Entpackt die serialisierte Gruppenliste eines Mitglieds.
	 *
	 * @param mixed $wert Inhalt der Spalte „groups“
	 *
	 * @return list<int> IDs der Gruppen
	 */
	private function gruppenAus(mixed $wert): array
	{
		$liste = is_string($wert) && '' !== $wert ? @unserialize($wert, array('allowed_classes' => false)) : array();

		return is_array($liste) ? array_map('intval', array_values($liste)) : array();
	}

	/**
	 * Wandelt eine Zeile in die Personenstruktur des Bundles.
	 *
	 * @param array<string, mixed> $zeile Zeile aus tl_member
	 *
	 * @return array{id: int, firstname: string, lastname: string, email: string}
	 */
	private function person(array $zeile): array
	{
		return array(
			'id'        => (int) $zeile['id'],
			'firstname' => (string) $zeile['firstname'],
			'lastname'  => (string) $zeile['lastname'],
			'email'     => (string) $zeile['email'],
		);
	}
}
