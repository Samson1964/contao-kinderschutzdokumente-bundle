<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

/**
 * Erzeugt kleine Dateien bekannten Typs im temporären Verzeichnis.
 *
 * Die Inhalte sind gerade so lang, dass finfo sie am Anfang erkennt. Jede
 * Datei wird am Ende des Testlaufs wieder gelöscht.
 */
final class Testdateien
{
	/** @var list<string> */
	private static array $angelegt = array();

	/**
	 * Legt eine Datei mit beliebigem Inhalt an.
	 *
	 * @param string $inhalt Inhalt der Datei
	 * @param string $endung Endung ohne Punkt; finfo ignoriert sie
	 *
	 * @return string Pfad der Datei
	 */
	public static function datei(string $inhalt, string $endung): string
	{
		$pfad = sys_get_temp_dir().'/kinderschutz-test-'.bin2hex(random_bytes(6)).'.'.$endung;
		file_put_contents($pfad, $inhalt);

		if (array() === self::$angelegt) {
			register_shutdown_function(static function (): void {
				foreach (self::$angelegt as $datei) {
					@unlink($datei);
				}
			});
		}

		self::$angelegt[] = $pfad;

		return $pfad;
	}

	/**
	 * Ein minimales PDF.
	 *
	 * @return string Pfad der Datei
	 */
	public static function pdf(): string
	{
		return self::datei("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", 'pdf');
	}

	/**
	 * Ein JPEG-Kopf (JFIF).
	 *
	 * @return string Pfad der Datei
	 */
	public static function jpeg(): string
	{
		return self::datei("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9", 'jpg');
	}

	/**
	 * Ein PNG mit einem Bildpunkt.
	 *
	 * @return string Pfad der Datei
	 */
	public static function png(): string
	{
		return self::datei((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'png');
	}
}
