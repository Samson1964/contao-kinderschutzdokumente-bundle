<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln;

/**
 * Ein Nachweis (Zeile aus tl_kinderschutz) als unveränderliches Wertobjekt.
 *
 * Enthält nur, was die Rechenregeln brauchen. Datei, Bemerkung und
 * Papierkorbstand bleiben in der Datenbankzeile, weil sie für Status und
 * Erinnerungen keine Rolle spielen.
 */
final class Nachweis
{
	/** Hochgeladen, wartet auf Prüfung */
	public const EINGEREICHT = 'eingereicht';

	/** Vom Prüfer anerkannt; gueltigBis ist gesetzt */
	public const GEPRUEFT = 'geprueft';

	/** Vom Prüfer abgelehnt; ablehnungsgrund ist gesetzt */
	public const ABGELEHNT = 'abgelehnt';

	/** Vor der Prüfung durch eine neuere Einreichung ersetzt */
	public const ERSETZT = 'ersetzt';

	/** Alle gespeicherten Status in der Reihenfolge des Arbeitsablaufs */
	public const ALLE = array(self::EINGEREICHT, self::GEPRUEFT, self::ABGELEHNT, self::ERSETZT);

	/**
	 * @param int      $id                ID in tl_kinderschutz
	 * @param int      $member            ID des Mitglieds
	 * @param int      $dokumentart       ID der Dokumentart
	 * @param string   $status            Einer der Status aus ALLE
	 * @param int      $ausstellungsdatum Zeitstempel des Ausstellungstags, 0 = unbekannt
	 * @param int      $hochgeladenAm     Zeitpunkt des Uploads, 0 ohne Datei
	 * @param int      $geprueftAm        Zeitpunkt der Entscheidung, 0 = offen
	 * @param string   $einschlaegig      '', 'nein' oder 'ja'
	 * @param int|null $gueltigBis        Letzter gültiger Tag, null = unbegrenzt
	 *                                    oder (bei ungeprüften) ohne Bedeutung
	 * @param string   $ablehnungsgrund   Begründung einer Ablehnung
	 * @param int      $erinnertVorAblauf Versandzeit der Vorab-Erinnerung, 0 = keine
	 * @param int      $erinnertAblauf    Versandzeit der Ablaufmail, 0 = keine
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $member,
		public readonly int $dokumentart,
		public readonly string $status,
		public readonly int $ausstellungsdatum,
		public readonly int $hochgeladenAm,
		public readonly int $geprueftAm,
		public readonly string $einschlaegig,
		public readonly ?int $gueltigBis,
		public readonly string $ablehnungsgrund = '',
		public readonly int $erinnertVorAblauf = 0,
		public readonly int $erinnertAblauf = 0,
	) {
	}

	/**
	 * Baut einen Nachweis aus einer Datenbankzeile.
	 *
	 * MySQL liefert Zahlen als Zeichenketten; die Umwandlung geschieht hier
	 * einmal, damit die Regeln nur mit echten Typen arbeiten.
	 *
	 * @param array<string, mixed> $zeile Zeile aus tl_kinderschutz
	 *
	 * @return self Der Nachweis
	 */
	public static function ausZeile(array $zeile): self
	{
		return new self(
			(int) $zeile['id'],
			(int) $zeile['member'],
			(int) $zeile['dokumentart'],
			(string) $zeile['status'],
			(int) ($zeile['ausstellungsdatum'] ?? 0),
			(int) ($zeile['hochgeladenAm'] ?? 0),
			(int) ($zeile['geprueftAm'] ?? 0),
			(string) ($zeile['einschlaegig'] ?? ''),
			null === ($zeile['gueltigBis'] ?? null) ? null : (int) $zeile['gueltigBis'],
			(string) ($zeile['ablehnungsgrund'] ?? ''),
			(int) ($zeile['erinnertVorAblauf'] ?? 0),
			(int) ($zeile['erinnertAblauf'] ?? 0),
		);
	}
}
