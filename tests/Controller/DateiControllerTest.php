<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Controller;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Controller\DateiController;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Prüft die Dateiausgabe an Prüfer: Zugang, fehlende Dateien, Antwortkopf
 * und Protokoll.
 *
 * Datenbank (SQLite im Arbeitsspeicher) und Ablage (ein Ordner im
 * temporären Verzeichnis) sind echt; nur Berechtigung und Protokoll sind
 * Mocks. So prüft der Test, was ein Browser tatsächlich bekäme.
 */
class DateiControllerTest extends TestCase
{
	/** Inhalt der Testdatei, um zu sehen, dass wirklich sie ausgeliefert wird */
	private const INHALT = 'Inhalt der Testdatei 4711';

	private Connection $db;

	private string $wurzel;

	/**
	 * Legt Datenbank und einen leeren Ablageordner an.
	 */
	protected function setUp(): void
	{
		$this->db = Testdatenbank::verbindung();
		$this->wurzel = sys_get_temp_dir().'/kinderschutz-ablage-'.bin2hex(random_bytes(6));
		mkdir($this->wurzel.'/5', 0777, true);
	}

	/**
	 * Löscht den Ablageordner samt Inhalt.
	 */
	protected function tearDown(): void
	{
		foreach (glob($this->wurzel.'/*/*') ?: array() as $datei) {
			unlink($datei);
		}

		@rmdir($this->wurzel.'/5');
		@rmdir($this->wurzel);
	}

	/**
	 * Dateitypen, wie sie der Upload zulässt, mit Endung.
	 *
	 * @return array<string, array{0: string, 1: string}> Endung, MIME-Typ aus der Spalte „dateityp“
	 */
	public static function dateitypen(): array
	{
		return array(
			'PDF'  => array('pdf', 'application/pdf'),
			'JPEG' => array('jpg', 'image/jpeg'),
			'PNG'  => array('png', 'image/png'),
		);
	}

	/**
	 * Fälle, in denen ein Nachweis keine auslieferbare Datei hat.
	 *
	 * @return array<string, array{0: string}> Wert der Spalte „datei“
	 */
	public static function dateiloseNachweise(): array
	{
		return array(
			'Spalte leer (Prüfvermerk ohne Upload oder gelöscht)' => array(''),
			'Pfad außerhalb des Musters'                          => array('../../etc/passwd'),
			'gültiges Muster, aber Datei fehlt auf dem Server'    => array('5/'.str_repeat('cd', 16).'.pdf'),
		);
	}

	/**
	 * Ohne Prüferfreigabe wird nichts ausgeliefert und nichts protokolliert.
	 *
	 * Der Nachweis wird nicht einmal gelesen: Die Berechtigungsprüfung
	 * steht vor dem Datenbankzugriff. Fängt eine Route ab, die die Prüfung
	 * vergisst – dann könnte jeder angemeldete Benutzer fremde Unterlagen
	 * abrufen.
	 */
	public function testOhneFreigabeKeineAuslieferung(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->expects($this->once())->method('verlange')->willThrowException(new AccessDeniedException('Kein Zugang.'));
		$nachweise = $this->createMock(NachweisRepository::class);
		$nachweise->expects($this->never())->method($this->anything());
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method($this->anything());

		$this->expectException(AccessDeniedException::class);

		(new DateiController($zugang, $nachweise, new Dateiablage($this->wurzel), $protokoll))(1);
	}

	/**
	 * Eine unbekannte Nachweis-ID führt zu „nicht gefunden“, ohne Protokoll.
	 */
	public function testUnbekannteIdIstNichtGefunden(): void
	{
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method($this->anything());

		$this->expectException(NotFoundHttpException::class);

		$this->controller($protokoll)(9999);
	}

	/**
	 * Ein Nachweis ohne Datei führt zu „nicht gefunden“, ohne Protokoll.
	 *
	 * Drei Wege dorthin: leere Spalte, ein Pfad, der das Muster der Ablage
	 * verletzt (so könnte ein manipulierter Datensatz auf fremde Dateien
	 * zeigen), und ein gültiger Pfad, dessen Datei auf dem Server fehlt.
	 *
	 * @dataProvider dateiloseNachweise
	 *
	 * @param string $datei Wert der Spalte „datei“
	 */
	public function testNachweisOhneDateiIstNichtGefunden(string $datei): void
	{
		$id = $this->nachweis($datei, 'application/pdf');
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method($this->anything());

		$this->expectException(NotFoundHttpException::class);

		$this->controller($protokoll)($id);
	}

