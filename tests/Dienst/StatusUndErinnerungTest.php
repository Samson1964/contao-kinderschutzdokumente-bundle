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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Benachrichtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Erinnerungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Statusdienst;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;

/**
 * Prüft Matrix, Veranstaltungsstand im Frontend und die Erinnerungsmails.
 *
 * Ausgangslage am 15.10.2026: Anna ist vollständig (EFZ bis 2028, Kodex
 * unbegrenzt), Bernd hat nur ein EFZ, das am 30.11.2026 abläuft, und eine
 * wartende Kodex-Einreichung. Beide fahren zur JWM (02.–12.11.2026).
 */
class StatusUndErinnerungTest extends Testhilfen
{
	private Connection $db;

	private int $anna;

	private int $bernd;

	private int $efz;

	private int $kodex;

	private int $jwm;

	private int $jetzt;

	/**
	 * Legt die Ausgangslage an.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->db = Testdatenbank::verbindung();
		$this->jetzt = strtotime('2026-10-15 06:00');
		$this->anna = Testdatenbank::mitglied($this->db, 'Anna', 'Adler');
		$this->bernd = Testdatenbank::mitglied($this->db, 'Bernd', 'Bach');
		$this->efz = Testdatenbank::dokumentart($this->db, 'Führungszeugnis', 24, true, true, 1);
		$this->kodex = Testdatenbank::dokumentart($this->db, 'Ehrenkodex', 0, false, true, 2);

		$this->geprueft($this->anna, $this->efz, '2026-01-10', '2028-01-09');
		$this->geprueft($this->anna, $this->kodex, '2026-01-10', null);
		$this->geprueft($this->bernd, $this->efz, '2024-12-01', '2026-11-30');
		Testdatenbank::nachweis($this->db, array('member' => $this->bernd, 'dokumentart' => $this->kodex, 'status' => Nachweis::EINGEREICHT));

		$this->jwm = Testdatenbank::veranstaltung($this->db, 'JWM Kreta 11/2026', '2026-11-02', '2026-11-12');
		(new VeranstaltungRepository($this->db))->setzeTeilnehmer($this->jwm, array($this->bernd, $this->anna), $this->jetzt);
	}

	/**
	 * Matrix ohne Veranstaltung: Gruppenmitglieder, Stichtag heute, Zähler.
	 */
	public function testMatrixHeute(): void
	{
		$matrix = $this->statusdienst()->matrix(null, $this->jetzt);

		$this->assertSame(strtotime('2026-10-15 00:00'), $matrix['stichtag']);
		$this->assertSame(array('Führungszeugnis', 'Ehrenkodex'), array_map(static fn ($art) => $art->name, $matrix['arten']));
		$this->assertSame(array('Adler', 'Bach'), array_map(static fn ($zeile) => $zeile['person']['lastname'], $matrix['zeilen']));

		$bernd = $matrix['zeilen'][1];
		$this->assertSame(Zellstatus::LAEUFT_AB, $bernd['zellen'][$this->efz]->status);
		$this->assertSame(Zellstatus::EINGEREICHT, $bernd['zellen'][$this->kodex]->status);
		$this->assertFalse($bernd['vollstaendig']);
		$this->assertTrue($bernd['wartet']);
		$this->assertSame(array('vollstaendig' => 1, 'unvollstaendig' => 1, 'wartend' => 1), $matrix['zaehler']);
	}

	/**
	 * Matrix einer Veranstaltung: Teilnehmer, Stichtag letzter Tag, gelöschtes Mitglied.
	 */
	public function testMatrixVeranstaltung(): void
	{
		(new VeranstaltungRepository($this->db))->setzeTeilnehmer($this->jwm, array($this->anna, $this->bernd, 999), $this->jetzt);

		$matrix = $this->statusdienst()->matrix($this->jwm, $this->jetzt);

		$this->assertSame(strtotime('2026-11-12 00:00'), $matrix['stichtag']);
		$this->assertSame('JWM Kreta 11/2026', $matrix['veranstaltung']['name']);
		$this->assertSame(array(999, $this->anna, $this->bernd), array_map(static fn ($zeile) => $zeile['person']['id'], $matrix['zeilen']));
		$this->assertTrue($matrix['zeilen'][0]['geloescht']);
		$this->assertFalse($matrix['zeilen'][1]['geloescht']);
	}

