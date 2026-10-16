<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;

/**
 * Sichert die datenschutz- und rechterelevanten Schalter der DCA-Dateien ab.
 *
 * Der DatenbankSchemaTest prüft nur, dass Spaltennamen übereinstimmen. Die
 * Schalter hier bestimmen dagegen, ob Prüfvermerke in fremde Indizes und
 * Versionskopien wandern, wer die Module bedienen darf und ob leere Datumsfelder
 * als 01.01.1970 erscheinen. Ein Rückfall würde dort keinen Test röten.
 */
class DcaVertragTest extends TestCase
{
	/**
	 * Kein Feld des Nachweises ist durchsuchbar.
	 *
	 * Die Backend-Suche von Contao 5 übernimmt Felder mit „search => true“ in
	 * ihren Index. Damit gelangten interne Bemerkungen und Prüfvermerke zum
	 * Führungszeugnis in einen Suchbestand, den das Bundle nicht kontrolliert.
	 */
	public function testNachweisFelderSindNichtDurchsuchbar(): void
	{
		$durchsuchbar = array();

		foreach ($this->ladeDca('tl_kinderschutz')['fields'] as $name => $feld) {
			if (!empty($feld['search'])) {
				$durchsuchbar[] = $name;
			}
		}

		$this->assertSame(array(), $durchsuchbar, 'Diese Felder von tl_kinderschutz tragen „search“: '.implode(', ', $durchsuchbar));
	}

	/**
	 * Die Nachweise werden nicht versioniert.
	 *
	 * Jede Version wäre eine weitere Kopie in tl_version, auch von Prüfvermerken
	 * wie „einschlägige Einträge“. Diese Kopien lägen außerhalb der Tabelle,
	 * für die Papierkorb und Löschfristen des Bundles gelten.
	 */
	public function testNachweiseHabenKeineVersionierung(): void
	{
		$config = $this->ladeDca('tl_kinderschutz')['config'];

		$this->assertFalse((bool) ($config['enableVersioning'] ?? false), 'tl_kinderschutz darf nicht versioniert werden.');
	}

	/**
	 * Nachweise lassen sich nicht kopieren.
	 *
	 * Eine Kopie übernähme die Spalte „datei“: Zwei Datensätze zeigten auf
	 * dieselbe Datei, und wer sie über einen davon endgültig löscht, nimmt
	 * sie dem anderen mit. Im Prüfstand 5.7 bot die Prüfmaske dafür
	 * „Speichern und duplizieren“ an, die Kopie lag sofort im Papierkorb.
	 */
	public function testNachweiseSindNichtKopierbar(): void
	{
		$config = $this->ladeDca('tl_kinderschutz')['config'];

		$this->assertTrue($config['notCopyable'] ?? false, 'tl_kinderschutz muss notCopyable tragen.');
	}

	/**
	 * Anträge lassen sich nicht kopieren; eine Kopie trüge die Entscheidung mit.
	 */
	public function testAntraegeSindNichtKopierbar(): void
	{
		$this->assertTrue($this->ladeDca('tl_kinderschutz_antrag')['config']['notCopyable'] ?? false);
	}

	/**
	 * Die Begründung eines Antrags wird entkodiert gespeichert.
	 *
	 * Sie geht wörtlich in eine Textmail; ohne decodeEntities stünden dort
	 * Entitäten wie „&amp;“ (den Rest entkodiert Benachrichtigung::sende()).
	 */
	public function testAntragsbegruendungWirdEntkodiert(): void
	{
		$this->assertTrue($this->ladeDca('tl_kinderschutz_antrag')['fields']['grund']['eval']['decodeEntities'] ?? false);
	}

