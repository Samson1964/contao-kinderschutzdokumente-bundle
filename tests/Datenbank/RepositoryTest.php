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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;

/**
 * Prüft die Repositories gegen SQLite.
 */
class RepositoryTest extends TestCase
{
	private Connection $db;

	private int $jetzt;

	/**
	 * Frische Datenbank, „jetzt“ ist der 15.10.2026 12:00.
	 */
	protected function setUp(): void
	{
		$this->db = Testdatenbank::verbindung();
		$this->jetzt = strtotime('2026-10-15 12:00');
	}

	/**
	 * Nachweise werden nach Mitglied und Art gruppiert.
	 */
	public function testNachweiseGruppiert(): void
	{
		$repo = new NachweisRepository($this->db);
		$a = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'gueltigBis' => null));
		$repo->anlegen(array('member' => 3, 'dokumentart' => 11, 'status' => Nachweis::EINGEREICHT));
		$repo->anlegen(array('member' => 4, 'dokumentart' => 10, 'status' => Nachweis::EINGEREICHT));

		$alle = $repo->fuerMitglieder(array(3, 4, 9));

		$this->assertSame(array(3, 4), array_keys($alle));
		$this->assertSame(array(10, 11), array_keys($alle[3]));
		$this->assertSame($a, $alle[3][10][0]->id);
		$this->assertNull($alle[3][10][0]->gueltigBis);
		$this->assertSame(array(), $repo->fuerMitglieder(array()));
		$this->assertSame(array(10, 11), array_keys($repo->fuerMitglied(3)));
	}

	/**
	 * Von Contao angelegte, nie gespeicherte Datensätze (tstamp 0) zählen nicht.
	 */
	public function testUngespeicherteZaehlenNicht(): void
	{
		$repo = new NachweisRepository($this->db);
		Testdatenbank::nachweis($this->db, array('tstamp' => 0, 'member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT));

		$this->assertSame(array(), $repo->fuerMitglied(3));
	}

	/**
	 * Wartende, Papierkorb, Dateien eines Mitglieds und Zählung je Art.
	 */
	public function testAbfragen(): void
	{
		$repo = new NachweisRepository($this->db);
		$wartend = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::EINGEREICHT, 'datei' => '3/a.pdf'));
		$imKorb = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'datei' => '3/b.pdf', 'papierkorbSeit' => 1000));
		$repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'datei' => '', 'papierkorbSeit' => 900));

		$this->assertSame(array($wartend), $repo->wartende(3, 10));
		$this->assertSame(array($imKorb), $repo->imPapierkorb());
		$this->assertSame(array(), $repo->imPapierkorb(1000));
		$this->assertSame(array($imKorb), $repo->imPapierkorb(1001));
		$this->assertSame(array($wartend), $repo->mitDatei(3));
		$this->assertSame(3, $repo->anzahlFuerArt(10));

		$repo->aendern($wartend, array('status' => Nachweis::ERSETZT));
		$this->assertSame(Nachweis::ERSETZT, $repo->zeile($wartend)['status']);
		$this->assertNull($repo->zeile(999));
	}

	/**
	 * Aktive Dokumentarten in der eingestellten Reihenfolge.
	 */
	public function testDokumentarten(): void
	{
		$repo = new DokumentartRepository($this->db);
		$schulung = Testdatenbank::dokumentart($this->db, 'Schulungsnachweis', 0, false, true, 3);
		$efz = Testdatenbank::dokumentart($this->db, 'Führungszeugnis', 24, true, true, 1);
		$alt = Testdatenbank::dokumentart($this->db, 'Alt', 0, false, true, 2);
		$this->db->update('tl_kinderschutz_dokumentart', array('published' => ''), array('id' => $alt));

		$this->assertSame(array($efz, $schulung), array_map(static fn ($art) => $art->id, $repo->aktive()));
		$this->assertTrue($repo->eine($efz)->papierkorbNachPruefung);
		$this->assertSame('Alt', $repo->eine($alt)->name);
		$this->assertNull($repo->eine(999));
	}

	/**
	 * Nur aktive Mitglieder der gewählten Gruppen, sortiert.
	 */
	public function testMitgliederInGruppen(): void
	{
		$repo = new PersonenRepository($this->db);
		$berta = Testdatenbank::mitglied($this->db, 'Berta', 'Zander', array(1));
		$anton = Testdatenbank::mitglied($this->db, 'Anton', 'Adler', array(2, 1));
		// Contao 5.7 speichert „aktiv“ als boolean 0, 4.13 als ''
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Zeh', array(1), array('disable' => '0'));
		Testdatenbank::mitglied($this->db, 'Gesperrt', 'Gans', array(1), array('disable' => '1'));
		Testdatenbank::mitglied($this->db, 'Spaeter', 'Specht', array(1), array('start' => (string) ($this->jetzt + 86400)));
		Testdatenbank::mitglied($this->db, 'Vorbei', 'Vogel', array(1), array('stop' => (string) ($this->jetzt - 1)));
		// Gruppe 11 darf bei der Suche nach Gruppe 1 nicht mitkommen
		Testdatenbank::mitglied($this->db, 'Falsch', 'Fuchs', array(11));

		$this->assertSame(array($anton, $berta, $carla), array_column($repo->mitgliederInGruppen(array(1), $this->jetzt), 'id'));
		$this->assertSame(array($anton), array_column($repo->mitgliederInGruppen(array(2), $this->jetzt), 'id'));
		$this->assertSame(array(), $repo->mitgliederInGruppen(array(), $this->jetzt));
		$this->assertTrue($repo->istInGruppen($berta, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen($berta, array(2), $this->jetzt));
		$this->assertSame('Zander, Berta', PersonenRepository::name($repo->mitglied($berta)));
		$this->assertNull($repo->mitglied(999));
	}

	/**
	 * Hauptadmin und freigegebene, nicht gesperrte Benutzer bekommen Mails.
	 */
	public function testPrueferAdressen(): void
	{
		$this->db->insert('tl_user', array('name' => 'Haupt', 'email' => 'haupt@example.org'));
		$this->db->insert('tl_user', array('name' => 'Astrid', 'email' => 'astrid@example.org', 'kinderschutzPruefer' => '1'));
		$this->db->insert('tl_user', array('name' => 'Admin', 'email' => 'admin@example.org', 'admin' => '1'));
		$this->db->insert('tl_user', array('name' => 'Gesperrt', 'email' => 'weg@example.org', 'kinderschutzPruefer' => '1', 'disable' => '1'));
		$this->db->insert('tl_user', array('name' => 'Fünf', 'email' => 'fuenf@example.org', 'kinderschutzPruefer' => '1', 'disable' => '0'));

		$this->assertSame(array('haupt@example.org', 'astrid@example.org', 'fuenf@example.org'), (new PersonenRepository($this->db))->prueferAdressen(1));
	}

	/**
	 * Teilnehmer abgleichen: Herkunft bleibt, Abgewählte verschwinden.
	 */
	public function testTeilnehmerAbgleichen(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$jwm = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', '2026-11-02', '2026-11-12');

		$this->assertTrue($repo->anmelden($jwm, 5, strtotime('2026-10-15'), $this->jetzt));
		$repo->setzeTeilnehmer($jwm, array(5, 6), $this->jetzt);

		$quellen = $this->db->fetchAllKeyValue('SELECT member, quelle FROM tl_kinderschutz_teilnahme ORDER BY member');
		$this->assertSame(array(5 => 'frontend', 6 => 'backend'), array_map('strval', $quellen));

		$repo->setzeTeilnehmer($jwm, array(6), $this->jetzt);
		$this->assertSame(array(6), $repo->teilnehmer($jwm));
	}

	/**
	 * Selbst abmelden geht nur bei selbst angelegter Teilnahme.
	 */
	public function testAnUndAbmelden(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$heute = strtotime('2026-10-15');
		$offen = Testdatenbank::veranstaltung($this->db, 'Offen', '2026-11-02', '2026-11-12');
		$geschlossen = Testdatenbank::veranstaltung($this->db, 'Geschlossen', '2026-11-02', '', false);
		$vorbei = Testdatenbank::veranstaltung($this->db, 'Vorbei', '2026-10-01', '2026-10-14');

		$this->assertFalse($repo->anmelden($geschlossen, 5, $heute, $this->jetzt));
		$this->assertFalse($repo->anmelden($vorbei, 5, $heute, $this->jetzt));
		$this->assertTrue($repo->anmelden($offen, 5, $heute, $this->jetzt));
		$this->assertTrue($repo->anmelden($offen, 5, $heute, $this->jetzt));
		$this->assertSame(array(5), $repo->teilnehmer($offen));

		$repo->setzeTeilnehmer($geschlossen, array(5), $this->jetzt);
		$this->assertFalse($repo->abmelden($geschlossen, 5));
		$this->assertTrue($repo->abmelden($offen, 5));
		$this->assertSame(array(), $repo->teilnehmer($offen));
	}

	/**
	 * Wählbar sind nur offene, nicht beendete Veranstaltungen; laufende zählen noch.
	 */
	public function testWaehlbareUndEigene(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$heute = strtotime('2026-10-15');
		$laufend = Testdatenbank::veranstaltung($this->db, 'Laufend', '2026-10-10', '2026-10-15');
		$kommend = Testdatenbank::veranstaltung($this->db, 'Kommend', '2026-11-02');
		Testdatenbank::veranstaltung($this->db, 'Vorbei', '2026-10-01', '2026-10-14');
		Testdatenbank::veranstaltung($this->db, 'Geschlossen', '2026-12-01', '', false);

		$this->assertSame(array('Laufend', 'Kommend'), array_column($repo->waehlbare($heute), 'name'));

		$repo->setzeTeilnehmer($kommend, array(5), $this->jetzt);
		$repo->anmelden($laufend, 5, $heute, $this->jetzt);
		$eigene = $repo->fuerMitglied(5, $heute);
		$this->assertSame(array('Laufend', 'Kommend'), array_column($eigene, 'name'));
		$this->assertSame(array('frontend', 'backend'), array_column($eigene, 'quelle'));
	}

	/**
	 * Kommende Teilnahmen für die Veranstaltungsmail, Merker und Löschen.
	 */
	public function testKommendeTeilnahmen(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$heute = strtotime('2026-10-15');
		$kommend = Testdatenbank::veranstaltung($this->db, 'Kommend', '2026-11-02');
		$laufend = Testdatenbank::veranstaltung($this->db, 'Laufend', '2026-10-15', '2026-10-20');
		$repo->setzeTeilnehmer($kommend, array(5), $this->jetzt);
		$repo->setzeTeilnehmer($laufend, array(5), $this->jetzt);

		$zeilen = $repo->kommendeTeilnahmen($heute);
		$this->assertSame(array('Kommend'), array_column($zeilen, 'name'));

		$repo->merkeErinnert((int) $zeilen[0]['teilnahme'], $this->jetzt);
		$this->assertSame($this->jetzt, (int) $repo->kommendeTeilnahmen($heute)[0]['erinnertAm']);

		$repo->loescheTeilnahmen($kommend);
		$this->assertSame(array(), $repo->teilnehmer($kommend));
		$this->assertSame(array(5), $repo->teilnehmer($laufend));
	}
}
