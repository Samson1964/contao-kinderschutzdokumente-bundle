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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Benachrichtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Freischaltung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Prüft Antrag, Freischaltung, Ablehnung und direktes Freischalten.
 *
 * Ausgangslage am 16.10.2026: Die Freischaltgruppe ist 1 (Testhilfen),
 * Carla ist nur in Gruppe 2 („Standardmitglied“). Der Hauptadmin (ID 1)
 * bekommt die Antragsmails. Der Herbstcup ist wählbar, der interne
 * Lehrgang nicht.
 */
class FreischaltungTest extends Testhilfen
{
	private Connection $db;

	private int $carla;

	private int $cup;

	private int $intern;

	private int $jetzt;

	/**
	 * Legt die Ausgangslage an.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->db = Testdatenbank::verbindung();
		$this->jetzt = strtotime('2026-10-16 10:00');
		$this->db->insert('tl_user', array('name' => 'Hauptadmin', 'email' => 'admin@example.org'));
		$this->carla = Testdatenbank::mitglied($this->db, 'Carla', 'Cramer', array(2));
		$this->cup = Testdatenbank::veranstaltung($this->db, 'Herbstcup', '2026-11-20');
		$this->intern = Testdatenbank::veranstaltung($this->db, 'Interner Lehrgang', '2026-11-25', '', false);
	}

	/**
	 * Ein Antrag ist offen, behält nur wählbare Veranstaltungen und geht an die Prüfer.
	 */
	public function testBeantragenLegtOffenenAntragAnUndMeldetDenPruefern(): void
	{
		$this->assertNull($this->dienst()->beantragen($this->carla, '  Training <b>U12</b>  ', array($this->cup, $this->intern, 999), $this->jetzt));

		$antrag = (new AntragRepository($this->db))->offenerFuer($this->carla);
		$this->assertNotNull($antrag);
		$this->assertSame('Training U12', $antrag['wofuer']);
		$this->assertSame(array($this->cup), AntragRepository::veranstaltungenAus($antrag['veranstaltungen']));
		$this->assertSame($this->jetzt, (int) $antrag['beantragtAm']);
		$this->assertCount(1, $this->mails);
		$this->assertSame('admin@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertSame('Neuer Antrag auf Freischaltung: Cramer, Carla', $this->mails[0]->getSubject());
		$this->assertStringContainsString('- Herbstcup', $this->mails[0]->getTextBody());
	}

	/**
	 * Ohne brauchbare Begründung entsteht nichts.
	 *
	 * @dataProvider ungueltigeBegruendungen
	 *
	 * @param string $wofuer Die Eingabe
	 */
	public function testBeantragenVerlangtEineBegruendung(string $wofuer): void
	{
		$this->assertSame('wofuer', $this->dienst()->beantragen($this->carla, $wofuer, array(), $this->jetzt));
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz_antrag'));
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Leere, nur aus HTML bestehende und zu lange Begründungen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function ungueltigeBegruendungen(): array
	{
		return array(
			'leer'            => array(''),
			'nur Leerzeichen' => array("  \n "),
			'nur HTML'        => array('<b></b>'),
			'zu lang'         => array(str_repeat('ä', 501)),
		);
	}

	/**
	 * Nur echte Tags verschwinden; ein „<“ im Text und die Zeilenenden bleiben brauchbar.
	 *
	 * @dataProvider begruendungenImSpeicher
	 *
	 * @param string $eingabe     Text aus dem Formular
	 * @param string $gespeichert Was im Antrag landen muss
	 */
	public function testBeantragenBereinigtDieBegruendung(string $eingabe, string $gespeichert): void
	{
		$this->assertNull($this->dienst()->beantragen($this->carla, $eingabe, array(), $this->jetzt));
		$this->assertSame($gespeichert, (new AntragRepository($this->db))->offenerFuer($this->carla)['wofuer']);
	}

	/**
	 * Eingabe und erwarteter Eintrag.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function begruendungenImSpeicher(): array
	{
		$genau500 = str_repeat('ä', 500);

		return array(
			'kleiner-als vor Zahl'      => array('Betreuung bei <18-Jährigen', 'Betreuung bei <18-Jährigen'),
			'kleiner-als am Anfang'     => array('<12-Training JWM', '<12-Training JWM'),
			'Vergleich mit Leerzeichen' => array('U10 < U12', 'U10 < U12'),
			'Tag um ein Wort'           => array('<b>Training</b>', 'Training'),
			'Tag mit Attribut'          => array('Training <a href="x" onclick="y">U12</a>', 'Training U12'),
			'verschachtelter Tag'       => array('<<b>i>Text', 'Text'),
			'Rand wird getrimmt'        => array("  \t Training \n ", 'Training'),
			'Windows-Zeilenenden'       => array("Zeile 1\r\nZeile 2\rZeile 3", "Zeile 1\nZeile 2\nZeile 3"),
			'genau 500 Zeichen'         => array($genau500, $genau500),
		);
	}

	/**
	 * Solange ein Antrag offen ist, gibt es keinen zweiten.
	 */
	public function testZweiterOffenerAntragWirdAbgewiesen(): void
	{
		$this->assertNull($this->dienst()->beantragen($this->carla, 'Betreuung JWM', array(), $this->jetzt));
		$this->assertSame('offen', $this->dienst()->beantragen($this->carla, 'nochmal', array(), $this->jetzt + 60));
		$this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz_antrag'));
	}

	/**
	 * Ohne eingestellte Gruppe nimmt das Bundle keine Anträge an.
	 */
	public function testOhneGruppeKeinAntrag(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';

		$this->assertSame('gruppe', $this->dienst()->beantragen($this->carla, 'Training', array(), $this->jetzt));
	}

	/**
	 * Freischalten: Gruppe dazu, Veranstaltung als Selbstanmeldung, Vermerk, Mail.
	 */
	public function testFreischaltenNimmtInGruppeAufMeldetAnUndMailt(): void
	{
		$antrag = $this->offenerAntrag(array($this->cup));
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $antrag));

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));

		$personen = new PersonenRepository($this->db);
		$this->assertTrue($personen->istInGruppen($this->carla, array(1), $this->jetzt));
		$this->assertTrue($personen->istInGruppen($this->carla, array(2), $this->jetzt), 'Die bisherige Gruppe bleibt.');
		$this->assertSame(
			array(array('member' => (string) $this->carla, 'quelle' => 'frontend')),
			array_map(static fn (array $z): array => array('member' => (string) $z['member'], 'quelle' => (string) $z['quelle']), $this->db->fetchAllAssociative('SELECT member, quelle FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ?', array($this->cup)))
		);
		$zeile = (new AntragRepository($this->db))->zeile($antrag);
		$this->assertSame($this->jetzt, (int) $zeile['entschiedenAm']);
		$this->assertSame(7, (int) $zeile['entschiedenVon']);
		$this->assertCount(1, $this->mails);
		$this->assertSame('carla.cramer@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertSame('Sie können Ihre Kinderschutz-Unterlagen jetzt einreichen', $this->mails[0]->getSubject());
	}

	/**
	 * Erneutes Speichern zieht die Folgen kein zweites Mal.
	 */
	public function testEntscheidungWirktNurEinmal(): void
	{
		$antrag = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $antrag));

		$this->dienst()->nachSpeichern($antrag, 7, $this->jetzt);
		$this->dienst()->nachSpeichern($antrag, 7, $this->jetzt + 60);

		$this->assertCount(1, $this->mails);
	}

	/**
	 * Ein offener Antrag bleibt beim Speichern unberührt.
	 */
	public function testOffenerAntragBleibtUnberuehrt(): void
	{
		$antrag = $this->offenerAntrag();

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));
		$this->assertSame(0, (int) (new AntragRepository($this->db))->zeile($antrag)['entschiedenAm']);
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Ablehnen: keine Gruppe, Mail mit der Begründung.
	 */
	public function testAblehnenMailtDieBegruendung(): void
	{
		$antrag = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'abgelehnt', 'grund' => 'Bitte erst mit der Vereinsführung sprechen.'), array('id' => $antrag));

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));

		$this->assertFalse((new PersonenRepository($this->db))->istInGruppen($this->carla, array(1), $this->jetzt));
		$this->assertSame('Ihr Antrag auf Freischaltung wurde nicht angenommen', $this->mails[0]->getSubject());
		$this->assertStringContainsString('Bitte erst mit der Vereinsführung sprechen.', $this->mails[0]->getTextBody());
	}

	/**
	 * Ist das Mitglied schon in der Gruppe, geht die Mail trotzdem, und die Gruppe steht nicht doppelt da.
	 *
	 * Das kommt vor, wenn ein Administrator das Mitglied inzwischen von Hand
	 * in die Gruppe gelegt hat, während der Antrag noch offen war.
	 */
	public function testFreischaltenEinesMitgliedsAusDerGruppeMailtTrotzdem(): void
	{
		$anna = Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(2, 1));
		$antrag = (new AntragRepository($this->db))->anlegen($anna, 'Training', array(), $this->jetzt - 3600);
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $antrag));

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));

		$gruppen = unserialize((string) $this->db->fetchOne('SELECT '.$this->db->quoteIdentifier('groups').' FROM tl_member WHERE id = ?', array($anna)));
		$this->assertSame(array('2', '1'), $gruppen, 'Die Gruppe wird nicht ein zweites Mal eingetragen.');
		$this->assertCount(1, $this->mails);
		$this->assertSame('anna.adler@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertSame($this->jetzt, (int) (new AntragRepository($this->db))->zeile($antrag)['entschiedenAm']);
	}

	/**
	 * Ist das Mitglied eines abgelehnten Antrags inzwischen gelöscht, gilt die Ablehnung ohne Mail.
	 */
	public function testAblehnenOhneMitgliedMailtNichtUndMerktDieEntscheidung(): void
	{
		$antrag = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'abgelehnt', 'grund' => 'Zu ungenau.'), array('id' => $antrag));
		$this->db->delete('tl_member', array('id' => $this->carla));

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));

		$this->assertSame(array(), $this->mails);
		$zeile = (new AntragRepository($this->db))->zeile($antrag);
		$this->assertSame($this->jetzt, (int) $zeile['entschiedenAm']);
		$this->assertSame(7, (int) $zeile['entschiedenVon']);
		$this->assertSame('abgelehnt', $zeile['status']);
	}

	/**
	 * Direkt freischalten setzt Stand und Vermerk und schließt offene Anträge.
	 */
	public function testDirektFreischaltenSchliesstOffeneAntraege(): void
	{
		$offen = $this->offenerAntrag();
		$this->db->insert('tl_kinderschutz_antrag', array('member' => $this->carla, 'wofuer' => 'Telefonat'));
		$direkt = (int) $this->db->lastInsertId();

		$this->assertTrue($this->dienst()->nachSpeichern($direkt, 7, $this->jetzt));

		$antraege = new AntragRepository($this->db);
		$this->assertSame('freigeschaltet', $antraege->zeile($direkt)['status']);
		$this->assertSame('1', (string) $antraege->zeile($direkt)['direkt']);
		$this->assertSame($this->jetzt, (int) $antraege->zeile($direkt)['beantragtAm']);
		$this->assertSame('freigeschaltet', $antraege->zeile($offen)['status']);
		$this->assertSame(7, (int) $antraege->zeile($offen)['entschiedenVon']);
		$this->assertTrue((new PersonenRepository($this->db))->istInGruppen($this->carla, array(1), $this->jetzt));
		$this->assertCount(1, $this->mails);
	}

	/**
	 * Mit dem Antrag geschlossene Anträge bringen ihre Veranstaltungen mit.
	 *
	 * Wer zwei Anträge gestellt hat, bekommt mit der Freischaltung des einen
	 * auch den anderen geschlossen; seine Veranstaltungen sollen trotzdem
	 * gelten, statt stillschweigend zu verfallen.
	 */
	public function testFreischaltenUebernimmtVeranstaltungenDerMitgeschlossenenAntraege(): void
	{
		$lehrgang = Testdatenbank::veranstaltung($this->db, 'Lehrgang', '2026-12-01');
		$antraege = new AntragRepository($this->db);
		$entschieden = $this->offenerAntrag(array($this->cup));
		$mitgeschlossen = $antraege->anlegen($this->carla, 'Lehrgang', array($lehrgang), $this->jetzt - 1800);
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $entschieden));

		$this->assertTrue($this->dienst()->nachSpeichern($entschieden, 7, $this->jetzt));

		$veranstaltungen = new VeranstaltungRepository($this->db);
		$this->assertSame(array($this->carla), $veranstaltungen->teilnehmer($this->cup));
		$this->assertSame(array($this->carla), $veranstaltungen->teilnehmer($lehrgang));
		$this->assertSame(array('frontend'), $this->db->fetchFirstColumn('SELECT quelle FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ?', array($lehrgang)));
		$this->assertSame('freigeschaltet', $antraege->zeile($mitgeschlossen)['status']);
		$this->assertCount(1, $this->mails, 'Das Mitglied bekommt nur eine Mail.');
	}

	/**
	 * Beim direkten Freischalten gelten die Veranstaltungen eines noch offenen Antrags.
	 */
	public function testDirektFreischaltenUebernimmtVeranstaltungenDesOffenenAntrags(): void
	{
		$this->offenerAntrag(array($this->cup));
		$this->db->insert('tl_kinderschutz_antrag', array('member' => $this->carla, 'wofuer' => 'Telefonat'));
		$direkt = (int) $this->db->lastInsertId();

		$this->assertTrue($this->dienst()->nachSpeichern($direkt, 7, $this->jetzt));

		$this->assertSame(array($this->carla), (new VeranstaltungRepository($this->db))->teilnehmer($this->cup));
	}

	/**
	 * Scheitert die Mail, bleibt die Freischaltung und der Aufrufer erfährt es.
	 */
	public function testGescheiterteMailMeldetFalseUndBehaeltDieEntscheidung(): void
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('send')->willThrowException(new TransportException('Server weg'));
		$antrag = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $antrag));

		$this->assertFalse($this->dienst($transport)->nachSpeichern($antrag, 7, $this->jetzt));
		$this->assertTrue((new PersonenRepository($this->db))->istInGruppen($this->carla, array(1), $this->jetzt));
		$this->assertSame($this->jetzt, (int) (new AntragRepository($this->db))->zeile($antrag)['entschiedenAm']);
	}

	/**
	 * Scheitert die Mail beim Ablehnen, bleibt die Ablehnung und der Aufrufer erfährt es.
	 */
	public function testGescheiterteMailBeimAblehnenMeldetFalseUndBehaeltDieEntscheidung(): void
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('send')->willThrowException(new TransportException('Server weg'));
		$antrag = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'abgelehnt', 'grund' => 'Zu ungenau.'), array('id' => $antrag));

		$this->assertFalse($this->dienst($transport)->nachSpeichern($antrag, 7, $this->jetzt));

		$zeile = (new AntragRepository($this->db))->zeile($antrag);
		$this->assertSame('abgelehnt', $zeile['status']);
		$this->assertSame($this->jetzt, (int) $zeile['entschiedenAm']);
		$this->assertSame(7, (int) $zeile['entschiedenVon']);
	}

	/**
	 * Scheitert die Mail an die Prüfer, gilt der Antrag trotzdem, und die Annahme meldet keinen Fehler.
	 */
	public function testGescheiterteMailAnDiePrueferLaesstDenAntragStehen(): void
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('send')->willThrowException(new TransportException('Server weg'));

		$this->assertNull($this->dienst($transport)->beantragen($this->carla, 'Training', array($this->cup), $this->jetzt));

		$antrag = (new AntragRepository($this->db))->offenerFuer($this->carla);
		$this->assertNotNull($antrag);
		$this->assertSame('Training', $antrag['wofuer']);
		$this->assertSame(array($this->cup), AntragRepository::veranstaltungenAus($antrag['veranstaltungen']));
	}

	/**
	 * Nach einer Ablehnung kann das Mitglied einen neuen Antrag stellen.
	 */
	public function testNachAblehnungIstEinNeuerAntragMoeglich(): void
	{
		$abgelehnt = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'abgelehnt', 'grund' => 'Zu ungenau.'), array('id' => $abgelehnt));
		$this->dienst()->nachSpeichern($abgelehnt, 7, $this->jetzt);

		$this->assertNull($this->dienst()->beantragen($this->carla, 'Genauer: Betreuung JWM', array(), $this->jetzt + 3600));

		$offen = (new AntragRepository($this->db))->offenerFuer($this->carla);
		$this->assertNotNull($offen);
		$this->assertNotSame($abgelehnt, (int) $offen['id']);
		$this->assertSame('Genauer: Betreuung JWM', $offen['wofuer']);
		$this->assertSame('abgelehnt', (new AntragRepository($this->db))->zeile($abgelehnt)['status'], 'Der abgelehnte Antrag bleibt, wie er war.');
	}

	/**
	 * Eine Veranstaltung, die beim Freischalten nicht mehr wählbar ist, fällt still weg.
	 *
	 * Die Freischaltung selbst gilt trotzdem: Gruppe, Merker und Mail.
	 */
	public function testNichtMehrWaehlbareVeranstaltungEntfaelltStill(): void
	{
		$antrag = $this->offenerAntrag(array($this->cup));
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $antrag));
		$this->db->update('tl_kinderschutz_veranstaltung', array('waehlbar' => ''), array('id' => $this->cup));

		$this->assertTrue($this->dienst()->nachSpeichern($antrag, 7, $this->jetzt));

		$this->assertSame(array(), (new VeranstaltungRepository($this->db))->teilnehmer($this->cup));
		$this->assertTrue((new PersonenRepository($this->db))->istInGruppen($this->carla, array(1), $this->jetzt));
		$this->assertSame($this->jetzt, (int) (new AntragRepository($this->db))->zeile($antrag)['entschiedenAm']);
		$this->assertCount(1, $this->mails);
	}

	/**
	 * Rückfallweg: Fehlen Mitglied oder Gruppe trotz Eingabeprüfung, bleibt der Antrag offen.
	 *
	 * Praktisch nicht erreichbar, weil die Maske beides unmittelbar vor dem
	 * Speichern prüft; der Test hält das Sicherheitsnetz fest.
	 */
	public function testFreischaltenOhneMitgliedOderGruppeBleibtOffen(): void
	{
		$antraege = new AntragRepository($this->db);
		$geloescht = $this->offenerAntrag();
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $geloescht));
		$this->db->delete('tl_member', array('id' => $this->carla));

		$this->assertTrue($this->dienst()->nachSpeichern($geloescht, 7, $this->jetzt));

		$this->assertSame('offen', $antraege->zeile($geloescht)['status']);
		$this->assertSame(0, (int) $antraege->zeile($geloescht)['entschiedenAm']);

		$anna = Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(2));
		$ohneGruppe = $antraege->anlegen($anna, 'Training', array(), $this->jetzt - 3600);
		$this->db->update('tl_kinderschutz_antrag', array('status' => 'freigeschaltet'), array('id' => $ohneGruppe));
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';

		$this->assertTrue($this->dienst()->nachSpeichern($ohneGruppe, 7, $this->jetzt));

		$this->assertSame('offen', $antraege->zeile($ohneGruppe)['status']);
		$this->assertFalse((new PersonenRepository($this->db))->istInGruppen($anna, array(1), $this->jetzt));
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Eingabeprüfung der Maske für Entscheidungen.
	 */
	public function testPruefeEntscheidung(): void
	{
		$dienst = $this->dienst();
		$offen = array('member' => $this->carla, 'status' => 'offen', 'entschiedenAm' => 0);
		$entschieden = array('status' => 'abgelehnt', 'entschiedenAm' => 1) + $offen;

		$this->assertNull($dienst->pruefeEntscheidung(null, 'freigeschaltet', ''));
		$this->assertNull($dienst->pruefeEntscheidung($offen, 'freigeschaltet', ''));
		$this->assertNull($dienst->pruefeEntscheidung($offen, 'offen', ''));
		$this->assertSame('antragGrundFehlt', $dienst->pruefeEntscheidung($offen, 'abgelehnt', '  '));
		$this->assertNull($dienst->pruefeEntscheidung($offen, 'abgelehnt', 'Grund'));
		$this->assertSame('mitgliedFehlt', $dienst->pruefeEntscheidung(array('member' => 4711) + $offen, 'freigeschaltet', ''));
		$this->assertSame('antragEntschieden', $dienst->pruefeEntscheidung($entschieden, 'freigeschaltet', ''));
		$this->assertNull($dienst->pruefeEntscheidung($entschieden, 'abgelehnt', ''));

		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';
		$this->assertSame('keineGruppe', $dienst->pruefeEntscheidung($offen, 'freigeschaltet', ''));
	}

	/**
	 * Freischalten verlangt wie „Mitglied freischalten“ ein aktives Mitglied; Ablehnen nicht.
	 *
	 * Ein gesperrtes oder abgelaufenes Mitglied käme sonst in die Gruppe,
	 * könnte sich aber nicht anmelden. Der Zeitpunkt ist ein optionaler
	 * vierter Parameter; ohne ihn gilt die aktuelle Zeit.
	 */
	public function testPruefeEntscheidungVerlangtEinAktivesMitglied(): void
	{
		$dienst = $this->dienst();
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(2), array('disable' => '1'));
		$abgelaufen = Testdatenbank::mitglied($this->db, 'Alf', 'Abgelaufen', array(2), array('stop' => (string) ($this->jetzt - 60)));
		$antrag = static fn (int $mitglied): array => array('member' => $mitglied, 'status' => 'offen', 'entschiedenAm' => 0);

		$this->assertSame('mitgliedFehlt', $dienst->pruefeEntscheidung($antrag($gesperrt), 'freigeschaltet', '', $this->jetzt));
		$this->assertSame('mitgliedFehlt', $dienst->pruefeEntscheidung($antrag($abgelaufen), 'freigeschaltet', '', $this->jetzt));
		$this->assertNull($dienst->pruefeEntscheidung($antrag($abgelaufen), 'freigeschaltet', '', $this->jetzt - 3600), 'Eine Stunde früher war das Mitglied noch aktiv.');
		$this->assertNull($dienst->pruefeEntscheidung($antrag($gesperrt), 'abgelehnt', 'Grund', $this->jetzt), 'Ablehnen geht auch bei gesperrten Mitgliedern.');
		$this->assertSame('mitgliedFehlt', $dienst->pruefeEntscheidung($antrag($gesperrt), 'freigeschaltet', ''), 'Ohne Zeitpunkt gilt die aktuelle Zeit.');
		$this->assertNull($dienst->pruefeEntscheidung($antrag($this->carla), 'freigeschaltet', ''));
	}

	/**
	 * Eingabeprüfung für „Mitglied freischalten“.
	 */
	public function testPruefeDirekt(): void
	{
		$dienst = $this->dienst();
		$anna = Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(1));
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(2), array('disable' => '1'));

		$this->assertNull($dienst->pruefeDirekt($this->carla, $this->jetzt));
		$this->assertSame('schonFreigeschaltet', $dienst->pruefeDirekt($anna, $this->jetzt));
		$this->assertSame('mitgliedFehlt', $dienst->pruefeDirekt($gesperrt, $this->jetzt));
		$this->assertSame('mitgliedFehlt', $dienst->pruefeDirekt(4711, $this->jetzt));

		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';
		$this->assertSame('keineGruppe', $dienst->pruefeDirekt($this->carla, $this->jetzt));
	}

	/**
	 * Legt einen offenen Antrag für Carla ohne Mail an.
	 *
	 * @param list<int> $veranstaltungen Angekreuzte Veranstaltungen
	 *
	 * @return int ID des Antrags
	 */
	private function offenerAntrag(array $veranstaltungen = array()): int
	{
		return (new AntragRepository($this->db))->anlegen($this->carla, 'Training', $veranstaltungen, $this->jetzt - 3600);
	}

	/**
	 * Der Dienst über der Testdatenbank mit sammelndem oder eigenem Transport.
	 *
	 * @param TransportInterface|null $transport Ersatz für den sammelnden Transport
	 *
	 * @return Freischaltung Der Dienst
	 */
	private function dienst(?TransportInterface $transport = null): Freischaltung
	{
		$einstellungen = $this->einstellungen();

		return new Freischaltung(
			new AntragRepository($this->db),
			new PersonenRepository($this->db),
			new VeranstaltungRepository($this->db),
			$einstellungen,
			new Benachrichtigung($transport ?? $this->transport(), $einstellungen, $this->verweise(), $this->framework(), new NullLogger()),
			new Berechtigung(1),
			new NullLogger(),
		);
	}
}
