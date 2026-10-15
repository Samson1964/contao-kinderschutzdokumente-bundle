<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Benachrichtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einreichung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Pruefung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\UploadPruefer;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Testdateien;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prüft den Weg einer Unterlage: Einreichen, Ersetzen, Prüfen, Ablehnen,
 * Papierkorb und endgültiges Löschen – mit SQLite und echtem Dateisystem.
 */
class AblaufTest extends Testhilfen
{
	private Connection $db;

	private string $wurzel;

	private NachweisRepository $nachweise;

	private Papierkorb $papierkorb;

	private int $anna;

	private int $efz;

	private int $kodex;

	private int $jetzt;

	/**
	 * Datenbank mit Anna, EFZ (24 Monate, Papierkorb) und Ehrenkodex (unbegrenzt).
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->db = Testdatenbank::verbindung();
		$this->wurzel = sys_get_temp_dir().'/kinderschutz-ablage-'.bin2hex(random_bytes(4));
		$this->nachweise = new NachweisRepository($this->db);
		$this->papierkorb = new Papierkorb($this->nachweise, new Dateiablage($this->wurzel), $this->einstellungen(), new NullLogger());
		$this->anna = Testdatenbank::mitglied($this->db, 'Anna', 'Adler');
		$this->efz = Testdatenbank::dokumentart($this->db, 'Führungszeugnis', 24, true);
		$this->kodex = Testdatenbank::dokumentart($this->db, 'Ehrenkodex', 0);
		$this->db->insert('tl_user', array('name' => 'Haupt', 'email' => 'haupt@example.org'));
		$this->jetzt = strtotime('2026-10-15 12:00');
	}

	/**
	 * Räumt die Ablage weg.
	 */
	protected function tearDown(): void
	{
		foreach (glob($this->wurzel.'/{,.}*/*', GLOB_BRACE) ?: array() as $datei) {
			@unlink($datei);
		}

		foreach (glob($this->wurzel.'/*', GLOB_ONLYDIR) ?: array() as $ordner) {
			@rmdir($ordner);
		}

		@unlink($this->wurzel.'/.htaccess');
		@rmdir($this->wurzel);
		parent::tearDown();
	}

	/**
	 * Eine Einreichung landet geschützt in der Ablage und meldet sich bei den Prüfern.
	 */
	public function testEinreichen(): void
	{
		$this->assertNull($this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-01'), $this->jetzt));

		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz');
		$this->assertSame(Nachweis::EINGEREICHT, $zeile['status']);
		$this->assertSame('frontend', $zeile['quelle']);
		$this->assertSame('application/pdf', $zeile['dateityp']);
		$this->assertSame('Mein EFZ (2)_x_.pdf', $zeile['dateiname']);
		$this->assertMatchesRegularExpression('#^'.$this->anna.'/[0-9a-f]{32}\.pdf$#', $zeile['datei']);
		$this->assertFileExists($this->wurzel.'/'.$zeile['datei']);
		$this->assertSame("Require all denied\n", file_get_contents($this->wurzel.'/.htaccess'));
		$this->assertSame(strtotime('2026-10-01 00:00'), (int) $zeile['ausstellungsdatum']);

		$this->assertCount(1, $this->mails);
		$this->assertSame('haupt@example.org', $this->mails[0]->getTo()[0]->getAddress());
	}

	/**
	 * Unbekannte Art, Datum in der Zukunft und falscher Dateityp werden abgewiesen.
	 */
	public function testEinreichenAbgewiesen(): void
	{
		$dienst = $this->einreichung();

		$this->assertSame('art', $dienst->einreichen($this->anna, 999, $this->upload(), strtotime('2026-10-01'), $this->jetzt));
		$this->assertSame('datum', $dienst->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-16'), $this->jetzt));
		$this->assertSame('datum', $dienst->einreichen($this->anna, $this->efz, $this->upload(), 0, $this->jetzt));
		$this->assertSame('typ', $dienst->einreichen($this->anna, $this->efz, $this->upload(Testdateien::datei('<?php echo 1;', 'pdf')), strtotime('2026-10-01'), $this->jetzt));
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz'));
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Eine zweite Einreichung ersetzt die ungeprüfte erste; deren Datei geht in den Papierkorb.
	 */
	public function testErsetzen(): void
	{
		$dienst = $this->einreichung();
		$dienst->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		$dienst->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-02'), $this->jetzt + 60);

		$zeilen = $this->db->fetchAllAssociative('SELECT status, papierkorbSeit FROM tl_kinderschutz ORDER BY id');
		$this->assertSame(Nachweis::ERSETZT, $zeilen[0]['status']);
		$this->assertSame($this->jetzt + 60, (int) $zeilen[0]['papierkorbSeit']);
		$this->assertSame(Nachweis::EINGEREICHT, $zeilen[1]['status']);
		$this->assertSame(0, (int) $zeilen[1]['papierkorbSeit']);
	}

	/**
	 * Geprüftes EFZ: gültig bis berechnet, Prüfer vermerkt, Datei im Papierkorb.
	 */
	public function testPruefenEfz(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT, 'einschlaegig' => 'nein'));

		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 3600);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(strtotime('2028-03-14 00:00'), (int) $zeile['gueltigBis']);
		$this->assertSame($this->jetzt + 3600, (int) $zeile['geprueftAm']);
		$this->assertSame(7, (int) $zeile['geprueftVon']);
		$this->assertSame(Nachweis::GEPRUEFT, $zeile['verarbeitet']);
		$this->assertSame($this->jetzt + 3600, (int) $zeile['papierkorbSeit']);
	}