	/**
	 * Frontend: je Veranstaltung die fehlenden Pflichtarten.
	 */
	public function testVeranstaltungenFuerMitglied(): void
	{
		$heute = strtotime('2026-10-15');

		$bernd = $this->statusdienst()->veranstaltungenFuerMitglied($this->bernd, $heute);
		$this->assertSame('JWM Kreta 11/2026', $bernd[0]['veranstaltung']['name']);
		$this->assertSame(array('Ehrenkodex'), array_map(static fn ($art) => $art->name, $bernd[0]['fehlend']));

		$anna = $this->statusdienst()->veranstaltungenFuerMitglied($this->anna, $heute);
		$this->assertSame(array(), $anna[0]['fehlend']);
	}

	/**
	 * Bernds wartende Kodex-Einreichung verhindert keine EFZ-Erinnerung; die
	 * Erinnerung geht genau einmal hinaus.
	 */
	public function testAblaufErinnerung(): void
	{
		$erinnerungen = $this->erinnerungen();

		$this->assertSame(1, $erinnerungen->ablaufVersenden($this->jetzt));
		$this->assertSame('Ihre Unterlage „Führungszeugnis“ läuft bald ab', $this->mails[0]->getSubject());
		$this->assertSame('bernd.bach@example.org', $this->mails[0]->getTo()[0]->getAddress());

		$this->assertSame(0, $erinnerungen->ablaufVersenden(Kalender::plusTage($this->jetzt, 1)));
		$this->assertSame(1, $erinnerungen->ablaufVersenden(strtotime('2026-12-01 06:00')));
		$this->assertSame('Ihre Unterlage „Führungszeugnis“ ist abgelaufen', $this->mails[1]->getSubject());
	}

	/**
	 * Veranstaltungsmail nur an Unvollständige, nur im Zeitfenster, nur einmal.
	 */
	public function testVeranstaltungsErinnerung(): void
	{
		$erinnerungen = $this->erinnerungen();

		$this->assertSame(0, $erinnerungen->veranstaltungenVersenden(strtotime('2026-09-20 06:00')));

		// Gesperrte Mitglieder bekommen keine Mail
		$this->db->update('tl_member', array('disable' => '1'), array('id' => $this->bernd));
		$this->assertSame(0, $erinnerungen->veranstaltungenVersenden($this->jetzt));
		$this->db->update('tl_member', array('disable' => ''), array('id' => $this->bernd));

		$this->assertSame(1, $erinnerungen->veranstaltungenVersenden($this->jetzt));
		$this->assertSame('Fehlende Unterlagen für „JWM Kreta 11/2026“', $this->mails[0]->getSubject());
		$this->assertStringContainsString('- Ehrenkodex', $this->mails[0]->getTextBody());
		$this->assertSame(0, $erinnerungen->veranstaltungenVersenden(Kalender::plusTage($this->jetzt, 1)));
	}

	/**
	 * Ohne Absender wird nichts vermerkt; der nächste Lauf versucht es erneut.
	 */
	public function testKeinMerkerOhneVersand(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = '';

		$this->assertSame(0, $this->erinnerungen()->ablaufVersenden($this->jetzt));
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT MAX(erinnertVorAblauf) FROM tl_kinderschutz'));

		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = 'kinderschutz@example.org';
		$this->assertSame(1, $this->erinnerungen()->ablaufVersenden($this->jetzt));
	}

	/**
	 * Läuft ein Führungszeugnis mitten in der Veranstaltung ab, fehlt es im
	 * Frontend schon heute: Maßgeblich ist der letzte Veranstaltungstag.
	 *
	 * Carlas EFZ gilt bis 07.11.2026, die JWM dauert vom 02. bis 12.11.2026.
	 * Heute und am Beginn wäre es noch gültig (Gegenprobe über fuerMitglied),
	 * erst am letzten Tag ist es abgelaufen. Der unbegrenzt gültige
	 * Ehrenkodex darf nicht als fehlend erscheinen.
	 */
	public function testAblaufMitteInDerVeranstaltungImFrontend(): void
	{
		$carla = $this->teilnehmerinMitEfzBis('2026-11-07');
		$heute = strtotime('2026-10-15');

		$this->assertTrue($this->statusdienst()->fuerMitglied($carla, $heute)[$this->efz]->istGueltig());
		$this->assertTrue($this->statusdienst()->fuerMitglied($carla, strtotime('2026-11-02'))[$this->efz]->istGueltig());
		$this->assertSame(Zellstatus::ABGELAUFEN, $this->statusdienst()->fuerMitglied($carla, strtotime('2026-11-12'))[$this->efz]->status);

		$stand = $this->statusdienst()->veranstaltungenFuerMitglied($carla, $heute);

		$this->assertCount(1, $stand);
		$this->assertSame(array('Führungszeugnis'), array_map(static fn ($art) => $art->name, $stand[0]['fehlend']));
	}

