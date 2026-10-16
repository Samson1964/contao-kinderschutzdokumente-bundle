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
	 *
	 * Die Abfragen filtern nach Mitglied und Dokumentart. Das fällt nur auf,
	 * wenn es Datensätze gibt, die zum Filter nicht passen: Deshalb liegen
	 * neben den Nachweisen von Mitglied 3 / Art 10 ein eingereichter Nachweis
	 * von Mitglied 4 (gleiche Art), einer von Mitglied 3 zu Art 11 (gleiches
	 * Mitglied) und ein Prüfvermerk von Mitglied 3 ohne Datei und ohne
	 * Papierkorb in der Datenbank. Wer einen dieser Filter weglässt, bekommt
	 * fremde IDs zurück. Der Papierkorb-Teil nutzt bewusst Zeitstempel unter
	 * 2000, damit die Grenze 1000 / 1001 exakt geprüft wird.
	 *
	 * Die Methoden ohne ORDER BY werden ohne Rücksicht auf die Reihenfolge
	 * verglichen, weil sie keine Reihenfolge zusagen.
	 */
	public function testAbfragen(): void
	{
		$repo = new NachweisRepository($this->db);
		$wartend = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::EINGEREICHT, 'datei' => '3/a.pdf'));
		$imKorb = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'datei' => '3/b.pdf', 'papierkorbSeit' => 1000));
		$repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'datei' => '', 'papierkorbSeit' => 900));
		// Fremdes Mitglied, gleiche Art
		$fremd = $repo->anlegen(array('member' => 4, 'dokumentart' => 10, 'status' => Nachweis::EINGEREICHT, 'datei' => '4/a.pdf'));
		// Gleiches Mitglied, andere Art
		$andereArt = $repo->anlegen(array('member' => 3, 'dokumentart' => 11, 'status' => Nachweis::EINGEREICHT, 'datei' => '3/c.pdf'));
		// Prüfvermerk: Datei ist nach der Prüfung gelöscht, liegt aber nicht im Papierkorb
		$vermerk = $repo->anlegen(array('member' => 3, 'dokumentart' => 10, 'status' => Nachweis::GEPRUEFT, 'datei' => '', 'papierkorbSeit' => 0));

		$this->assertSame(array($wartend), $repo->wartende(3, 10));
		$this->assertSame(array($andereArt), $repo->wartende(3, 11));
		$this->assertSame(array($fremd), $repo->wartende(4, 10));
		$this->assertSame(array(), $repo->wartende(4, 11));
		$this->assertSame(array($imKorb), $repo->imPapierkorb());
		$this->assertSame(array(), $repo->imPapierkorb(1000));
		$this->assertSame(array($imKorb), $repo->imPapierkorb(1001));
		$this->assertEqualsCanonicalizing(array($wartend, $andereArt), $repo->mitDatei(3));
		$this->assertNotContains($vermerk, $repo->mitDatei(3));
		$this->assertSame(array($fremd), $repo->mitDatei(4));
		$this->assertSame(array(), $repo->mitDatei(9));
		$this->assertSame(5, $repo->anzahlFuerArt(10));
		$this->assertSame(1, $repo->anzahlFuerArt(11));
		$this->assertSame(0, $repo->anzahlFuerArt(99));

		$repo->aendern($wartend, array('status' => Nachweis::ERSETZT));
		$this->assertSame(Nachweis::ERSETZT, $repo->zeile($wartend)['status']);
		$this->assertNull($repo->zeile(999));
	}

	/**
	 * Ändern lässt den Änderungszeitpunkt (tstamp) unverändert.
	 *
	 * Die Backend-Liste ist nach tstamp sortiert. Setzte aendern() den Wert
	 * neu, würde jeder Versandmerker und jede Statusänderung durch den Cron
	 * den Nachweis nach oben springen lassen. Der Testwert 12345 ist weit weg
	 * von time(), so dass eine Aktualisierung sicher auffällt.
	 */
	public function testAendernLaesstTstampUnveraendert(): void
	{
		$repo = new NachweisRepository($this->db);
		$id = $repo->anlegen(array('tstamp' => 12345, 'member' => 3, 'dokumentart' => 10, 'status' => Nachweis::EINGEREICHT));

		$repo->aendern($id, array('status' => Nachweis::GEPRUEFT, 'erinnertAblauf' => 4711));

		$zeile = $repo->zeile($id);
		$this->assertSame(Nachweis::GEPRUEFT, $zeile['status']);
		$this->assertSame(4711, (int) $zeile['erinnertAblauf']);
		$this->assertSame(12345, (int) $zeile['tstamp']);
	}

	/**
	 * Aktive Dokumentarten in der eingestellten Reihenfolge.
	 *
	 * Die Sortierung lautet „Reihenfolge, dann Name“. Damit ein Fehler in
	 * einer der beiden Hälften auffällt, weicht die erwartete Reihenfolge
	 * sowohl von der Anlegereihenfolge (ID) als auch von der alphabetischen ab:
	 * „Ehrenkodex“ steht alphabetisch vor „Führungszeugnis“, hat aber die
	 * größere Reihenfolge. „Anlage“ und „Schulungsnachweis“ teilen sich die
	 * Reihenfolge 3; hier entscheidet der Name, obwohl „Anlage“ später
	 * angelegt wurde. Die inaktive Art „Alt“ fehlt in der Liste, ist aber
	 * über eine() abrufbar.
	 */
	public function testDokumentarten(): void
	{
		$repo = new DokumentartRepository($this->db);
		$schulung = Testdatenbank::dokumentart($this->db, 'Schulungsnachweis', 0, false, true, 3);
		$efz = Testdatenbank::dokumentart($this->db, 'Führungszeugnis', 24, true, true, 1);
		$alt = Testdatenbank::dokumentart($this->db, 'Alt', 0, false, true, 2);
		$this->db->update('tl_kinderschutz_dokumentart', array('published' => ''), array('id' => $alt));
		$kodex = Testdatenbank::dokumentart($this->db, 'Ehrenkodex', 0, false, true, 2);
		$anlage = Testdatenbank::dokumentart($this->db, 'Anlage', 0, false, false, 3);

		$this->assertSame(array($efz, $kodex, $anlage, $schulung), array_map(static fn ($art) => $art->id, $repo->aktive()));
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
	 * Mehrere Gruppen gelten als „oder“: Wer in einer von ihnen ist, kommt mit.
	 *
	 * Würden die Gruppen mit „und“ verknüpft, blieben nur Mitglieder übrig, die
	 * in allen gewählten Gruppen sind. Deshalb gehören Anton und Berta je nur
	 * einer der beiden Gruppen an, Carla beiden und Dora einer dritten, die
	 * nicht gesucht wird. Die Einzelabfragen zeigen, dass jede Gruppe für sich
	 * die richtigen Mitglieder liefert und eine doppelt genannte Gruppe nichts
	 * verdoppelt.
	 */
	public function testMitgliederInMehrerenGruppen(): void
	{
		$repo = new PersonenRepository($this->db);
		$anton = Testdatenbank::mitglied($this->db, 'Anton', 'Adler', array(1));
		$berta = Testdatenbank::mitglied($this->db, 'Berta', 'Bauer', array(2));
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Conrad', array(1, 2));
		Testdatenbank::mitglied($this->db, 'Dora', 'Dachs', array(3));

		$this->assertSame(array($anton, $berta, $carla), array_column($repo->mitgliederInGruppen(array(1, 2), $this->jetzt), 'id'));
		$this->assertSame(array($anton, $carla), array_column($repo->mitgliederInGruppen(array(1), $this->jetzt), 'id'));
		$this->assertSame(array($berta, $carla), array_column($repo->mitgliederInGruppen(array(2), $this->jetzt), 'id'));
		$this->assertSame(array($anton, $carla), array_column($repo->mitgliederInGruppen(array(1, 1), $this->jetzt), 'id'));
	}

	/**
	 * Die Mitglieder kommen nach Nachname, bei gleichem Nachnamen nach Vorname.
	 *
	 * Die Mitglieder werden absichtlich quer zur erwarteten Reihenfolge
	 * angelegt, sonst bliebe unbemerkt, wenn nur nach einer der beiden Spalten
	 * (oder nur nach der ID) sortiert würde: Zoe Meier ist vor Anna Meier
	 * angelegt, Carl Adler als Letzter, obwohl er alphabetisch zuerst kommt.
	 */
	public function testMitgliederSortierung(): void
	{
		$repo = new PersonenRepository($this->db);
		$zoe = Testdatenbank::mitglied($this->db, 'Zoe', 'Meier');
		$anna = Testdatenbank::mitglied($this->db, 'Anna', 'Meier');
		$bert = Testdatenbank::mitglied($this->db, 'Bert', 'Zorn');
		$carl = Testdatenbank::mitglied($this->db, 'Carl', 'Adler');

		$this->assertSame(array($carl, $anna, $zoe, $bert), array_column($repo->mitgliederInGruppen(array(1), $this->jetzt), 'id'));
	}

	/**
	 * Die Grenzen von „Aktivieren am“ und „Deaktivieren am“ gelten auf die Sekunde.
	 *
	 * Wie bei der Contao-Anmeldung ist ein Mitglied ab dem Startzeitpunkt aktiv
	 * (start == jetzt zählt schon), aber ab dem Stoppzeitpunkt nicht mehr
	 * (stop == jetzt zählt nicht mehr). Je eine Sekunde daneben gilt die
	 * jeweils andere Seite. Beide Abfragen, die Liste und die Einzelprüfung,
	 * müssen dieselbe Antwort geben.
	 */
	public function testAktivitaetGrenzen(): void
	{
		$repo = new PersonenRepository($this->db);
		$anfang = Testdatenbank::mitglied($this->db, 'Anna', 'Anfang', array(1), array('start' => (string) $this->jetzt));
		$bald = Testdatenbank::mitglied($this->db, 'Bernd', 'Bald', array(1), array('start' => (string) ($this->jetzt + 1)));
		$ende = Testdatenbank::mitglied($this->db, 'Carla', 'Ende', array(1), array('stop' => (string) $this->jetzt));
		$danach = Testdatenbank::mitglied($this->db, 'Dirk', 'Danach', array(1), array('stop' => (string) ($this->jetzt + 1)));

		$this->assertSame(array($anfang, $danach), array_column($repo->mitgliederInGruppen(array(1), $this->jetzt), 'id'));
		$this->assertTrue($repo->istInGruppen($anfang, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen($bald, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen($ende, array(1), $this->jetzt));
		$this->assertTrue($repo->istInGruppen($danach, array(1), $this->jetzt));
	}

	/**
	 * Die Einzelprüfung verneint gesperrte, unbekannte und gruppenlose Fälle.
	 *
	 * istInGruppen() entscheidet, ob jemand kinderschutzpflichtig ist. Ein
	 * gesperrtes Mitglied in der richtigen Gruppe darf deshalb nicht als
	 * pflichtig gelten, genauso wenig eine ID, die es nicht (mehr) gibt, oder
	 * eine leere Gruppenliste. Zur Kontrolle bleibt das freie Mitglied
	 * pflichtig.
	 */
	public function testIstInGruppenVerneint(): void
	{
		$repo = new PersonenRepository($this->db);
		$frei = Testdatenbank::mitglied($this->db, 'Frei', 'Fuchs', array(1));
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gesperrt', 'Gans', array(1), array('disable' => '1'));

		$this->assertTrue($repo->istInGruppen($frei, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen($gesperrt, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen(999, array(1), $this->jetzt));
		$this->assertFalse($repo->istInGruppen($frei, array(), $this->jetzt));
	}

	/**
	 * Ein einzelnes Mitglied wird auch geliefert, wenn es gesperrt ist.
	 *
	 * mitglied() dient der Anzeige, etwa neben einem Nachweis im Backend. Der
	 * Prüfer muss den Namen auch dann sehen, wenn das Konto inzwischen
	 * gesperrt oder abgelaufen ist. Nur bei einer gelöschten ID kommt null.
	 */
	public function testMitgliedAuchGesperrt(): void
	{
		$repo = new PersonenRepository($this->db);
		$gesperrt = Testdatenbank::mitglied($this->db, 'Gesperrt', 'Gans', array(1), array('disable' => '1'));
		$vorbei = Testdatenbank::mitglied($this->db, 'Vorbei', 'Vogel', array(1), array('stop' => (string) ($this->jetzt - 1)));

		$this->assertSame(
			array('id' => $gesperrt, 'firstname' => 'Gesperrt', 'lastname' => 'Gans', 'email' => 'gesperrt.gans@example.org'),
			$repo->mitglied($gesperrt)
		);
		$this->assertSame('Vogel, Vorbei', PersonenRepository::name($repo->mitglied($vorbei)));
		$this->assertNull($repo->mitglied($vorbei + 1));
	}

	/**
	 * Hauptadmin und freigegebene, nicht gesperrte Benutzer bekommen Mails.
	 *
	 * Benutzer ohne E-Mail-Adresse (leeres Feld) werden übergangen, sonst
	 * ginge eine Mail an die leere Adresse. Das gilt auch für einen
	 * Hauptadmin ohne Adresse.
	 */
	public function testPrueferAdressen(): void
	{
		$this->db->insert('tl_user', array('name' => 'Haupt', 'email' => 'haupt@example.org'));
		$this->db->insert('tl_user', array('name' => 'Astrid', 'email' => 'astrid@example.org', 'kinderschutzPruefer' => '1'));
		$this->db->insert('tl_user', array('name' => 'Admin', 'email' => 'admin@example.org', 'admin' => '1'));
		$this->db->insert('tl_user', array('name' => 'Gesperrt', 'email' => 'weg@example.org', 'kinderschutzPruefer' => '1', 'disable' => '1'));
		$this->db->insert('tl_user', array('name' => 'Fünf', 'email' => 'fuenf@example.org', 'kinderschutzPruefer' => '1', 'disable' => '0'));
		$this->db->insert('tl_user', array('name' => 'Ohne Adresse', 'email' => '', 'kinderschutzPruefer' => '1'));

		$repo = new PersonenRepository($this->db);

		$this->assertSame(array('haupt@example.org', 'astrid@example.org', 'fuenf@example.org'), $repo->prueferAdressen(1));
		// Der Hauptadmin ohne Adresse bleibt ebenfalls draußen
		$this->assertSame(array('astrid@example.org', 'fuenf@example.org'), $repo->prueferAdressen(6));
	}

	/**
	 * Der Name eines Backend-Benutzers, ersatzweise „#ID“ bei gelöschten.
	 *
	 * Die Nachweisliste zeigt, wer geprüft hat. Ist dieser Benutzer später
	 * gelöscht worden, steht dort die ID statt eines leeren Feldes.
	 */
	public function testBenutzerName(): void
	{
		$this->db->insert('tl_user', array('name' => 'Astrid Prüferin', 'email' => 'astrid@example.org'));
		$id = (int) $this->db->lastInsertId();
		$repo = new PersonenRepository($this->db);

		$this->assertSame('Astrid Prüferin', $repo->benutzerName($id));
		$this->assertSame('#999', $repo->benutzerName(999));
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
	 * Eine bestehende Teilnahme behält beim erneuten Anmelden ihre Herkunft.
	 *
	 * Hat der Prüfer ein Mitglied im Backend eingetragen, darf dessen
	 * Anmeldung im Frontend die Teilnahme nicht in „frontend“ umschreiben:
	 * Sonst könnte sich das Mitglied danach selbst abmelden, obwohl die
	 * Teilnahme vom Prüfer stammt. Auch der Anlagezeitpunkt bleibt erhalten,
	 * und es entsteht keine zweite Zeile.
	 */
	public function testAnmeldenBehaeltHerkunft(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$heute = strtotime('2026-10-15');
		$jwm = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', '2026-11-02', '2026-11-12');
		$repo->setzeTeilnehmer($jwm, array(6), $this->jetzt);

		$this->assertTrue($repo->anmelden($jwm, 6, $heute, $this->jetzt + 60));

		$zeile = $this->db->fetchAssociative('SELECT quelle, angelegtAm FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ? AND member = ?', array($jwm, 6));
		$this->assertSame(VeranstaltungRepository::QUELLE_BACKEND, $zeile['quelle']);
		$this->assertSame($this->jetzt, (int) $zeile['angelegtAm']);
		$this->assertSame(array(6), $repo->teilnehmer($jwm));
		$this->assertFalse($repo->abmelden($jwm, 6));
		$this->assertSame(array(6), $repo->teilnehmer($jwm));
	}

	/**
	 * Alle Veranstaltungen kommen mit der jüngsten zuerst.
	 *
	 * Sortiert wird nach Beginn absteigend, bei gleichem Beginn nach der ID
	 * absteigend, so dass die zuletzt angelegte zuerst steht. Die Daten sind
	 * deshalb weder in der Reihenfolge des Beginns noch in der umgekehrten
	 * angelegt, und „Neu B“ teilt sich den Beginn mit „Neu A“.
	 */
	public function testAlleJuengsteZuerst(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		Testdatenbank::veranstaltung($this->db, 'Alt', '2026-01-10');
		Testdatenbank::veranstaltung($this->db, 'Neu A', '2026-12-01');
		Testdatenbank::veranstaltung($this->db, 'Mitte', '2026-06-01');
		Testdatenbank::veranstaltung($this->db, 'Neu B', '2026-12-01');

		$this->assertSame(array('Neu B', 'Neu A', 'Mitte', 'Alt'), array_column($repo->alle(), 'name'));
	}

	/**
	 * Unfertige Entwürfe fehlen in der Liste aller Veranstaltungen.
	 *
	 * „Neu“ legt den Datensatz sofort an, ohne Beginn und mit tstamp 0.
	 * Verlässt der Prüfer die Maske ohne Speichern und ohne „Zurück“, bleibt
	 * er liegen (Contao 4.13 räumt ihn nur über „Zurück“ ab). Im Prüfstand
	 * 4.13 erschien er in der Auswahl der Übersicht als „(01.01.1970)“.
	 */
	public function testAlleOhneUnfertigeEntwuerfe(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		Testdatenbank::veranstaltung($this->db, 'JWM', '2026-11-02');
		$this->db->insert('tl_kinderschutz_veranstaltung', array('tstamp' => 0, 'name' => '', 'startDate' => null));

		$this->assertSame(array('JWM'), array_column($repo->alle(), 'name'));
	}

	/**
	 * Wählbar sind nur offene, nicht beendete Veranstaltungen; laufende zählen noch.
	 *
	 * Die Liste ist nach Beginn aufsteigend sortiert, nicht nach Anlegereihenfolge:
	 * „Kommend“ wird deshalb vor „Laufend“ angelegt, beginnt aber später. Das
	 * gilt ebenso für die Veranstaltungen eines Mitglieds, die zusätzlich ihre
	 * Herkunft („frontend“ oder „backend“) mitliefern.
	 */
	public function testWaehlbareUndEigene(): void
	{
		$repo = new VeranstaltungRepository($this->db);
		$heute = strtotime('2026-10-15');
		$kommend = Testdatenbank::veranstaltung($this->db, 'Kommend', '2026-11-02');
		$laufend = Testdatenbank::veranstaltung($this->db, 'Laufend', '2026-10-10', '2026-10-15');
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
