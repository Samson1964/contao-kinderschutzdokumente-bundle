<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Hält die SQLite-Testdatenbank und die DCA-Dateien im Gleichschritt.
 *
 * Fehlt eine Spalte in der Testdatenbank, würden Tests an einer Stelle
 * vorbeiprüfen, die in MySQL anders aussieht; fehlt sie in der DCA, legt
 * Contao sie gar nicht erst an.
 */
class DatenbankSchemaTest extends TestCase
{
	/**
	 * Jede Tabelle hat in DCA und Testdatenbank dieselben Spalten.
	 *
	 * @dataProvider tabellen
	 *
	 * @param string $tabelle Name der Tabelle, etwa „tl_kinderschutz“
	 */
	public function testSpaltenStimmenUeberein(string $tabelle): void
	{
		$this->assertSame($this->dcaSpalten($tabelle), $this->sqliteSpalten($tabelle));
	}

	/**
	 * Die Felder, die das Bundle in tl_user ergänzt, gibt es auch in SQLite.
	 */
	public function testBenutzerfeld(): void
	{
		$GLOBALS['TL_DCA'] = array('tl_user' => array('palettes' => array(), 'fields' => array()));
		include __DIR__.'/../src/Resources/contao/dca/tl_user.php';
		$felder = array_keys($GLOBALS['TL_DCA']['tl_user']['fields']);
		unset($GLOBALS['TL_DCA']);

		$this->assertSame(array(), array_diff($felder, $this->sqliteSpalten('tl_user')));
	}

	/**
	 * Die eigenen Tabellen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function tabellen(): array
	{
		return array(
			'Nachweise'       => array('tl_kinderschutz'),
			'Dokumentarten'   => array('tl_kinderschutz_dokumentart'),
			'Veranstaltungen' => array('tl_kinderschutz_veranstaltung'),
			'Teilnahmen'      => array('tl_kinderschutz_teilnahme'),
		);
	}

	/**
	 * Liest die Spalten (Felder mit „sql“) aus einer DCA-Datei.
	 *
	 * @param string $tabelle Name der Tabelle
	 *
	 * @return list<string> Sortierte Spaltennamen
	 */
	private function dcaSpalten(string $tabelle): array
	{
		$GLOBALS['TL_DCA'] = array();
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../src/Resources/contao/dca/'.$tabelle.'.php';

		$spalten = array_keys(array_filter(
			$GLOBALS['TL_DCA'][$tabelle]['fields'],
			static fn (array $feld): bool => isset($feld['sql'])
		));
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
		sort($spalten);

		return $spalten;
	}

	/**
	 * Liest die Spalten einer Tabelle aus der SQLite-Testdatenbank.
	 *
	 * @param string $tabelle Name der Tabelle
	 *
	 * @return list<string> Sortierte Spaltennamen
	 */
	private function sqliteSpalten(string $tabelle): array
	{
		$spalten = array_column(Datenbank::verbindung()->fetchAllAssociative('PRAGMA table_info('.$tabelle.')'), 'name');
		sort($spalten);

		return $spalten;
	}
}
