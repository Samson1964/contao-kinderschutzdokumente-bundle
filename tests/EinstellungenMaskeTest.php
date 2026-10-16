<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

use Contao\DC_File;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;

/**
 * Prüft die Einstellungsmaske im Kinderschutzbereich und die Menüpunkte.
 */
class EinstellungenMaskeTest extends TestCase
{
	/**
	 * Lädt die DCA.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA'] = array();
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../src/Resources/contao/dca/tl_kinderschutz_einstellungen.php';
	}

	/**
	 * Räumt die Globalen weg.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['BE_MOD']);
	}

	/**
	 * Die Maske führt alle Einstellungen und je Mailart Betreff und Text.
	 */
	public function testMaskeFuehrtAlleEinstellungenUndMails(): void
	{
		$dca = $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen'];
		$palette = $dca['palettes']['default'];

		$this->assertSame(DC_File::class, $dca['config']['dataContainer']);

		foreach (array('kinderschutzGruppe', 'kinderschutzSeite', 'kinderschutzAbsender', 'kinderschutzPapierkorbTage', 'kinderschutzVorlaufAblauf', 'kinderschutzVorlaufVeranstaltung') as $feld) {
			$this->assertMatchesRegularExpression('/[,}]'.$feld.'(,|;|$)/', $palette);
			$this->assertFalse($dca['fields'][$feld]['exclude']);
		}

		foreach (Einstellungen::MAILARTEN as $art) {
			$feld = 'kinderschutzMail'.ucfirst($art);
			$this->assertStringContainsString('{mail'.ucfirst($art).'_legend:hide},'.$feld.'Betreff,'.$feld.'Text', $palette);
			$this->assertSame('textarea', $dca['fields'][$feld.'Text']['inputType']);
			$this->assertFalse($dca['fields'][$feld.'Text']['exclude']);
		}

		$this->assertArrayNotHasKey('kinderschutzGruppen', $dca['fields']);
	}

	/**
	 * Mailtexte überstehen die localconfig über die Callbacks der Felder.
	 */
	public function testMailtexteUeberstehenDieKonfigurationsdatei(): void
	{
		$feld = $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields']['kinderschutzMailAntragText'];
		$gespeichert = $feld['save_callback'][0]("Zeile 1\r\n\r\nZeile 2");

		$this->assertStringNotContainsString("\n", $gespeichert);
		$this->assertSame("Zeile 1\n\nZeile 2", $feld['load_callback'][0]($gespeichert));
		$this->assertSame('', $feld['save_callback'][0]("  \n"));
	}

	/**
	 * Der load_callback liest den Mailtext auch, wenn Contao 4.13 ihn maskiert hat.
	 *
	 * DC_File::edit() wendet in 4.13 htmlspecialchars() an, bevor der
	 * load_callback läuft; in 5.7 geschieht das nicht.
	 */
	public function testLadenMitMaskiertemWert(): void
	{
		$feld = $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields']['kinderschutzMailAntragText'];
		$text = "Hallo \"{name}\",\n\nA & B <b> \ äöü";
		$gespeichert = $feld['save_callback'][0]($text);
		$maskiert = htmlspecialchars($gespeichert, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);

		$this->assertSame($text, $feld['load_callback'][0]($gespeichert));
		$this->assertSame($text, $feld['load_callback'][0]($maskiert));
		// Rundreise ohne Änderung verpackt nicht doppelt
		$this->assertSame($gespeichert, $feld['save_callback'][0]($feld['load_callback'][0]($maskiert)));
	}

	/**
	 * Unter System → Einstellungen steht nichts mehr vom Bundle.
	 */
	public function testNichtMehrUnterSystemEinstellungen(): void
	{
		$this->assertFileDoesNotExist(__DIR__.'/../src/Resources/contao/dca/tl_settings.php');
		$this->assertFileDoesNotExist(__DIR__.'/../src/Resources/contao/languages/de/tl_settings.php');
	}

	/**
	 * Reihenfolge und Rechte der Menüpunkte im Kinderschutzbereich.
	 */
	public function testMenuepunkte(): void
	{
		$GLOBALS['BE_MOD'] = array();
		include __DIR__.'/../src/Resources/contao/config/config.php';

		$this->assertSame(
			array('kinderschutz_uebersicht', 'kinderschutz_nachweise', 'kinderschutz_papierkorb', 'kinderschutz_antraege', 'kinderschutz_veranstaltungen', 'kinderschutz_dokumentarten', 'kinderschutz_einstellungen'),
			array_keys($GLOBALS['BE_MOD']['kinderschutz'])
		);

		foreach ($GLOBALS['BE_MOD']['kinderschutz'] as $modul) {
			$this->assertTrue($modul['disablePermissionChecks']);
		}

		$this->assertSame(array('tl_kinderschutz_einstellungen'), $GLOBALS['BE_MOD']['kinderschutz']['kinderschutz_einstellungen']['tables']);
	}
}
