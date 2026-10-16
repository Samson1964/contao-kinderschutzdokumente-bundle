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
