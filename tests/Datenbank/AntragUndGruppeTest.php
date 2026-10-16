<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;

/**
 * Prüft das Antragsregister und die Gruppenpflege an tl_member.
 */
class AntragUndGruppeTest extends TestCase
{
	private Connection $db;

	/**
	 * Legt eine leere Testdatenbank an.
	 */
	protected function setUp(): void
	{
		$this->db = Testdatenbank::verbindung();
	}

	/**
	 * Ein neuer Antrag ist offen und hält seine Veranstaltungen als Zahlen.
	 */
	public function testAnlegenUndLesen(): void
	{
		$antraege = new AntragRepository($this->db);
		$id = $antraege->anlegen(5, 'Training U12', array('3', 4), 1000);

		$zeile = $antraege->zeile($id);
		$this->assertSame('offen', $zeile['status']);
		$this->assertSame('Training U12', $zeile['wofuer']);
		$this->assertSame(1000, (int) $zeile['beantragtAm']);
		$this->assertSame(array(3, 4), AntragRepository::veranstaltungenAus($zeile['veranstaltungen']));
		$this->assertSame($id, (int) $antraege->offenerFuer(5)['id']);
		$this->assertNull($antraege->offenerFuer(6));
		$this->assertNull($antraege->zeile(999));
		$this->assertSame(1, $antraege->offeneAnzahl());
	}

	/**
	 * Der letzte Antrag ist der mit dem jüngsten Antragsdatum, nicht der mit der größten ID.
	 *
	 * ID und Datum laufen hier gegenläufig (der ältere Antrag hat die größere ID),
	 * und der ungespeicherte Entwurf aus „Mitglied freischalten“ trägt das
	 * jüngste Datum von allen. Er darf trotzdem nicht gewinnen.
	 */
	public function testLetzterAntrag(): void
	{
		$antraege = new AntragRepository($this->db);
		$juenger = $antraege->anlegen(5, 'jünger', array(), 2000);
		$antraege->aendern($juenger, array('status' => AntragRepository::ABGELEHNT, 'grund' => 'zu ungenau'));
		$aelter = $antraege->anlegen(5, 'älter', array(), 1000);
		$antraege->aendern($aelter, array('status' => AntragRepository::ABGELEHNT));
		$this->db->insert('tl_kinderschutz_antrag', array('member' => 5, 'status' => '', 'beantragtAm' => 3000));

		$this->assertGreaterThan($juenger, $aelter, 'Der Test braucht gegenläufige IDs.');
		$this->assertSame($juenger, (int) $antraege->letzterFuer(5)['id']);
		$this->assertSame('zu ungenau', $antraege->letzterFuer(5)['grund']);
		$this->assertNull($antraege->letzterFuer(6));
		$this->assertSame(0, $antraege->offeneAnzahl());
	}

	/**
	 * Beim Freischalten werden andere offene Anträge desselben Mitglieds mit geschlossen.
	 *
	 * Ein bereits abgelehnter Antrag desselben Mitglieds bleibt dabei, wie er ist.
	 */
	public function testSchliesseOffene(): void
	{
		$antraege = new AntragRepository($this->db);
		$offen = $antraege->anlegen(5, 'offen', array(), 1000);
		$fremd = $antraege->anlegen(6, 'fremd', array(), 1000);
		$selbst = $antraege->anlegen(5, 'selbst', array(), 1000);
		$abgelehnt = $antraege->anlegen(5, 'abgelehnt', array(), 500);
		$antraege->aendern($abgelehnt, array('status' => AntragRepository::ABGELEHNT, 'grund' => 'zu ungenau'));

		$antraege->schliesseOffene(5, $selbst, 7, 3000);

		$this->assertSame('freigeschaltet', $antraege->zeile($offen)['status']);
		$this->assertSame(3000, (int) $antraege->zeile($offen)['entschiedenAm']);
		$this->assertSame(7, (int) $antraege->zeile($offen)['entschiedenVon']);
		$this->assertSame('offen', $antraege->zeile($fremd)['status']);
		$this->assertSame('offen', $antraege->zeile($selbst)['status']);
		$this->assertSame('abgelehnt', $antraege->zeile($abgelehnt)['status']);
		$this->assertSame(0, (int) $antraege->zeile($abgelehnt)['entschiedenAm']);
		$this->assertSame(0, (int) $antraege->zeile($abgelehnt)['entschiedenVon']);
		$this->assertSame('zu ungenau', $antraege->zeile($abgelehnt)['grund']);
	}

