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
use Psr\Log\LoggerInterface;
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
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;

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
	 * Scheitert das Löschen eines Eintrags, löscht die Schleife die übrigen trotzdem.
	 *
	 * Zwei Dateien sind fällig, die erste lässt sich nicht löschen (die Ablage
	 * wirft wie bei einer gesperrten Datei). Die zweite verschwindet trotzdem,
	 * die Rückgabe zählt nur sie, und das Protokoll bekommt genau einen
	 * Fehlereintrag mit der Nachweis-ID, aber ohne Pfad. Der gescheiterte
	 * Eintrag bleibt im Papierkorb und fällt beim nächsten Lauf wieder an.
	 */
	public function testLoeschenMachtNachEinemFehlerWeiter(): void
	{
		$dienst = $this->einreichung();
		$dienst->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		$dienst->einreichen($this->anna, $this->kodex, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		list($a, $b) = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_kinderschutz ORDER BY id'));
		$relativA = (string) $this->nachweise->zeile($a)['datei'];
		$relativB = (string) $this->nachweise->zeile($b)['datei'];
		$this->papierkorb->hineinlegen($a, $this->jetzt);
		$this->papierkorb->hineinlegen($b, $this->jetzt);

		$ablage = new class($this->wurzel) extends Dateiablage {
			/** @var string Pfad, dessen Löschen scheitern soll */
			public string $gesperrt = '';

			/** @var list<string> Alle Löschversuche in der Reihenfolge ihres Auftretens */
			public array $versuche = array();

			/**
			 * Wirft für den gesperrten Pfad, wie es eine gesperrte Datei unter Windows tut.
			 *
			 * @param string $relativ Pfad aus der Spalte „datei“
			 *
			 * @throws \RuntimeException Für den gesperrten Pfad
			 */
			public function loesche(string $relativ): void
			{
				$this->versuche[] = $relativ;

				if ($relativ === $this->gesperrt) {
					throw new \RuntimeException('Datei konnte nicht gelöscht werden: '.$relativ);
				}

				parent::loesche($relativ);
			}
		};
		$ablage->gesperrt = $relativA;

		$fehler = array();
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->method('error')->willReturnCallback(static function ($meldung) use (&$fehler): void {
			$fehler[] = (string) $meldung;
		});
		$korb = new Papierkorb($this->nachweise, $ablage, $this->einstellungen(), $protokoll);

		$this->assertSame(1, $korb->faelligeLoeschen($this->jetzt + 31 * 86400));

		// Die gesperrte Datei war zuerst dran, die zweite wurde trotzdem versucht
		$this->assertSame(array($relativA, $relativB), $ablage->versuche);
		$this->assertFileExists($this->wurzel.'/'.$relativA);
		$this->assertFileDoesNotExist($this->wurzel.'/'.$relativB);
		$this->assertSame($relativA, $this->nachweise->zeile($a)['datei']);
		$this->assertSame($this->jetzt, (int) $this->nachweise->zeile($a)['papierkorbSeit']);
		$this->assertSame('', $this->nachweise->zeile($b)['datei']);

		$this->assertCount(1, $fehler);
		$this->assertStringContainsString('Nachweis ID '.$a, $fehler[0]);
		$this->assertStringNotContainsString($relativA, $fehler[0]);
		$this->assertStringNotContainsString(basename($relativA), $fehler[0]);
		$this->assertStringNotContainsString($this->wurzel, $fehler[0]);

		// Beim nächsten Lauf, diesmal ohne Sperre, ist der Rest dran
		$this->assertSame(1, $this->papierkorb->leeren($this->jetzt + 32 * 86400));
		$this->assertFileDoesNotExist($this->wurzel.'/'.$relativA);
	}

	/**
	 * Eine gescheiterte Ablehnungsmail macht nachSpeichern() zu false.
	 *
	 * Die Entscheidung selbst ist trotzdem gespeichert (Status verarbeitet,
	 * Datei im Papierkorb); der Prüfer bekommt über den Rückgabewert die
	 * Gelegenheit, die Person direkt zu informieren. Ein erfolgreicher Versand,
	 * eine Prüfung und ein wiederholtes Speichern derselben Ablehnung liefern
	 * true, weil dabei keine Mail scheitert.
	 */
	public function testGescheiterteAblehnungsmailWirdGemeldet(): void
	{
		$kaputt = $this->createMock(TransportInterface::class);
		$kaputt->method('send')->willThrowException(new TransportException('Verbindung zum Mailserver verweigert'));
		$id = Testdatenbank::nachweis($this->db, array('member' => $this->anna, 'dokumentart' => $this->kodex, 'status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Unterschrift fehlt', 'ausstellungsdatum' => strtotime('2026-03-15'), 'quelle' => 'backend'));

		$this->assertFalse($this->pruefung($this->benachrichtigung($kaputt))->nachSpeichern($id, 7, $this->jetzt));

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(Nachweis::ABGELEHNT, $zeile['verarbeitet']);
		$this->assertSame($this->jetzt, (int) $zeile['geprueftAm']);
		$this->assertSame(array(), $this->mails);

		// Dieselbe Ablehnung noch einmal gespeichert: keine neue Mail, nichts gescheitert
		$this->assertTrue($this->pruefung($this->benachrichtigung($kaputt))->nachSpeichern($id, 7, $this->jetzt + 60));

		// Erfolgreicher Versand
		$zweiter = Testdatenbank::nachweis($this->db, array('member' => $this->anna, 'dokumentart' => $this->kodex, 'status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Seite 2 fehlt', 'ausstellungsdatum' => strtotime('2026-03-15'), 'quelle' => 'backend'));
		$this->assertTrue($this->pruefung()->nachSpeichern($zweiter, 7, $this->jetzt));
		$this->assertCount(1, $this->mails);

		// Eine Prüfung verschickt nichts und kann deshalb nicht an der Mail scheitern
		$gepruefter = $this->gepruefterEfz(strtotime('2026-03-15'));
		$this->assertTrue($this->pruefung($this->benachrichtigung($kaputt))->nachSpeichern($gepruefter, 7, $this->jetzt));

		// Ein Nachweis, den es nicht gibt, hat nichts zu melden
		$this->assertTrue($this->pruefung()->nachSpeichern(9999, 7, $this->jetzt));
	}

	/**
	 * Ist das Mitglied bei der Ablehnung schon gelöscht, bleibt die Mail aus und nachSpeichern() liefert false.
	 */
	public function testAblehnungOhneMitgliedWirdGemeldet(): void
	{
		$id = Testdatenbank::nachweis($this->db, array('member' => 9999, 'dokumentart' => $this->kodex, 'status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Unterschrift fehlt', 'ausstellungsdatum' => strtotime('2026-03-15'), 'quelle' => 'backend'));

		$this->assertFalse($this->pruefung()->nachSpeichern($id, 7, $this->jetzt));
		$this->assertSame(array(), $this->mails);
		$this->assertSame(Nachweis::ABGELEHNT, $this->nachweise->zeile($id)['verarbeitet']);
	}

	/**
	 * „Gültig bis“ bleibt fest, wenn die Monatszahl der Dokumentart später geändert wird.
	 *
	 * Beim Prüfen wird die Monatszahl der Art im Nachweis festgehalten
	 * (Spalte gueltigkeit). Weiteres Speichern, etwa nur der Bemerkung, ändert
	 * „gültig bis“ nicht, auch wenn die Art inzwischen kürzer gilt. Eine
	 * Korrektur des Ausstellungsdatums rechnet dagegen neu, aber mit der
	 * festgehaltenen Monatszahl.
	 */
	public function testGueltigBisBleibtBeiGeaenderterMonatszahlFest(): void
	{
		$id = $this->gepruefterEfz(strtotime('2026-03-15'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(24, (int) $zeile['gueltigkeit']);
		$this->assertSame(strtotime('2028-03-14 00:00'), (int) $zeile['gueltigBis']);

		// Die Art gilt nun nur noch 12 Monate; der Prüfer ändert lediglich die Bemerkung
		$this->db->update('tl_kinderschutz_dokumentart', array('gueltigkeit' => 12), array('id' => $this->efz));
		$this->nachweise->aendern($id, array('bemerkung' => 'Kopie liegt vor'));
		$this->pruefung()->nachSpeichern($id, 8, $this->jetzt + 60);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(24, (int) $zeile['gueltigkeit']);
		$this->assertSame(strtotime('2028-03-14 00:00'), (int) $zeile['gueltigBis']);
		$this->assertSame(7, (int) $zeile['geprueftVon']);

		// Ausstellungsdatum korrigiert: neu gerechnet, weiter mit 24 statt 12 Monaten
		$this->nachweise->aendern($id, array('ausstellungsdatum' => strtotime('2026-04-01')));
		$this->pruefung()->nachSpeichern($id, 8, $this->jetzt + 120);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(24, (int) $zeile['gueltigkeit']);
		$this->assertSame(strtotime('2028-03-31 00:00'), (int) $zeile['gueltigBis']);
	}

	/**
	 * Eine neue Entscheidung „geprüft“ übernimmt die dann gültige Monatszahl der Art.
	 *
	 * Wird ein Nachweis abgelehnt und danach doch als geprüft vermerkt, gilt
	 * für die neue Entscheidung die zu diesem Zeitpunkt eingestellte Monatszahl.
	 */
	public function testNeueEntscheidungUebernimmtDieAktuelleMonatszahl(): void
	{
		$id = $this->gepruefterEfz(strtotime('2026-03-15'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);
		$this->db->update('tl_kinderschutz_dokumentart', array('gueltigkeit' => 12), array('id' => $this->efz));

		$this->nachweise->aendern($id, array('status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Kopie unleserlich'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 60);
		$this->assertNull($this->nachweise->zeile($id)['gueltigBis']);

		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 120);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(12, (int) $zeile['gueltigkeit']);
		$this->assertSame(strtotime('2027-03-14 00:00'), (int) $zeile['gueltigBis']);
	}

	/**
	 * Ein abgelehnter Ehrenkodex, der doch als geprüft gilt, kommt aus dem Papierkorb zurück.
	 *
	 * Der Ehrenkodex verlangt keinen Papierkorb nach der Prüfung. Die Datei
	 * liegt nach der Ablehnung im Papierkorb und wird mit der neuen
	 * Entscheidung wiederhergestellt, bleibt dabei aber unverändert vorhanden.
	 */
	public function testAbgelehntZuGeprueftHoltDieDateiZurueck(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->kodex, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Unterschrift fehlt'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);
		$this->assertSame($this->jetzt, (int) $this->nachweise->zeile($id)['papierkorbSeit']);

		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 60);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(0, (int) $zeile['papierkorbSeit']);
		$this->assertFileExists($this->wurzel.'/'.$zeile['datei']);
	}

	/**
	 * Ein geprüftes EFZ, das wieder auf „eingereicht“ gestellt wird, kommt aus dem Papierkorb zurück.
	 *
	 * Sonst löschte der Cronjob die Datei einer Einreichung, die wieder auf
	 * Prüfung wartet. Die Prüfspuren (Zeitpunkt, „gültig bis“) fallen dabei weg.
	 */
	public function testGeprueftZuEingereichtHoltDieDateiZurueck(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT, 'einschlaegig' => 'nein'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);
		$this->assertSame($this->jetzt, (int) $this->nachweise->zeile($id)['papierkorbSeit']);

		$this->nachweise->aendern($id, array('status' => Nachweis::EINGEREICHT));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 60);

		$zeile = $this->nachweise->zeile($id);
		$this->assertSame(0, (int) $zeile['papierkorbSeit']);
		$this->assertSame(0, (int) $zeile['geprueftAm']);
		$this->assertNull($zeile['gueltigBis']);
		$this->assertFileExists($this->wurzel.'/'.$zeile['datei']);
	}

	/**
	 * Ein abgelehntes EFZ, das doch als geprüft gilt, bleibt im Papierkorb.
	 *
	 * Das EFZ verlangt den Papierkorb nach der Prüfung; die Datei liegt nach
	 * der Ablehnung schon dort und wird nicht wiederhergestellt.
	 */
	public function testAbgelehntesEfzBleibtNachDerPruefungImPapierkorb(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-03-15'), $this->jetzt);
		$id = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$this->nachweise->aendern($id, array('status' => Nachweis::ABGELEHNT, 'ablehnungsgrund' => 'Unterschrift fehlt'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt);

		$this->nachweise->aendern($id, array('status' => Nachweis::GEPRUEFT, 'einschlaegig' => 'nein'));
		$this->pruefung()->nachSpeichern($id, 7, $this->jetzt + 60);

		$this->assertGreaterThan(0, (int) $this->nachweise->zeile($id)['papierkorbSeit']);
	}

	/**
	 * Scheitert das Anlegen des Nachweises, bleibt weder eine Datei liegen noch wird die alte Einreichung ersetzt.
	 *
	 * Das Nachweis-Repository wirft beim Anlegen (die Datenbank ist weg). Die
	 * Ausnahme kommt beim Aufrufer an, die eben abgelegte Datei ist wieder
	 * gelöscht, und die vorher wartende Einreichung samt ihrer Datei ist
	 * unangetastet. Es geht auch keine Mail an die Prüfer.
	 */
	public function testGescheitertesAnlegenHinterlaesstKeineVerwaisteDatei(): void
	{
		$this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-01'), $this->jetzt);
		$erste = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz');
		$vorher = glob($this->wurzel.'/'.$this->anna.'/*') ?: array();
		$this->assertCount(1, $vorher);
		$this->mails = array();

		$kaputt = $this->getMockBuilder(NachweisRepository::class)
			->setConstructorArgs(array($this->db))
			->onlyMethods(array('anlegen'))
			->getMock();
		$kaputt->method('anlegen')->willThrowException(new \RuntimeException('Datenbank nicht erreichbar'));

		try {
			$this->einreichung($kaputt)->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-02'), $this->jetzt + 60);
			$this->fail('Die Ausnahme beim Anlegen hätte beim Aufrufer ankommen müssen.');
		} catch (\RuntimeException $fehler) {
			$this->assertSame('Datenbank nicht erreichbar', $fehler->getMessage());
		}

		$this->assertSame($vorher, glob($this->wurzel.'/'.$this->anna.'/*') ?: array());
		$this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz'));
		$zeile = $this->nachweise->zeile($erste);
		$this->assertSame(Nachweis::EINGEREICHT, $zeile['status']);
		$this->assertSame(Nachweis::EINGEREICHT, $zeile['verarbeitet']);
		$this->assertSame(0, (int) $zeile['papierkorbSeit']);
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Ein Ausstellungsdatum von heute wird angenommen, das von morgen nicht.
	 *
	 * Die Grenze liegt bei „heute 0:00 Uhr“: Wer am selben Tag ausgestellt
	 * bekommen hat, darf es einreichen.
	 */
	public function testAusstellungsdatumHeuteWirdAngenommen(): void
	{
		$this->assertNull($this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-15 00:00'), $this->jetzt));
		$this->assertSame(strtotime('2026-10-15 00:00'), (int) $this->db->fetchOne('SELECT ausstellungsdatum FROM tl_kinderschutz'));
		$this->assertSame('datum', $this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(), strtotime('2026-10-16 00:00'), $this->jetzt));
	}

	/**
	 * Eine abgewiesene Datei wird gar nicht erst abgelegt.
	 *
	 * Der Typ ist falsch (PHP-Quelltext als „.pdf“). Danach liegt in der
	 * Ablage nichts, auch nicht im Ordner des Mitglieds.
	 */
	public function testAbgewieseneDateiLiegtNichtInDerAblage(): void
	{
		$this->assertSame('typ', $this->einreichung()->einreichen($this->anna, $this->efz, $this->upload(Testdateien::datei('<?php echo 1;', 'pdf')), strtotime('2026-10-01'), $this->jetzt));

		$this->assertSame(array(), glob($this->wurzel.'/*/*') ?: array());
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz'));
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
	 * @param NachweisRepository|null $nachweise Ersatz für das Nachweis-Repository,
	 *                                           etwa ein Mock, dessen Anlegen
	 *                                           scheitert; null nimmt das echte
	 *
	 * @return Einreichung Der Dienst
	 */
	private function einreichung(?NachweisRepository $nachweise = null): Einreichung
	{
		return new Einreichung(
			$nachweise ?? $this->nachweise,
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
	 * @param Benachrichtigung|null $benachrichtigung Ersatz für die Benachrichtigung,
	 *                                                etwa mit einem Mailer, der
	 *                                                scheitert; null nimmt die
	 *                                                mit sammelndem Mailer
	 *
	 * @return Pruefung Der Dienst
	 */
	private function pruefung(?Benachrichtigung $benachrichtigung = null): Pruefung
	{
		return new Pruefung($this->nachweise, new DokumentartRepository($this->db), new PersonenRepository($this->db), $this->papierkorb, $benachrichtigung ?? $this->benachrichtigung(), new NullLogger());
	}

	/**
	 * Die Benachrichtigung, standardmäßig mit sammelndem Transport.
	 *
	 * @param TransportInterface|null $transport Eigener Transport; null nimmt
	 *                                           den sammelnden, der in
	 *                                           $this->mails ablegt
	 *
	 * @return Benachrichtigung Der Dienst
	 */
	private function benachrichtigung(?TransportInterface $transport = null): Benachrichtigung
	{
		return new Benachrichtigung($transport ?? $this->transport(), $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger());
	}

	/**
	 * Legt einen vom Prüfer ohne Datei erfassten, bereits geprüften EFZ-Nachweis an.
	 *
	 * @param int $ausstellung Zeitstempel des Ausstellungstags
	 *
	 * @return int ID des Nachweises (Status „geprüft“, noch nicht verarbeitet)
	 */
	private function gepruefterEfz(int $ausstellung): int
	{
		return Testdatenbank::nachweis($this->db, array('member' => $this->anna, 'dokumentart' => $this->efz, 'status' => Nachweis::GEPRUEFT, 'einschlaegig' => 'nein', 'ausstellungsdatum' => $ausstellung, 'quelle' => 'backend'));
	}
}