	/**
	 * Die Matrix der Veranstaltung rechnet die Zellen zum letzten Tag, die
	 * Matrix ohne Veranstaltung zu heute.
	 *
	 * Dasselbe Führungszeugnis (gültig bis 07.11.2026) ist in der
	 * Veranstaltungsmatrix „abgelaufen“ und macht Carla unvollständig, in der
	 * Matrix von heute dagegen „läuft bald ab“ und zählt noch als gültig.
	 * Geprüft werden die Zellen selbst, nicht nur der ausgewiesene Stichtag.
	 */
	public function testAblaufMitteInDerVeranstaltungInDerMatrix(): void
	{
		$this->teilnehmerinMitEfzBis('2026-11-07');

		$matrix = $this->statusdienst()->matrix($this->jwm, $this->jetzt);
		$zeilen = $this->zeilenNachNachname($matrix);

		$this->assertSame(Zellstatus::ABGELAUFEN, $zeilen['Clausen']['zellen'][$this->efz]->status);
		$this->assertSame(Zellstatus::GUELTIG, $zeilen['Clausen']['zellen'][$this->kodex]->status);
		$this->assertFalse($zeilen['Clausen']['vollstaendig']);
		$this->assertTrue($zeilen['Adler']['vollstaendig']);
		$this->assertSame(array('vollstaendig' => 1, 'unvollstaendig' => 1, 'wartend' => 0), $matrix['zaehler']);

		$heute = $this->zeilenNachNachname($this->statusdienst()->matrix(null, $this->jetzt));

		$this->assertSame(Zellstatus::LAEUFT_AB, $heute['Clausen']['zellen'][$this->efz]->status);
		$this->assertTrue($heute['Clausen']['vollstaendig']);
	}

