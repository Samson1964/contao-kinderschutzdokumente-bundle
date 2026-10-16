<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\UndoListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EingabeAttrappe;

/**
 * Prüft, dass Kinderschutz-Kopien unter System → Wiederherstellen nur für
 * Hauptadmin und Prüfer sichtbar und aufrufbar sind.
 */
class UndoListenerTest extends TestCase
{
	use EingabeAttrappe;

	/**
	 * Die Testdatenbank mit vier Kopien.
	 */
	private Connection $db;

	/**
	 * IDs der Kopien in tl_undo, nach Herkunftstabelle.
	 *
	 * @var array<string, int>
	 */
	private array $kopien = array();

	/**
	 * Legt die DCA von tl_undo und je eine Kopie aus vier Tabellen an.
	 *
	 * „tlXkinderschutz“ prüft, dass der Unterstrich im Filter nicht als
	 * Platzhalter wirkt.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA']['tl_undo'] = array('list' => array('sorting' => array('mode' => 2)));

		$this->db = Datenbank::verbindung();

		foreach (array('tl_kinderschutz', 'tl_kinderschutz_antrag', 'tl_news', 'tlXkinderschutz') as $tabelle) {
			$this->db->insert('tl_undo', array('pid' => 1, 'tstamp' => 1, 'fromTable' => $tabelle, 'data' => serialize(array($tabelle => array(array('id' => 7))))));
			$this->kopien[$tabelle] = (int) $this->db->lastInsertId();
		}
	}

	/**
	 * Räumt die DCA wieder ab.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA']);
	}

	/**
	 * Ein Administrator ohne Freigabe bekommt den Listenfilter.
	 */
	public function testOhneFreigabeFilterGesetzt(): void
	{
		$this->listener(array(), $this->zugang(false))();

		$this->assertSame(array(array("fromTable NOT LIKE ? ESCAPE '!'", 'tl!_kinderschutz%')), $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter']);
	}

	/**
	 * Ein schon gesetzter Filter des Kerns (5.7: pid=?) bleibt stehen.
	 */
	public function testVorhandenerFilterBleibt(): void
	{
		$GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter'] = array(array('pid=?', 3));

		$this->listener(array(), $this->zugang(false))();

		$this->assertCount(2, $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter']);
		$this->assertSame(array('pid=?', 3), $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter'][0]);
	}

	/**
	 * Der Filter blendet genau die Kinderschutz-Kopien aus – auch in SQLite,
	 * wo LIKE ohne ESCAPE kein Fluchtzeichen kennt.
	 */
	public function testFilterBlendetNurKinderschutzAus(): void
	{
		$this->listener(array(), $this->zugang(false))();

		[$bedingung, $wert] = $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter'][0];
		$sichtbar = $this->db->fetchFirstColumn('SELECT fromTable FROM tl_undo WHERE '.$bedingung.' ORDER BY id', array($wert));

		$this->assertSame(array('tl_news', 'tlXkinderschutz'), $sichtbar);
	}

	/**
	 * Prüfer und Hauptadmin sehen alles wie bisher.
	 */
	public function testMitFreigabeKeinFilter(): void
	{
		$this->listener(array('act' => 'show', 'id' => (string) $this->kopien['tl_kinderschutz']), $this->zugang(true))();

		$this->assertArrayNotHasKey('filter', $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']);
	}

	/**
	 * Prüfer und Hauptadmin holen eine Kinderschutz-Kopie zurück wie bisher.
	 */
	public function testPrueferDarfWiederherstellen(): void
	{
		$this->listener(array('act' => 'undo', 'id' => (string) $this->kopien['tl_kinderschutz']), $this->zugang(true))();

		// Keine Ausnahme und kein Filter: der Kern entscheidet allein
		$this->assertArrayNotHasKey('filter', $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']);
	}

	/**
	 * Verschieben ist ohne Freigabe pauschal gesperrt, auch ohne ID.
	 *
	 * tl_undo ist nicht „notSortable“; über einen nachgebauten
	 * tl_select-POST und „act=cutAll&pid=N&mode=2“ ließe sich sonst der
	 * Besitzer (pid) eines verborgenen Schritts umschreiben.
	 *
	 * @dataProvider verschiebeaktionen
	 *
	 * @param array<string, string> $abfrage act und weitere Werte
	 */
	public function testVerschiebenGesperrt(array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener($abfrage, $this->zugang(false))();
	}

	/**
	 * Die gesperrten Verschiebeaktionen.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function verschiebeaktionen(): array
	{
		return array(
			'Mehrere ausschneiden'         => array(array('act' => 'cutAll', 'pid' => '3', 'mode' => '2')),
			'Ausschneiden fremder Schritt' => array(array('act' => 'cut', 'id' => '3', 'pid' => '1', 'mode' => '2')),
			'Einfügen'                     => array(array('act' => 'paste', 'mode' => 'copy')),
			'Gemischte Schreibweise'       => array(array('act' => 'CUTALL')),
		);
	}

	/**
	 * Ein „act“ mit anderen Zeichen als Buchstaben ist von Hand gebaut: 403.
	 */
	public function testActMitFremdzeichenGesperrt(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => 'cu&#116;All'), $this->zugang(false))();
	}

	/**
	 * Ohne Backend-Benutzer (Konsole, Cron) geschieht nichts.
	 */
	public function testOhneBenutzerNichts(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('benutzerId')->willReturn(0);
		$zugang->method('darfPruefen')->willReturn(false);

		$this->listener(array('act' => 'show', 'id' => (string) $this->kopien['tl_kinderschutz']), $zugang)();

		$this->assertArrayNotHasKey('filter', $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']);
	}

	/**
	 * Einzelaufrufe einer Kinderschutz-Kopie enden ohne Freigabe mit 403.
	 *
	 * @dataProvider einzelaktionen
	 *
	 * @param string $act     Die Aktion
	 * @param string $tabelle Herkunft der Kopie
	 */
	public function testEinzelaufrufGesperrt(string $act, string $tabelle): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => $act, 'id' => (string) $this->kopien[$tabelle]), $this->zugang(false))();
	}

	/**
	 * Aktionen auf einen einzelnen Eintrag.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function einzelaktionen(): array
	{
		return array(
			'Details Nachweis'           => array('show', 'tl_kinderschutz'),
			'Wiederherstellen Nachweis'  => array('undo', 'tl_kinderschutz'),
			'Löschen Nachweis'           => array('delete', 'tl_kinderschutz'),
			'Details Antrag'             => array('show', 'tl_kinderschutz_antrag'),
			'Wiederherstellen Antrag'    => array('undo', 'tl_kinderschutz_antrag'),
			'Bearbeiten Antrag'          => array('edit', 'tl_kinderschutz_antrag'),
			'Kopieren Antrag'            => array('copy', 'tl_kinderschutz_antrag'),
			// $dc->$act() ist von der Schreibweise unabhängig
			'Gemischte Schreibweise'     => array('UnDo', 'tl_kinderschutz'),
		);
	}

	/**
	 * Fremde Kopien bleiben aufrufbar.
	 *
	 * @dataProvider fremdeAktionen
	 *
	 * @param string $act Die Aktion
	 */
	public function testFremderEintragErlaubt(string $act): void
	{
		$this->listener(array('act' => $act, 'id' => (string) $this->kopien['tl_news']), $this->zugang(false))();
		$this->listener(array('act' => $act, 'id' => (string) $this->kopien['tlXkinderschutz']), $this->zugang(false))();

		// Keine Ausnahme in beiden Aufrufen
		$this->addToAssertionCount(2);
	}

	/**
	 * Aktionen auf fremde Kopien.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function fremdeAktionen(): array
	{
		return array(
			'Details'          => array('show'),
			'Wiederherstellen' => array('undo'),
		);
	}

	/**
	 * Eine unbekannte ID überlässt die Antwort Contao (Datensatz fehlt).
	 */
	public function testUnbekannteIdErlaubt(): void
	{
		$this->listener(array('act' => 'show', 'id' => '999'), $this->zugang(false))();

		// Keine Ausnahme; der Filter steht trotzdem
		$this->assertCount(1, $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter']);
	}

	/**
	 * Eine ID aus anderen Zeichen als Ziffern ist von Hand gebaut: 403.
	 *
	 * MySQL läse „1abc“ als 1; das Bundle rät nicht mit.
	 */
	public function testUngueltigeIdGesperrt(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => 'show', 'id' => $this->kopien['tl_kinderschutz'].'abc'), $this->zugang(false))();
	}

	/**
	 * Sammelaktionen ohne ID laufen in die gefilterte Liste und bleiben erlaubt.
	 */
	public function testSammelaktionOhneIdErlaubt(): void
	{
		$this->listener(array('act' => 'select'), $this->zugang(false))();

		$this->assertCount(1, $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter']);
	}

	/**
	 * Baut eine Zugangsprüfung für einen angemeldeten Benutzer.
	 *
	 * @param bool $darfPruefen Ob er Hauptadmin oder freigegebener Prüfer ist
	 *
	 * @return Zugang Die Attrappe mit Benutzer-ID 5
	 */
	private function zugang(bool $darfPruefen): Zugang
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('benutzerId')->willReturn(5);
		$zugang->method('darfPruefen')->willReturn($darfPruefen);

		return $zugang;
	}

	/**
	 * Baut den Listener mit einer Anfrage.
	 *
	 * @param array<string, string> $abfrage Werte der Adresse
	 * @param Zugang                $zugang  Die Zugangsprüfung
	 *
	 * @return UndoListener Der Listener
	 */
	private function listener(array $abfrage, Zugang $zugang): UndoListener
	{
		return new UndoListener($zugang, $this->eingabe(array('do' => 'undo') + $abfrage), $this->db);
	}
}