	/**
	 * Die Antragsliste hat ausdrücklich keine globalen Operationen.
	 *
	 * Contao 5.7 ergänzt für jede DC_Table-Tabelle „Mehrere bearbeiten“, solange
	 * sie bearbeitbar oder löschbar ist (DefaultGlobalOperationsListener). Bei
	 * Anträgen ist das nicht gewollt: Jede Entscheidung läuft über die
	 * Einzelmaske und zieht Gruppe, Teilnahmen und Mails nach. Der Listener lässt
	 * eine leere Liste unangetastet (`[] === $dca`); fehlte der Schlüssel,
	 * erschiene der Knopf. „Neu“ (hier „Mitglied freischalten“) erzeugt
	 * DC_Table getrennt davon.
	 */
	public function testAntraegeHabenKeineGlobalenOperationen(): void
	{
		$liste = $this->ladeDca('tl_kinderschutz_antrag')['list'];

		$this->assertArrayHasKey('global_operations', $liste);
		$this->assertSame(array(), $liste['global_operations']);
	}

	/**
	 * Die Antragsliste blendet nicht gespeicherte Entwürfe aus.
	 *
	 * „Mitglied freischalten“ legt über DC_Table einen Datensatz mit
	 * tstamp 0 an. Contao 5.7 löscht ihn nur, wenn die Maske über „Zurück“
	 * verlassen wird (Parameter „revise“); wer über das Menü weggeht, lässt
	 * ihn stehen. Im Prüfstand 5.7 erschien er dann in der Liste unter „-“
	 * als „Mitglied gelöscht (#0) (–)“. Contao 4.13 räumt solche Datensätze
	 * beim nächsten Listenaufruf selbst ab; der Filter schadet dort nicht.
	 */
	public function testAntragslisteBlendetEntwuerfeAus(): void
	{
		$sortierung = $this->ladeDca('tl_kinderschutz_antrag')['list']['sorting'];

		$this->assertContains(array('tstamp>?', 0), $sortierung['filter'] ?? array());
	}

	/**
	 * Die Datensätze der Module lassen sich nicht verschieben.
	 *
	 * Die Tabellen haben weder „pid“ noch „sorting“. Eine nachgebaute Adresse
	 * mit „act=cut“ endete im Prüfstand 5.7 deshalb mit HTTP 500 („Set array
	 * must not be empty for UPDATE queries“, ohne pid „Cannot load record“).
	 * Mit „notSortable“ weist DC_Table::cut() sie in 4.13 und 5.7 mit einer
	 * AccessDeniedException (403) ab.
	 *
	 * @dataProvider modultabellen
	 *
	 * @param string $tabelle Name der Tabelle, etwa „tl_kinderschutz“
	 */
	public function testModultabellenSindNichtVerschiebbar(string $tabelle): void
	{
		$this->assertTrue($this->ladeDca($tabelle)['config']['notSortable'] ?? false, $tabelle.' muss notSortable tragen.');
	}

	/**
	 * Die Tabellen, die ein Backend-Modul als Liste zeigt.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function modultabellen(): array
	{
		return array(
			'Nachweise'       => array('tl_kinderschutz'),
			'Dokumentarten'   => array('tl_kinderschutz_dokumentart'),
			'Veranstaltungen' => array('tl_kinderschutz_veranstaltung'),
			'Anträge'         => array('tl_kinderschutz_antrag'),
		);
	}

	/**
	 * Die eigenen Tabellen haben leere Contao-Berechtigungen.
	 *
	 * Die Kinderschutz-Module schalten Contaos Modul- und Tabellenrechte ab
	 * und prüfen selbst, wer Hauptadmin oder freigegebener Benutzer ist. Fehlt
	 * „permissions“ oder ist es befüllt, greifen zusätzlich die Kernrechte, und
	 * der Zugriff hängt dann an Einstellungen, die niemand mit dem Bundle
	 * abgestimmt hat.
	 *
	 * @dataProvider tabellen
	 *
	 * @param string $tabelle Name der Tabelle, etwa „tl_kinderschutz“
	 */
	public function testBerechtigungenSindLeer(string $tabelle): void
	{
		$config = $this->ladeDca($tabelle)['config'];

		$this->assertArrayHasKey('permissions', $config, $tabelle.' braucht „permissions“.');
		$this->assertSame(array(), $config['permissions'], $tabelle.': „permissions“ muss leer sein.');
	}

