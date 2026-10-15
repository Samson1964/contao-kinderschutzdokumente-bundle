<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Pruefstand;

use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass pruefstand/schema-anlegen.php nur harmlose eigene Befehle
 * ausführt.
 *
 * Die Prüfstände 4.13 und 5.7 teilen sich alle Bundles. Doctrine fasst die
 * Änderungen einer Tabelle zu einem ALTER TABLE zusammen, und
 * contao:migrate --dry-run liefert in 5.7 auch DROP-Anteile. Ein Befehl mit
 * „kinderschutz“ kann also fremde Spalten löschen.
 *
 * Das Skript läuft hier echt als eigener Prozess, gegen ein Wegwerfprojekt
 * mit einer Attrappe von vendor/bin/contao-console: contao:migrate liefert
 * die vorgegebenen Befehle als ndjson, dbal:run-sql schreibt den Befehl in
 * eine Protokolldatei statt in eine Datenbank.
 */
class SchemaAnlegenTest extends TestCase
{
	/** Pfad des Skripts */
	private const SKRIPT = __DIR__.'/../../pruefstand/schema-anlegen.php';

	/** Die Bundle-Spalte in tl_user, wie Doctrine sie anlegt */
	private const SPALTE = "ALTER TABLE tl_user ADD kinderschutzPruefer CHAR(1) DEFAULT '' NOT NULL";

	/** @var string Verzeichnis des Wegwerfprojekts */
	private string $projekt;

	/**
	 * Legt das Wegwerfprojekt mit der Konsolen-Attrappe an.
	 */
	protected function setUp(): void
	{
		$this->projekt = sys_get_temp_dir().'/kinderschutz-schema-'.bin2hex(random_bytes(6));
		mkdir($this->projekt.'/vendor/bin', 0777, true);

		file_put_contents($this->projekt.'/vendor/bin/contao-console', <<<'PHP'
			<?php
			// Attrappe der Contao-Konsole für SchemaAnlegenTest
			$projekt = dirname(__DIR__, 2);

			if ('contao:migrate' === ($argv[1] ?? '')) {
				echo json_encode(array('type' => 'migration-pending', 'names' => array())), "\n";
				echo json_encode(array('type' => 'schema-pending', 'commands' => json_decode(file_get_contents($projekt.'/befehle.json'), true))), "\n";

				exit(0);
			}

			if ('dbal:run-sql' === ($argv[1] ?? '')) {
				file_put_contents($projekt.'/ausgefuehrt.log', $argv[2]."\n", FILE_APPEND);

				exit(0);
			}

			exit(2);
			PHP);
	}

	/**
	 * Räumt das Wegwerfprojekt ab.
	 */
	protected function tearDown(): void
	{
		foreach (array('vendor/bin/contao-console', 'befehle.json', 'ausgefuehrt.log') as $datei) {
			if (is_file($this->projekt.'/'.$datei)) {
				unlink($this->projekt.'/'.$datei);
			}
		}

		rmdir($this->projekt.'/vendor/bin');
		rmdir($this->projekt.'/vendor');
		rmdir($this->projekt);
	}

	/**
	 * Eigene Tabellen und die Bundle-Spalte laufen, Fremdes bleibt liegen.
	 */
	public function testFuehrtNurEigeneBefehleAus(): void
	{
		$eigene = array('CREATE TABLE tl_kinderschutz (id INT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY(id))', self::SPALTE);

		[$code, $ausgabe] = $this->starte(array_merge($eigene, array('ALTER TABLE tl_news ADD fremd INT DEFAULT 0 NOT NULL')));

		$this->assertSame(0, $code);
		$this->assertStringContainsString('3 ausstehende Schemabefehle, davon 2 für dieses Bundle.', $ausgabe);
		$this->assertStringContainsString('Fertig.', $ausgabe);
		$this->assertSame($eigene, $this->ausgefuehrt());
	}

	/**
	 * Erlaubte Befehle: eigene Tabellen beliebig, die Bundle-Spalte einzeln.
	 *
	 * @dataProvider erlaubteBefehle
	 *
	 * @param string $befehl Der einzige ausstehende Befehl
	 */
	public function testErlaubterBefehlLaeuft(string $befehl): void
	{
		[$code] = $this->starte(array($befehl));

		$this->assertSame(0, $code);
		$this->assertSame(array($befehl), $this->ausgefuehrt());
	}

	/**
	 * Liefert Befehle, die das Skript ausführen darf.
	 *
	 * @return array<string, array{string}> Je Fall ein Befehl
	 */
	public static function erlaubteBefehle(): array
	{
		return array(
			'Spalte anlegen'              => array(self::SPALTE),
			'Spalte ändern'               => array("ALTER TABLE tl_user CHANGE kinderschutzPruefer kinderschutzPruefer CHAR(1) DEFAULT '' NOT NULL"),
			'Spalte mit Backticks'        => array("ALTER TABLE `tl_user` ADD `kinderschutzPruefer` CHAR(1) DEFAULT '' NOT NULL"),
			'eigene Tabelle ändern'       => array('ALTER TABLE tl_kinderschutz ADD gueltigkeit SMALLINT UNSIGNED DEFAULT 0 NOT NULL, CHANGE bemerkung bemerkung TEXT DEFAULT NULL'),
			'Index auf eigener Tabelle'   => array('CREATE INDEX member ON tl_kinderschutz_teilnahme (member)'),
			'weitere eigene Tabelle'      => array('CREATE TABLE tl_kinderschutz_dokumentart (id INT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY(id))'),
		);
	}