	/**
	 * Unlesbare Veranstaltungslisten ergeben eine leere Liste.
	 */
	public function testVeranstaltungenAusKaputtemWert(): void
	{
		$this->assertSame(array(), AntragRepository::veranstaltungenAus(null));
		$this->assertSame(array(), AntragRepository::veranstaltungenAus(''));
		$this->assertSame(array(), AntragRepository::veranstaltungenAus('kein serialisierter Wert'));
		$this->assertSame(array(), AntragRepository::veranstaltungenAus(serialize('Text')));
	}

	/**
	 * Aufnehmen ergänzt die Gruppe, lässt die übrigen stehen und trägt nie doppelt ein.
	 */
	public function testInGruppeAufnehmen(): void
	{
		$personen = new PersonenRepository($this->db);
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Cramer', array(2));

		$this->assertTrue($personen->inGruppeAufnehmen($carla, 1));
		$this->assertFalse($personen->inGruppeAufnehmen($carla, 1));
		$this->assertFalse($personen->inGruppeAufnehmen(4711, 1));
		$this->assertFalse($personen->inGruppeAufnehmen($carla, 0));

		$gruppen = unserialize((string) $this->db->fetchOne('SELECT '.$this->db->quoteIdentifier('groups').' FROM tl_member WHERE id = ?', array($carla)));
		$this->assertSame(array('2', '1'), $gruppen, 'Contao speichert Gruppen als Zeichenketten.');
	}

	/**
	 * Aktiv heißt: nicht gesperrt und im Aktivierungszeitraum.
	 *
	 * „disable“ ist in 4.13 '' oder '1', in 5.7 '0' oder '1'; beide
	 * Schreibweisen für „nicht gesperrt“ zählen als aktiv.
	 */
	public function testIstAktivesMitglied(): void
	{
		$personen = new PersonenRepository($this->db);
		$aktiv = Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(2));
		$aktivNeu = Testdatenbank::mitglied($this->db, 'Nora', 'Neu', array(2), array('disable' => '0'));
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(2), array('disable' => '1'));
		$vorbei = Testdatenbank::mitglied($this->db, 'Alf', 'Abgelaufen', array(2), array('stop' => '500'));
		$spaeter = Testdatenbank::mitglied($this->db, 'Sven', 'Zukunft', array(2), array('start' => '2000'));

		$this->assertTrue($personen->istAktivesMitglied($aktiv, 1000));
		$this->assertTrue($personen->istAktivesMitglied($aktivNeu, 1000));
		$this->assertFalse($personen->istAktivesMitglied($gesperrt, 1000));
		$this->assertFalse($personen->istAktivesMitglied($vorbei, 1000));
		$this->assertFalse($personen->istAktivesMitglied($spaeter, 1000));
		$this->assertTrue($personen->istAktivesMitglied($spaeter, 2000), 'Ab „Aktivieren am“ ist das Mitglied aktiv.');
		$this->assertFalse($personen->istAktivesMitglied(4711, 1000));
	}

	/**
	 * Die Auswahl fürs direkte Freischalten: aktive Mitglieder außerhalb der Gruppe.
	 */
	public function testAktiveOhneGruppe(): void
	{
		$personen = new PersonenRepository($this->db);
		Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(1, 2));
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Cramer', array(2));
		$ohne = Testdatenbank::mitglied($this->db, 'Otto', 'Ohne', array());
		Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(2), array('disable' => '1'));

		$this->assertSame(array($carla, $ohne), array_column($personen->aktiveOhneGruppe(1, 1000), 'id'));
	}

	/**
	 * Ohne gültige Gruppe gibt es keine Auswahl.
	 *
	 * Ist die Gruppe der freigeschalteten Mitglieder noch nicht eingestellt
	 * (0), wäre sonst jedes aktive Mitglied „außerhalb der Gruppe“.
	 */
	public function testAktiveOhneGruppeOhneGueltigeGruppe(): void
	{
		$personen = new PersonenRepository($this->db);
		Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(1, 2));
		Testdatenbank::mitglied($this->db, 'Otto', 'Ohne', array());

		$this->assertSame(array(), $personen->aktiveOhneGruppe(0, 1000));
		$this->assertSame(array(), $personen->aktiveOhneGruppe(-1, 1000));
	}
}