	/**
	 * Jedes bearbeitbare Feld ist ausdrücklich „exclude => false“.
	 *
	 * Contao behandelt ein Feld mit inputType ohne diese Angabe als
	 * ausgeschlossen (DataContainer::isFieldExcluded) und lässt es nur Benutzer
	 * bearbeiten, denen es in der Benutzergruppe einzeln freigegeben wurde. Die
	 * Kinderschutz-Module schalten diese Rechte bewusst ab; die Prüfer sähen
	 * sonst leere Masken. Der Wert muss strikt false sein, ein fehlender
	 * Schlüssel zählt nicht.
	 *
	 * @dataProvider tabellen
	 *
	 * @param string $tabelle Name der Tabelle, etwa „tl_kinderschutz“
	 */
	public function testBearbeitbareFelderSindNichtAusgeschlossen(string $tabelle): void
	{
		$abweichend = array();

		foreach ($this->ladeDca($tabelle)['fields'] as $name => $feld) {
			if (isset($feld['inputType']) && ($feld['exclude'] ?? null) !== false) {
				$abweichend[] = $name;
			}
		}

		$this->assertSame(array(), $abweichend, 'Diese Felder von '.$tabelle.' tragen kein „exclude => false“: '.implode(', ', $abweichend));
	}

	/**
	 * Die Vorschau in der Prüfmaske ist „exclude => false“.
	 *
	 * Das Feld „vorschau“ hat keinen inputType, weil es nur den Kopf der
	 * Prüfmaske anzeigt. Deshalb erfasst die Prüfung auf bearbeitbare Felder
	 * es nicht. Fiele die Angabe weg oder würde sie true, könnte die Maske den
	 * Kopf mit Datei und Stand für Prüfer ohne Feldfreigabe ausblenden.
	 */
	public function testVorschauIstNichtAusgeschlossen(): void
	{
		$felder = $this->ladeDca('tl_kinderschutz')['fields'];

		$this->assertArrayHasKey('vorschau', $felder);
		$this->assertArrayHasKey('exclude', $felder['vorschau']);
		$this->assertFalse($felder['vorschau']['exclude']);
	}

	/**
	 * Die Datumsspalten sind nullbar.
	 *
	 * Ein leeres Datum (kein Ende der Veranstaltung, noch kein Ablauftag) muss
	 * als NULL gespeichert werden können. Eine Spalte mit „NOT NULL default 0“
	 * zeigte es als 01.01.1970, und die Gültigkeits- und Erinnerungsregeln
	 * hielten die Null für einen echten Zeitpunkt.
	 *
	 * @dataProvider datumsspalten
	 *
	 * @param string $tabelle Name der Tabelle
	 * @param string $feld    Name des Datumsfeldes
	 */
	public function testDatumsspaltenSindNullbar(string $tabelle, string $feld): void
	{
		$felder = $this->ladeDca($tabelle)['fields'];

		$this->assertArrayHasKey($feld, $felder);
		$this->assertArrayHasKey('sql', $felder[$feld]);
		$this->assertMatchesRegularExpression('/\bNULL\b/i', $felder[$feld]['sql']);
		$this->assertDoesNotMatchRegularExpression('/\bNOT\s+NULL\b/i', $felder[$feld]['sql'], $tabelle.'.'.$feld.' darf nicht „NOT NULL“ sein.');
	}