	/**
	 * Mit Datei wird sie sicher und nur für Prüfer ausgeliefert.
	 *
	 * Geprüft wird, was der Browser bekommt: die richtige Datei, den Typ aus
	 * der Spalte „dateityp“, „inline“ unter einem neutralen Namen
	 * (nachweis-<id>.<endung>, nie der Name des Hochladenden), „nosniff“ und
	 * ein Cache-Kopf, der Zwischenspeichern verbietet (no-store, private,
	 * nicht public). Das Protokoll bekommt genau einen Eintrag mit den IDs,
	 * aber weder Namen noch Dateinamen noch Pfad.
	 *
	 * @dataProvider dateitypen
	 *
	 * @param string $endung Endung der abgelegten Datei
	 * @param string $typ    MIME-Typ aus der Spalte „dateityp“
	 */
	public function testMitDateiWirdSicherAusgeliefert(string $endung, string $typ): void
	{
		$mitglied = Testdatenbank::mitglied($this->db, 'Max', 'Mustermann');
		$relativ = '5/'.str_repeat('ab', 16).'.'.$endung;
		file_put_contents($this->wurzel.'/'.$relativ, self::INHALT);
		$id = $this->nachweis($relativ, $typ, $mitglied, 'Führungszeugnis-Max-Mustermann.'.$endung);

		$protokolliert = array();
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->once())->method('info')->willReturnCallback(static function ($meldung) use (&$protokolliert): void {
			$protokolliert[] = (string) $meldung;
		});

		$antwort = $this->controller($protokoll)($id);

		$this->assertInstanceOf(BinaryFileResponse::class, $antwort);
		$this->assertSame(self::INHALT, file_get_contents($antwort->getFile()->getPathname()));
		$this->assertSame($typ, $antwort->headers->get('Content-Type'));
		$this->assertSame('nosniff', $antwort->headers->get('X-Content-Type-Options'));
		$this->assertSame('inline; filename=nachweis-'.$id.'.'.$endung, $antwort->headers->get('Content-Disposition'));

		$cache = (string) $antwort->headers->get('Cache-Control');
		$this->assertStringContainsString('no-store', $cache);
		$this->assertStringContainsString('private', $cache);
		$this->assertStringNotContainsString('public', $cache);

		$this->assertCount(1, $protokolliert);
		$this->assertStringContainsString('Nachweis ID '.$id, $protokolliert[0]);
		$this->assertStringContainsString('Mitglied ID '.$mitglied, $protokolliert[0]);

		foreach (array('Max', 'Mustermann', 'Führungszeugnis', $relativ, str_repeat('ab', 16)) as $verboten) {
			$this->assertStringNotContainsString($verboten, $protokolliert[0]);
		}
	}

	/**
	 * Auch eine Datei im Papierkorb wird ausgeliefert.
	 *
	 * Der Prüfer soll hineinsehen können, bevor er wiederherstellt oder
	 * endgültig löscht (siehe Klassenkommentar des Controllers).
	 */
	public function testDateiImPapierkorbWirdAusgeliefert(): void
	{
		$relativ = '5/'.str_repeat('ef', 16).'.pdf';
		file_put_contents($this->wurzel.'/'.$relativ, self::INHALT);
		$id = $this->nachweis($relativ, 'application/pdf', 5, '', array('papierkorbSeit' => 1760000000));

		$antwort = $this->controller($this->createMock(LoggerInterface::class))($id);

		$this->assertInstanceOf(BinaryFileResponse::class, $antwort);
		$this->assertSame(self::INHALT, file_get_contents($antwort->getFile()->getPathname()));
	}

	/**
	 * Baut den Controller mit echter Datenbank und Ablage, aber freigegebenem Zugang.
	 *
	 * @param LoggerInterface $protokoll Mock für das Systemprotokoll
	 *
	 * @return DateiController Der Controller unter Test
	 */
	private function controller(LoggerInterface $protokoll): DateiController
	{
		return new DateiController(
			$this->createMock(Zugang::class),
			new NachweisRepository($this->db),
			new Dateiablage($this->wurzel),
			$protokoll,
		);
	}

	/**
	 * Legt einen Nachweis mit Datei an.
	 *
	 * @param string               $datei    Wert der Spalte „datei“
	 * @param string               $typ      Wert der Spalte „dateityp“
	 * @param int                  $mitglied ID des Mitglieds
	 * @param string               $name     Ursprünglicher Dateiname des Hochladenden
	 * @param array<string, mixed> $mehr     Weitere Spalten
	 *
	 * @return int Die ID des Nachweises
	 */
	private function nachweis(string $datei, string $typ, int $mitglied = 5, string $name = 'zeugnis.pdf', array $mehr = array()): int
	{
		return Testdatenbank::nachweis($this->db, array_merge(array(
			'member'      => $mitglied,
			'dokumentart' => 1,
			'status'      => Nachweis::EINGEREICHT,
			'datei'       => $datei,
			'dateiname'   => $name,
			'dateityp'    => $typ,
		), $mehr));
	}
}
