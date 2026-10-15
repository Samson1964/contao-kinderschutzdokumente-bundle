<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Ablage;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;

/**
 * Prüft das Löschen in der Dateiablage an echten Dateien.
 */
class DateiablageTest extends TestCase
{
	/** Wurzel der Ablage für einen Test */
	private string $wurzel;

	/**
	 * Legt eine leere Ablage im temporären Verzeichnis an.
	 */
	protected function setUp(): void
	{
		$this->wurzel = sys_get_temp_dir().'/kinderschutz-ablage-'.bin2hex(random_bytes(6));
		mkdir($this->wurzel.'/7', 0777, true);
	}

	/**
	 * Hebt jeden Schreibschutz auf und räumt die Ablage wieder ab.
	 */
	protected function tearDown(): void
	{
		@chmod($this->wurzel.'/7', 0777);

		foreach (glob($this->wurzel.'/7/*') ?: array() as $datei) {
			@chmod($datei, 0666);
			@unlink($datei);
		}

		@rmdir($this->wurzel.'/7');
		@rmdir($this->wurzel);
	}

	/**
	 * Eine Datei, die sich nicht löschen lässt, ergibt nur die eigene
	 * RuntimeException, ohne PHP-Warnung.
	 *
	 * Im Prüfstand 5.7 schrieb unlink() bei einer schreibgeschützten Datei
	 * eine Warnung samt absolutem Pfad ins Protokoll („php.ERROR“). Im
	 * Debug-Modus wandelt Symfony diese Warnung in eine ErrorException um –
	 * die ist keine RuntimeException, ginge an den catch-Blöcken von
	 * Papierkorb und Papierkorb-Aktionen vorbei und endete auf einer
	 * Fehlerseite.
	 *
	 * Schreibschutz: unter Windows reicht die Datei, unter Linux braucht es
	 * den Ordner. Wer beides ignorieren darf (root), überspringt den Test.
	 */
	public function testNichtLoeschbareDateiWirftOhneWarnung(): void
	{
		$relativ = '7/'.str_repeat('a', 32).'.pdf';
		$probe = $this->wurzel.'/7/probe.txt';
		file_put_contents($this->wurzel.'/'.$relativ, '%PDF-1.4');
		file_put_contents($probe, 'x');
		chmod($this->wurzel.'/'.$relativ, 0444);
		chmod($probe, 0444);
		chmod($this->wurzel.'/7', 0555);

		if (@unlink($probe)) {
			$this->markTestSkipped('Der Schreibschutz wirkt hier nicht (etwa als root).');
		}

		// Wie Symfonys ErrorHandler: Mit @ unterdrückte Meldungen zählen nicht
		$warnungen = array();
		set_error_handler(static function (int $nummer, string $text) use (&$warnungen): bool {
			if (0 !== (error_reporting() & $nummer)) {
				$warnungen[] = $text;
			}

			return true;
		});

		try {
			(new Dateiablage($this->wurzel))->loesche($relativ);
			$this->fail('loesche() hätte eine RuntimeException werfen müssen.');
		} catch (\RuntimeException $fehler) {
			$this->assertSame(\RuntimeException::class, $fehler::class);
			$this->assertStringNotContainsString($this->wurzel, $fehler->getMessage(), 'Die Meldung nennt keinen absoluten Pfad.');
		} finally {
			restore_error_handler();
		}

		$this->assertSame(array(), $warnungen);
		$this->assertFileExists($this->wurzel.'/'.$relativ);
	}

	/**
	 * Eine löschbare Datei verschwindet, eine schon fehlende gilt als gelöscht.
	 */
	public function testLoeschenUndFehlendeDatei(): void
	{
		$relativ = '7/'.str_repeat('b', 32).'.pdf';
		file_put_contents($this->wurzel.'/'.$relativ, '%PDF-1.4');
		$ablage = new Dateiablage($this->wurzel);

		$ablage->loesche($relativ);
		$this->assertFileDoesNotExist($this->wurzel.'/'.$relativ);

		$ablage->loesche($relativ);
		$this->assertFileDoesNotExist($this->wurzel.'/'.$relativ);
	}
}
