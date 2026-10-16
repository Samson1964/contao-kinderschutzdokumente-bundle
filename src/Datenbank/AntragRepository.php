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
 * Liest und schreibt die Anträge auf Freischaltung (tl_kinderschutz_antrag).
 *
 * Stände: „offen“ (aus dem Frontend), „freigeschaltet“, „abgelehnt“; ein
 * leerer Stand gehört zu einem Datensatz, den „Mitglied freischalten“
 * gerade angelegt hat und der noch nicht gespeichert ist.
 */
class AntragRepository
{
	public const OFFEN = 'offen';

	public const FREIGESCHALTET = 'freigeschaltet';

	public const ABGELEHNT = 'abgelehnt';

	/**
	 * Merkt sich die Datenbankverbindung für alle Abfragen auf
	 * tl_kinderschutz_antrag; eine Verbindung wird erst bei der ersten
	 * Abfrage aufgebaut.
	 *
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Legt einen offenen Antrag an.
	 *
	 * Die Regel „höchstens ein offener Antrag je Mitglied“ prüft der Dienst
	 * (offenerFuer()), nicht dieses Repository: Es legt jeden Antrag an, den
	 * man ihm gibt.
	 *
	 * Seiteneffekt: schreibt eine neue Zeile in tl_kinderschutz_antrag (Stand
	 * „offen“, Antrags- und Änderungszeit gleich $jetzt).
	 *
	 * @param int       $mitglied        ID des Mitglieds
	 * @param string    $wofuer          Wofür das Mitglied die Freischaltung braucht,
	 *                                   bereits bereinigt; die Begründung einer
	 *                                   Ablehnung heißt dagegen „grund“
	 * @param list<int> $veranstaltungen IDs der angekreuzten Veranstaltungen;
	 *                                   werden als serialisierte Liste von Zahlen
	 *                                   gespeichert
	 * @param int       $jetzt           Aktueller Zeitpunkt
	 *
	 * @return int Die ID des Antrags
	 */
	public function anlegen(int $mitglied, string $wofuer, array $veranstaltungen, int $jetzt): int
	{
		$this->db->insert('tl_kinderschutz_antrag', array(
			'tstamp'          => $jetzt,
			'member'          => $mitglied,
			'wofuer'          => $wofuer,
			'veranstaltungen' => serialize(array_values(array_map('intval', $veranstaltungen))),
			'status'          => self::OFFEN,
			'beantragtAm'     => $jetzt,
		));

		return (int) $this->db->lastInsertId();
	}

	/**
	 * Liefert einen Antrag.
	 *
	 * @param int $id ID des Antrags
	 *
	 * @return array<string, mixed>|null Die Zeile, oder null, wenn es sie nicht gibt
	 */
	public function zeile(int $id): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz_antrag WHERE id = ?', array($id));