	/**
	 * Listen, die nach einem Zeitstempel sortieren, gruppieren nach Tag.
	 *
	 * Über jeder Gruppe gibt Contao den Gruppenwert aus. Contao 5.7
	 * formatiert ihn für „tstamp“ und Felder mit rgxp „date“/„datim“ selbst
	 * (ValueFormatter); Contao 4.13 nur bei den Tag-, Monats- und
	 * Jahresflags (DC_Table::formatCurrentValue()) und zeigt sonst die rohe
	 * Zahl. Im Prüfstand 4.13 standen so „1792125474“ über den Nachweisen und
	 * „1794697200“ über den Veranstaltungen.
	 *
	 * @dataProvider zeitlisten
	 *
	 * @param string $tabelle Name der Tabelle
	 * @param string $feld    Das Sortierfeld mit Zeitstempel
	 */
	public function testZeitlistenGruppierenNachTag(string $tabelle, string $feld): void
	{
		$sortierung = $this->ladeDca($tabelle)['list']['sorting'];

		$this->assertSame(array($feld), $sortierung['fields']);
		$this->assertContains($sortierung['flag'] ?? null, array(DataContainer::SORT_DAY_ASC, DataContainer::SORT_DAY_DESC), $tabelle.': Die Liste nach „'.$feld.'“ braucht ein Tagesflag, sonst zeigt 4.13 Zeitstempel als Gruppenköpfe.');
	}

	/**
	 * Die Listen, die nach einem Zeitstempel sortieren.
	 *
	 * Die Papierkorb-Ansicht stellt der NachweisListener zur Laufzeit ein;
	 * sie prüft der NachweisListenerTest.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function zeitlisten(): array
	{
		return array(
			'Nachweise'       => array('tl_kinderschutz', 'tstamp'),
			'Veranstaltungen' => array('tl_kinderschutz_veranstaltung', 'startDate'),
		);
	}

	/**
	 * Die vier eigenen Tabellen des Bundles.
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
			'Anträge'         => array('tl_kinderschutz_antrag'),
		);
	}

	/**
	 * Die Datumsspalten, die leer bleiben dürfen.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function datumsspalten(): array
	{
		return array(
			'Ausstellungsdatum' => array('tl_kinderschutz', 'ausstellungsdatum'),
			'Gültig bis'        => array('tl_kinderschutz', 'gueltigBis'),
			'Beginn'            => array('tl_kinderschutz_veranstaltung', 'startDate'),
			'Ende'              => array('tl_kinderschutz_veranstaltung', 'endDate'),
		);
	}

	/**
	 * Liest die DCA-Definition einer Tabelle aus ihrer Datei.
	 *
	 * Die DCA-Dateien schreiben in $GLOBALS['TL_DCA'] und legen über
	 * Verweise Einträge in $GLOBALS['TL_LANG'] an. Beide Felder werden deshalb
	 * vorher gesichert, geleert und nachher wiederhergestellt, auch wenn das
	 * Einbinden mit einer Ausnahme endet. So beeinflusst der Test weder andere
	 * Tests noch wird er von ihnen beeinflusst.
	 *
	 * @param string $tabelle Name der Tabelle, etwa „tl_kinderschutz“
	 *
	 * @return array<string, mixed> Die DCA-Definition mit config, fields usw.
	 */
	private function ladeDca(string $tabelle): array
	{
		$hatDca  = array_key_exists('TL_DCA', $GLOBALS);
		$dca     = $GLOBALS['TL_DCA'] ?? null;
		$hatLang = array_key_exists('TL_LANG', $GLOBALS);
		$lang    = $GLOBALS['TL_LANG'] ?? null;

		try {
			$GLOBALS['TL_DCA']  = array();
			$GLOBALS['TL_LANG'] = array();
			include __DIR__.'/../src/Resources/contao/dca/'.$tabelle.'.php';

			return $GLOBALS['TL_DCA'][$tabelle];
		} finally {
			if ($hatDca) {
				$GLOBALS['TL_DCA'] = $dca;
			} else {
				unset($GLOBALS['TL_DCA']);
			}

			if ($hatLang) {
				$GLOBALS['TL_LANG'] = $lang;
			} else {
				unset($GLOBALS['TL_LANG']);
			}
		}
	}
}