	/**
	 * Ein geprüfter Ehrenkodex bleibt archiviert und gilt unbegrenzt.
	 */
	public function testPruefenEhrenkodex(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->kodex, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT));

		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);

		$zeile = $this->nachweise->zeile($id);
		$this->assertNull($zeile['gueltigBis']);
		$this->assertSame(0, (int) $zeile['papierkorbSeit']);
	}

	/**
	 * Ablehnen: Datei in den Papierkorb, genau eine Mail, auch bei erneutem Speichern.
	 */
	public function testAblehnen(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->kodex, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Unterschrift fehlt'));
		$this->mails = array();

		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 60);

		$this->assertCount(1, $this->mails);
		$this->assertSame('anna.adler@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertStringContainsString('Unterschrift fehlt', $this->mails[0]->getTextBody());
		$this->assertSame($this->jetzt, (int) $this->nachweise->zeile($id)['papierkorbSeit']);
	}

	/**
	 * Korrigiertes Ausstellungsdatum ändert „gültig bis“, ohne neue Folgen.
	 */
	public function testKorrekturDesDatums(): void
	{
		$id = Testdatenbank::nachweis($this->db, array('member' => $this->anna, 'dokumentart' => $this->efz, 'status' => Nachweis::GEPRUEFT, 'ausstellungsdatum' => strtotime('2026-03-15'), 'quelle' => 'backend'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);
		$this->nachweise->aendern($id, array('ausstellungsdatum' => strtotime('2026-04-01')));
		$this->pruefung()->nachSpeichern($id, 8, $this->jetzt + 60);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(strtotime('2028-03-31 00:00'), (int) $zeile['gueltigBis']);
		$this->assertSame(7, (int) $zeile['geprueftVon']);
	}

	/**
	 * Eingabeprüfung der Prüfmaske.
	 */
	public function testEingabefehler(): void
	{
		$this->assertSame('grundFehlt', Pruefung::eingabefehler(Nachweis::ABGELEHNT, '  ', '', false));
		$this->assertNull(Pruefung::eingabefehler(Nachweis::ABGELEHNT, 'unleserlich', '', true));
		$this->assertSame('einschlaegigFehlt', Pruefung::eingabefehler(Nachweis::GEPRUEFT, '', '', true));
		$this->assertNull(Pruefung::eingabefehler(Nachweis::GEPRUEFT, '', 'nein', true));
		$this->assertNull(Pruefung::eingabefehler(Nachweis::GEPRUEFT, '', '', false));
		$this->assertNull(Pruefung::eingabefehler(Nachweis::EINGEREICHT, '', '', true));
	}

	/**
	 * Papierkorb: wiederherstellen, endgültig löschen, Frist, leeren, Mitglied entfernt.
	 */
	public function testPapierkorb(): void
	{
		$dienst = $this->einreichung();
		$dienst->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		$dienst->einreichen($this->anna, $this->kodex, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		list($a, $b) = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_kinderschutz ORDER BY id'));
		$dateiA = $this->wurzel.'/'.$this->nachweise->zeile($a)['datei'];

		$this->assertTrue($this->papierkorb->hineinlegen($a, $this->jetzt));
		$this->assertFalse($this->papierkorb->hineinlegen($a, $this->jetzt));
		$this->assertTrue($this->papierkorb->wiederherstellen($a));
		$this->assertSame(0, (int) $this->nachweise->zeile($a)['papierkorbSeit']);

		$this->papierkorb->hineinlegen($a, $this->jetzt);
		$this->assertSame(0, $this->papierkorb->faelligeLoeschen($this->jetzt + 29 * 86400));
		$this->assertSame(strtotime('2026-11-14 00:00'), $this->papierkorb->loeschtAm($this->jetzt));
		$this->assertSame(1, $this->papierkorb->faelligeLoeschen(strtotime('2026-11-14 03:00')));
		$this->assertFileDoesNotExist($dateiA);

		$zeile = $this->nachweise->zeile($a);
		$this->assertSame('', $zeile['datei']);
		$this->assertSame(strtotime('2026-11-14 03:00'), (int) $zeile['dateiGeloeschtAm']);
		$this->assertSame(Nachweis::EINGEREICHT, $zeile['status']);

		$this->assertSame(1, $this->papierkorb->mitgliedEntfernt($this->anna, $this->jetzt));
		$this->assertSame(1, $this->papierkorb->leeren($this->jetzt));
		$this->assertSame('', $this->nachweise->zeile($b)['datei']);
		$this->assertFalse($this->papierkorb->endgueltigLoeschen($b, $this->jetzt));
	}

	/**
	 * Eine frische PDF-Datei als Upload im Testmodus.
	 *
	 * @param string|null $pfad Eigene Datei statt des Standard-PDFs
	 *
	 * @return UploadedFile Der Upload; move() verschiebt im Testmodus per rename
	 */
	private function upload(?string $pfad = null): UploadedFile
	{
		return new UploadedFile($pfad ?? Testdateien::pdf(), 'Mein EFZ (2)<x>.pdf', null, UPLOAD_ERR_OK, true);
	}

	/**
	 * Der Einreichungsdienst mit allen echten Bausteinen.
	 *
	 * @return Einreichung Der Dienst
	 */
	private function einreichung(): Einreichung
	{
		return new Einreichung(
			$this->nachweise,
			new DokumentartRepository($this->db),
			new PersonenRepository($this->db),
			new Dateiablage($this->wurzel),
			new UploadPruefer(1024 * 1024),
			$this->papierkorb,
			$this->benachrichtigung(),
			new Berechtigung(1),
			new NullLogger(),
		);
	}

	/**
	 * Der Prüfdienst mit allen echten Bausteinen.
	 *
	 * @return Pruefung Der Dienst
	 */
	private function pruefung(): Pruefung
	{
		return new Pruefung($this->nachweise, new DokumentartRepository($this->db), new PersonenRepository($this->db), $this->papierkorb, $this->benachrichtigung(), new NullLogger());
	}

	/**
	 * Die Benachrichtigung mit sammelndem Mailer.
	 *
	 * @return Benachrichtigung Der Dienst
	 */
	private function benachrichtigung(): Benachrichtigung
	{
		return new Benachrichtigung($this->mailer(), $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger());
	}
}