		return false === $zeile ? null : $zeile;
	}

	/**
	 * Liefert den offenen Antrag eines Mitglieds.
	 *
	 * @param int $mitglied ID des Mitglieds
	 *
	 * @return array<string, mixed>|null Der jüngste offene Antrag, oder null
	 */
	public function offenerFuer(int $mitglied): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz_antrag WHERE member = ? AND status = ? ORDER BY id DESC', array($mitglied, self::OFFEN));

		return false === $zeile ? null : $zeile;
	}

	/**
	 * Liefert den letzten Antrag eines Mitglieds, gleich welchen Stands.
	 *
	 * Ungespeicherte Entwürfe aus „Mitglied freischalten“ (leerer Stand)
	 * zählen nicht.
	 *
	 * @param int $mitglied ID des Mitglieds
	 *
	 * @return array<string, mixed>|null Der jüngste Antrag, oder null
	 */
	public function letzterFuer(int $mitglied): ?array
	{
		$zeile = $this->db->fetchAssociative("SELECT * FROM tl_kinderschutz_antrag WHERE member = ? AND status != '' ORDER BY beantragtAm DESC, id DESC", array($mitglied));

		return false === $zeile ? null : $zeile;
	}

	/**
	 * Zählt die offenen Anträge.
	 *
	 * @return int Anzahl
	 */
	public function offeneAnzahl(): int
	{
		return (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz_antrag WHERE status = ?', array(self::OFFEN));
	}

	/**
	 * Ändert Spalten eines Antrags.
	 *
	 * Die Spaltennamen in $werte gehen ungeprüft in die SQL-Anweisung; sie
	 * dürfen nie aus Benutzereingaben stammen. Ein leeres $werte ergibt eine
	 * ungültige Anweisung und wirft eine DBAL-Ausnahme (Syntaxfehler). Eine
	 * unbekannte ID ändert nichts und meldet keinen Fehler.
	 *
	 * Seiteneffekt: schreibt tl_kinderschutz_antrag. „tstamp“ wird nicht
	 * selbst nachgeführt, wer es braucht, gibt es in $werte mit.
	 *
	 * @param int                  $id    ID des Antrags
	 * @param array<string, mixed> $werte Spalten und neue Werte, nicht leer
	 */
	public function aendern(int $id, array $werte): void
	{
		$this->db->update('tl_kinderschutz_antrag', $werte, array('id' => $id));
	}

	/**
	 * Liefert die übrigen offenen Anträge eines Mitglieds.
	 *
	 * Gebraucht beim Freischalten: Die Anträge, die schliesseOffene() gleich
	 * mit schließt, bringen angekreuzte Veranstaltungen mit, die vorher
	 * übernommen werden müssen. Gelesen wird vor dem Schließen, danach wären
	 * sie nicht mehr „offen“.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $ausser   ID des Antrags, der gerade entschieden wird
	 *
	 * @return list<array<string, mixed>> Die offenen Anträge des Mitglieds ohne
	 *                                    $ausser, der älteste zuerst; leer,
	 *                                    wenn es keine weiteren gibt. Entschiedene
	 *                                    Anträge und ungespeicherte Entwürfe
	 *                                    (leerer Stand) fehlen.
	 */
	public function weitereOffene(int $mitglied, int $ausser): array
	{
		return $this->db->fetchAllAssociative(
			'SELECT * FROM tl_kinderschutz_antrag WHERE member = ? AND status = ? AND id != ? ORDER BY id',
			array($mitglied, self::OFFEN, $ausser)
		);
	}

	/**
	 * Schließt die übrigen offenen Anträge eines frisch freigeschalteten Mitglieds.
	 *
	 * Schaltet der Prüfer direkt frei, während noch ein Antrag offen ist,
	 * bliebe dieser sonst als „offen“ in der Liste stehen. Abgelehnte und schon
	 * freigeschaltete Anträge sowie ungespeicherte Entwürfe (leerer Stand)
	 * bleiben unverändert.
	 *
	 * Seiteneffekt: schreibt tl_kinderschutz_antrag (Stand, Entscheidungszeit,
	 * Prüfer und Änderungszeit der betroffenen Zeilen); Begründung und
	 * Veranstaltungen bleiben stehen.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $ausser   ID des Antrags, der gerade entschieden wurde
	 * @param int $pruefer  ID des Prüfers in tl_user
	 * @param int $jetzt    Aktueller Zeitpunkt
	 */
	public function schliesseOffene(int $mitglied, int $ausser, int $pruefer, int $jetzt): void
	{
		$this->db->executeStatement(
			'UPDATE tl_kinderschutz_antrag SET status = ?, entschiedenAm = ?, entschiedenVon = ?, tstamp = ? WHERE member = ? AND status = ? AND id != ?',
			array(self::FREIGESCHALTET, $jetzt, $pruefer, $jetzt, $mitglied, self::OFFEN, $ausser)
		);
	}

	/**
	 * Entpackt die gespeicherte Liste der Veranstaltungen.
	 *
	 * @param mixed $wert Inhalt der Spalte „veranstaltungen“
	 *
	 * @return list<int> IDs, leer bei leerem oder unlesbarem Wert
	 */
	public static function veranstaltungenAus(mixed $wert): array
	{
		$liste = is_string($wert) && '' !== $wert ? @unserialize($wert, array('allowed_classes' => false)) : array();

		return is_array($liste) ? array_values(array_map('intval', $liste)) : array();
	}
}