	/**
	 * Ein gesperrter Befehl hält alle zurück, auch die harmlosen davor.
	 *
	 * Das Skript bricht vor der ersten Ausführung ab, nennt den Grund und
	 * meldet 1. Der harmlose CREATE TABLE davor läuft nicht.
	 *
	 * @dataProvider gesperrteBefehle
	 *
	 * @param string $befehl Der gesperrte Befehl
	 * @param string $grund  Der erwartete Sperrgrund
	 */
	public function testGesperrterBefehlVerhindertJedeAusfuehrung(string $befehl, string $grund): void
	{
		[$code, $ausgabe, $fehler] = $this->starte(array('CREATE TABLE tl_kinderschutz (id INT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY(id))', $befehl));

		$this->assertSame(1, $code);
		$this->assertStringContainsString('GESPERRT ('.$grund.'): '.$befehl, $ausgabe);
		$this->assertStringContainsString('Abbruch, nichts ausgeführt: 1 der eigenen Befehle ist gesperrt', $fehler);
		$this->assertSame(array(), $this->ausgefuehrt());
	}

	/**
	 * Liefert Befehle, die das Skript nie ausführen darf.
	 *
	 * @return array<string, array{string, string}> Je Fall Befehl und Sperrgrund
	 */
	public static function gesperrteBefehle(): array
	{
		$fremd = 'ändert in der fremden Tabelle tl_user mehr als die Spalte kinderschutzPruefer';

		return array(
			'DROP einer fremden Spalte'        => array(self::SPALTE.', DROP fremd', 'enthält DROP'),
			'DROP in eigener Tabelle'          => array('ALTER TABLE tl_kinderschutz DROP alt', 'enthält DROP'),
			'eigene Tabelle löschen'           => array('DROP TABLE tl_kinderschutz_alt', 'enthält DROP'),
			'weitere fremde Spalte anlegen'    => array(self::SPALTE.", ADD fremd VARCHAR(255) DEFAULT '' NOT NULL", $fremd),
			'fremde Spalte ändern'             => array("ALTER TABLE tl_user CHANGE fremd fremd INT DEFAULT 0 NOT NULL, ADD kinderschutzPruefer CHAR(1) DEFAULT '' NOT NULL", $fremd),
			'Bundle-Spalte umbenennen'         => array("ALTER TABLE tl_user CHANGE kinderschutzPruefer fremd CHAR(1) DEFAULT '' NOT NULL", $fremd),
			'andere Spalte in fremder Tabelle' => array('ALTER TABLE tl_member ADD kinderschutzNotiz TEXT DEFAULT NULL', 'ändert in der fremden Tabelle tl_member mehr als die Spalte kinderschutzPruefer'),
			'Index auf fremder Tabelle'        => array('CREATE INDEX kinderschutzPruefer ON tl_user (kinderschutzPruefer)', $fremd),
			'Tabelle nicht erkennbar'          => array('RENAME TABLE tl_kinderschutz TO tl_kinderschutz_neu', 'Tabelle nicht erkennbar'),
		);
	}

	/**
	 * --nur-zeigen listet und führt nichts aus; gesperrte Befehle melden 1.
	 */
	public function testNurZeigen(): void
	{
		[$code, $ausgabe] = $this->starte(array(self::SPALTE), true);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('  '.self::SPALTE, $ausgabe);
		$this->assertStringContainsString('Nichts ausgeführt (--nur-zeigen).', $ausgabe);
		$this->assertSame(array(), $this->ausgefuehrt());

		[$code, $ausgabe, $fehler] = $this->starte(array(self::SPALTE.', DROP fremd'), true);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('GESPERRT (enthält DROP)', $ausgabe);
		$this->assertStringContainsString('Abbruch, nichts ausgeführt', $fehler);
		$this->assertSame(array(), $this->ausgefuehrt());
	}

	/**
	 * Startet das Skript gegen das Wegwerfprojekt.
	 *
	 * @param list<string> $befehle   Die ausstehenden Schemabefehle, die die
	 *                                Attrappe meldet
	 * @param bool         $nurZeigen Mit --nur-zeigen aufrufen
	 *
	 * @return array{0: int, 1: string, 2: string} Rückgabewert, Standardausgabe, Fehlerausgabe
	 */
	private function starte(array $befehle, bool $nurZeigen = false): array
	{
		file_put_contents($this->projekt.'/befehle.json', json_encode($befehle));

		$prozess = proc_open(
			array_merge(array(PHP_BINARY, self::SKRIPT, $this->projekt), $nurZeigen ? array('--nur-zeigen') : array()),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes
		);

		$ausgabe = (string) stream_get_contents($pipes[1]);
		$fehler = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return array(proc_close($prozess), $ausgabe, $fehler);
	}

	/**
	 * Liest, welche Befehle die Attrappe per dbal:run-sql bekommen hat.
	 *
	 * @return list<string> Die ausgeführten Befehle in ihrer Reihenfolge
	 */
	private function ausgefuehrt(): array
	{
		$datei = $this->projekt.'/ausgefuehrt.log';

		return is_file($datei) ? file($datei, FILE_IGNORE_NEW_LINES) : array();
	}
}