	/**
	 * Die Veranstaltungsmail nennt ein Führungszeugnis, das mitten in der
	 * Veranstaltung abläuft, als fehlend – und sonst nichts.
	 *
	 * Heute wäre das EFZ noch gültig; die Mail muss trotzdem hinausgehen,
	 * damit Carla es rechtzeitig vor der Reise erneuert.
	 */
	public function testAblaufMitteInDerVeranstaltungInDerMail(): void
	{
		$this->teilnehmerinMitEfzBis('2026-11-07');

		$this->assertSame(1, $this->erinnerungen()->veranstaltungenVersenden($this->jetzt));
		$this->assertCount(1, $this->mails);
		$this->assertSame('carla.clausen@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertStringContainsString('- Führungszeugnis', $this->mails[0]->getTextBody());
		$this->assertStringNotContainsString('- Ehrenkodex', $this->mails[0]->getTextBody());
	}

	/**
	 * Das Führungszeugnis muss genau bis zum letzten Veranstaltungstag
	 * reichen: am 12.11. ist es gültig, am 11.11. fehlt es.
	 *
	 * Fängt eine Umsetzung ab, die den Stichtag um einen Tag verschiebt –
	 * etwa auf den Vortag des Endes oder auf den Tag danach.
	 */
	public function testEfzGiltBisZumLetztenVeranstaltungstag(): void
	{
		$carla = $this->teilnehmerinMitEfzBis('2026-11-12');
		$heute = strtotime('2026-10-15');

		$this->assertSame(array(), $this->statusdienst()->veranstaltungenFuerMitglied($carla, $heute)[0]['fehlend']);
		$this->assertSame(0, $this->erinnerungen()->veranstaltungenVersenden($this->jetzt));

		$this->db->update('tl_kinderschutz', array('gueltigBis' => strtotime('2026-11-11 00:00')), array('member' => $carla, 'dokumentart' => $this->efz));

		$this->assertSame(array('Führungszeugnis'), array_map(static fn ($art) => $art->name, $this->statusdienst()->veranstaltungenFuerMitglied($carla, $heute)[0]['fehlend']));
		$this->assertSame(1, $this->erinnerungen()->veranstaltungenVersenden($this->jetzt));
	}

	/**
	 * Die Zähler der Matrix zählen Personen und lassen sich nicht
	 * verwechseln; „wartend“ gilt auch neben einem gültigen Nachweis.
	 *
	 * Sechs Personen: Anna und Carla sind vollständig (2), Bernd, Dora, Emil
	 * und Fritz nicht (4). Auf Prüfung warten Bernd (Kodex), Carla (Erneuerung
	 * ihres gültigen EFZ) und Fritz (beide Arten, also zwei Zellen) – drei
	 * Personen, aber vier Zellen. Dora hat nichts abgegeben, Emils Kodex ist
	 * abgelehnt; beide warten nicht.
	 */
	public function testZaehlerZaehlenPersonenUndWartetNebenGueltigem(): void
	{
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Clausen');
		$this->geprueft($carla, $this->efz, '2026-01-10', '2028-01-09');
		$this->geprueft($carla, $this->kodex, '2026-01-10', null);
		Testdatenbank::nachweis($this->db, array('member' => $carla, 'dokumentart' => $this->efz, 'status' => Nachweis::EINGEREICHT));

		Testdatenbank::mitglied($this->db, 'Dora', 'Dahl');

		$emil = Testdatenbank::mitglied($this->db, 'Emil', 'Eder');
		$this->geprueft($emil, $this->efz, '2026-01-10', '2028-01-09');
		Testdatenbank::nachweis($this->db, array('member' => $emil, 'dokumentart' => $this->kodex, 'status' => Nachweis::ABGELEHNT));

		$fritz = Testdatenbank::mitglied($this->db, 'Fritz', 'Fuchs');
		Testdatenbank::nachweis($this->db, array('member' => $fritz, 'dokumentart' => $this->efz, 'status' => Nachweis::EINGEREICHT));
		Testdatenbank::nachweis($this->db, array('member' => $fritz, 'dokumentart' => $this->kodex, 'status' => Nachweis::EINGEREICHT));

		$matrix = $this->statusdienst()->matrix(null, $this->jetzt);
		$zeilen = $this->zeilenNachNachname($matrix);

		$this->assertSame(array('Adler', 'Bach', 'Clausen', 'Dahl', 'Eder', 'Fuchs'), array_keys($zeilen));
		$this->assertSame(array('vollstaendig' => 2, 'unvollstaendig' => 4, 'wartend' => 3), $matrix['zaehler']);

		$this->assertSame(
			array('Adler' => true, 'Bach' => false, 'Clausen' => true, 'Dahl' => false, 'Eder' => false, 'Fuchs' => false),
			array_map(static fn (array $zeile): bool => $zeile['vollstaendig'], $zeilen)
		);
		$this->assertSame(
			array('Adler' => false, 'Bach' => true, 'Clausen' => true, 'Dahl' => false, 'Eder' => false, 'Fuchs' => true),
			array_map(static fn (array $zeile): bool => $zeile['wartet'], $zeilen)
		);

		// Carlas EFZ ist gültig und trotzdem wartet eine Erneuerung
		$this->assertSame(Zellstatus::GUELTIG, $zeilen['Clausen']['zellen'][$this->efz]->status);
		$this->assertTrue($zeilen['Clausen']['zellen'][$this->efz]->wartet());
		$this->assertSame(Zellstatus::ABGELEHNT, $zeilen['Eder']['zellen'][$this->kodex]->status);
		$this->assertSame(Zellstatus::FEHLT, $zeilen['Dahl']['zellen'][$this->efz]->status);
	}

	/**
	 * Die Erinnerungen eines Führungszeugnisses vermerken jeweils ihren
	 * eigenen Merker und kommen jede nur einmal.
	 *
	 * Am 15.10. geht die Mail „läuft bald ab“ hinaus und setzt nur
	 * erinnertVorAblauf. Am 01.12. folgt die Ablaufmail und setzt nur
	 * erinnertAblauf; danach schickt auch ein zweiter Lauf am selben und am
	 * nächsten Tag nichts mehr.
	 */
	public function testAblaufmailsVermerkenIhrenMerkerUndKommenNurEinmal(): void
	{
		$erinnerungen = $this->erinnerungen();
		$ablauftag = strtotime('2026-12-01 06:00');

		$this->assertSame(1, $erinnerungen->ablaufVersenden($this->jetzt));
		$this->assertSame(array($this->jetzt, 0), $this->merkerDesEfz($this->bernd));
		$this->assertSame(0, $erinnerungen->ablaufVersenden($this->jetzt));

		$this->assertSame(1, $erinnerungen->ablaufVersenden($ablauftag));
		$this->assertSame('Ihre Unterlage „Führungszeugnis“ ist abgelaufen', $this->mails[1]->getSubject());
		$this->assertSame(array($this->jetzt, $ablauftag), $this->merkerDesEfz($this->bernd));

		$this->assertSame(0, $erinnerungen->ablaufVersenden($ablauftag));
		$this->assertSame(0, $erinnerungen->ablaufVersenden(strtotime('2026-12-02 06:00')));
		$this->assertCount(2, $this->mails);
	}

	/**
	 * Eine Ablaufmail geht nur bei tatsächlich abgelaufenem Zeugnis hinaus,
	 * auch wenn der Cronjob mitten am Tag läuft.
	 *
	 * Der 30.11.2026 ist der letzte gültige Tag. Ein Lauf um 06:00 liegt
	 * nach Mitternacht dieses Tages, darf ihn aber nicht schon für
	 * abgelaufen halten; deshalb wird der Zeitpunkt auf den Kalendertag
	 * gesetzt. Die Mail „läuft bald ab“ ist bereits verschickt, damit nur
	 * eine fälschlich ausgelöste Ablaufmail eine Zahl über 0 ergeben könnte.
	 */
	public function testAmLetztenGueltigenTagKommtKeineAblaufmail(): void
	{
		$erinnerungen = $this->erinnerungen();

		$this->assertSame(1, $erinnerungen->ablaufVersenden($this->jetzt));

		$this->assertSame(0, $erinnerungen->ablaufVersenden(strtotime('2026-11-30 06:00')));
		$this->assertSame(0, $erinnerungen->ablaufVersenden(strtotime('2026-11-30 23:59')));
		$this->assertCount(1, $this->mails);

		$this->assertSame(1, $erinnerungen->ablaufVersenden(strtotime('2026-12-01 00:00')));
		$this->assertSame('Ihre Unterlage „Führungszeugnis“ ist abgelaufen', $this->mails[1]->getSubject());
	}

	/**
	 * Scheitert der Versand der Veranstaltungsmail, bleibt der Merker leer
	 * und ein späterer Lauf holt die Mail nach.
	 *
	 * Ohne Absenderadresse kann nichts hinausgehen. Erst mit Absender
	 * verschickt der nächste Lauf die Mail und vermerkt sie – nur an Bernds
	 * Teilnahme, denn Anna fehlt nichts.
	 */
	public function testKeinVeranstaltungsmerkerOhneVersand(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = '';

		$this->assertSame(0, $this->erinnerungen()->veranstaltungenVersenden($this->jetzt));
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT MAX(erinnertAm) FROM tl_kinderschutz_teilnahme'));
		$this->assertSame(array(), $this->mails);

		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = 'kinderschutz@example.org';

		$this->assertSame(1, $this->erinnerungen()->veranstaltungenVersenden($this->jetzt));
		$this->assertCount(1, $this->mails);
		$this->assertSame($this->jetzt, (int) $this->db->fetchOne('SELECT erinnertAm FROM tl_kinderschutz_teilnahme WHERE member = ?', array($this->bernd)));
		$this->assertSame(0, (int) $this->db->fetchOne('SELECT erinnertAm FROM tl_kinderschutz_teilnahme WHERE member = ?', array($this->anna)));
	}

	/**
	 * Legt einen geprüften Nachweis an.
	 *
	 * @param int         $mitglied    ID des Mitglieds
	 * @param int         $art         ID der Dokumentart
	 * @param string      $ausgestellt Ausstellungsdatum JJJJ-MM-TT
	 * @param string|null $gueltigBis  Letzter gültiger Tag oder null
	 */
	private function geprueft(int $mitglied, int $art, string $ausgestellt, ?string $gueltigBis): void
	{
		Testdatenbank::nachweis($this->db, array(
			'member'            => $mitglied,
			'dokumentart'       => $art,
			'status'            => Nachweis::GEPRUEFT,
			'verarbeitet'       => Nachweis::GEPRUEFT,
			'ausstellungsdatum' => strtotime($ausgestellt),
			'geprueftAm'        => Kalender::plusTage(strtotime($ausgestellt), 1),
			'einschlaegig'      => 'nein',
			'gueltigBis'        => null === $gueltigBis ? null : strtotime($gueltigBis.' 00:00'),
		));
	}

	/**
	 * Legt Carla an, deren Führungszeugnis zu einem frei wählbaren Tag
	 * abläuft, und macht sie zusammen mit Anna zur einzigen Teilnehmerin der
	 * JWM.
	 *
	 * Bernd wird dabei aus der Veranstaltung genommen, damit er die Mails und
	 * Zähler der Szenarien nicht mitbestimmt. Der Ehrenkodex ist unbegrenzt
	 * gültig, es hängt also allein am Führungszeugnis, ob Carla vollständig ist.
	 *
	 * @param string $gueltigBis Letzter gültiger Tag des EFZ, JJJJ-MM-TT
	 *
	 * @return int Die ID von Carla
	 */
	private function teilnehmerinMitEfzBis(string $gueltigBis): int
	{
		$carla = Testdatenbank::mitglied($this->db, 'Carla', 'Clausen');
		$this->geprueft($carla, $this->efz, '2024-11-08', $gueltigBis);
		$this->geprueft($carla, $this->kodex, '2026-01-10', null);
		(new VeranstaltungRepository($this->db))->setzeTeilnehmer($this->jwm, array($this->anna, $carla), $this->jetzt);

		return $carla;
	}

	/**
	 * Ordnet die Zeilen einer Matrix ihrem Nachnamen zu.
	 *
	 * Alle Personen der Szenarien haben verschiedene Nachnamen, sodass die
	 * Tests einzelne Zeilen benennen können, statt auf Positionen zu zählen.
	 * Die Reihenfolge der Zeilen bleibt erhalten.
	 *
	 * @param array{zeilen: list<array{person: array{lastname: string}}>} $matrix Das Ergebnis von Statusdienst::matrix()
	 *
	 * @return array<string, array<string, mixed>> Die Zeilen, nach Nachname benannt
	 */
	private function zeilenNachNachname(array $matrix): array
	{
		$zeilen = array();

		foreach ($matrix['zeilen'] as $zeile) {
			$zeilen[$zeile['person']['lastname']] = $zeile;
		}

		return $zeilen;
	}

	/**
	 * Liest die beiden Versandmerker des Führungszeugnisses eines Mitglieds.
	 *
	 * @param int $mitglied ID des Mitglieds; es hat genau einen EFZ-Nachweis
	 *
	 * @return array{0: int, 1: int} erinnertVorAblauf und erinnertAblauf, jeweils
	 *                               0, wenn die Mail noch nicht verschickt wurde
	 */
	private function merkerDesEfz(int $mitglied): array
	{
		$zeile = $this->db->fetchAssociative('SELECT erinnertVorAblauf, erinnertAblauf FROM tl_kinderschutz WHERE member = ? AND dokumentart = ?', array($mitglied, $this->efz));

		return array((int) $zeile['erinnertVorAblauf'], (int) $zeile['erinnertAblauf']);
	}

	/**
	 * Der Statusdienst über der Testdatenbank.
	 *
	 * @return Statusdienst Der Dienst
	 */
	private function statusdienst(): Statusdienst
	{
		return new Statusdienst(new DokumentartRepository($this->db), new NachweisRepository($this->db), new PersonenRepository($this->db), new VeranstaltungRepository($this->db), $this->einstellungen());
	}

	/**
	 * Der Erinnerungsdienst mit sammelndem Mailer.
	 *
	 * @return Erinnerungen Der Dienst
	 */
	private function erinnerungen(): Erinnerungen
	{
		return new Erinnerungen(
			$this->statusdienst(),
			new NachweisRepository($this->db),
			new PersonenRepository($this->db),
			new VeranstaltungRepository($this->db),
			$this->einstellungen(),
			new Benachrichtigung($this->transport(), $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger()),
		);
	}
}
