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
 * Eine Dokumentart (Zeile aus tl_kinderschutz_dokumentart) als Wertobjekt.
 */
final class Dokumentart
{
	/**
	 * @param int    $id                     ID in tl_kinderschutz_dokumentart
	 * @param string $name                   Anzeigename, etwa „Ehrenkodex“
	 * @param string $hinweis                Hinweistext für das Frontend
	 * @param bool   $pflicht                Gehört zur Vollständigkeit
	 * @param int    $gueltigkeit            Gültigkeit in Monaten, 0 = unbegrenzt
	 * @param bool   $papierkorbNachPruefung Datei nach der Prüfung in den Papierkorb
	 * @param bool   $einschlaegigAbfragen   Prüfmaske fragt einschlägige Einträge ab
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $hinweis,
		public readonly bool $pflicht,
		public readonly int $gueltigkeit,
		public readonly bool $papierkorbNachPruefung,
		public readonly bool $einschlaegigAbfragen,
	) {
	}

	/**
	 * Baut eine Dokumentart aus einer Datenbankzeile.
	 *
	 * @param array<string, mixed> $zeile Zeile aus tl_kinderschutz_dokumentart
	 *
	 * @return self Die Dokumentart
	 */
	public static function ausZeile(array $zeile): self
	{
		return new self(
			(int) $zeile['id'],
			(string) $zeile['name'],
			(string) ($zeile['hinweis'] ?? ''),
			'1' === (string) ($zeile['pflicht'] ?? ''),
			(int) ($zeile['gueltigkeit'] ?? 0),
			'1' === (string) ($zeile['papierkorbNachPruefung'] ?? ''),
			'1' === (string) ($zeile['einschlaegigAbfragen'] ?? ''),
		);
	}
}
