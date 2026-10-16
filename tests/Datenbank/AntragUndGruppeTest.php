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
	 * Der letzte Antrag ist der jüngste; ein ungespeicherter Entwurf zählt nicht.
	 */
	public function testLetzterAntrag(): void
	{
		$antraege = new AntragRepository($this->db);
		$alt = $antraege->anlegen(5, 'alt', array(), 1000);
		$antraege->aendern($alt, array('status' => AntragRepository::ABGELEHNT, 'grund' => 'zu ungenau'));
		$neu = $antraege->anlegen(5, 'neu', array(), 2000);
		$antraege->aendern($neu, array('status' => AntragRepository::ABGELEHNT));
		$this->db->insert('tl_kinderschutz_antrag', array('member' => 5, 'status' => ''));

		$this->assertSame($neu, (int) $antraege->letzterFuer(5)['id']);
		$this->assertNull($antraege->letzterFuer(6));
		$this->assertSame(0, $antraege->offeneAnzahl());
	}

	/**
	 * Beim Freischalten werden andere offene Anträge desselben Mitglieds mit geschlossen.
	 */
	public function testSchliesseOffene(): void
	{
		$antraege = new AntragRepository($this->db);
		$offen = $antraege->anlegen(5, 'offen', array(), 1000);
		$fremd = $antraege->anlegen(6, 'fremd', array(), 1000);
		$selbst = $antraege->anlegen(5, 'selbst', array(), 1000);

		$antraege->schliesseOffene(5, $selbst, 7, 3000);

		$this->assertSame('freigeschaltet', $antraege->zeile($offen)['status']);
		$this->assertSame(3000, (int) $antraege->zeile($offen)['entschiedenAm']);
		$this->assertSame(7, (int) $antraege->zeile($offen)['entschiedenVon']);
		$this->assertSame('offen', $antraege->zeile($fremd)['status']);
		$this->assertSame('offen', $antraege->zeile($selbst)['status']);
	}

	/**
	 * Unlesbare Veranstaltungslisten ergeben eine leere Liste.
	 */
	public function testVeranstaltungenAusKaputtemWert(): void
	{
		$this->assertSame(array(), AntragRepository::veranstaltungenAus(null));
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
	 */
	public function testIstAktivesMitglied(): void
	{
		$personen = new PersonenRepository($this->db);
		$aktiv = Testdatenbank::mitglied($this->db, 'Anna', 'Adler', array(2));
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(2), array('disable' => '1'));
		$vorbei = Testdatenbank::mitglied($this->db, 'Alf', 'Abgelaufen', array(2), array('stop' => '500'));

		$this->assertTrue($personen->istAktivesMitglied($aktiv, 1000));
		$this->assertFalse($personen->istAktivesMitglied($gesperrt, 1000));
		$this->assertFalse($personen->istAktivesMitglied($vorbei, 1000));
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
}
