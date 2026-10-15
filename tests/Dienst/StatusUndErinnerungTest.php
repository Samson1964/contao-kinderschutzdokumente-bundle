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

		$this->assertSame(0, $erinnerungen->ablaufVersenden($this->jetzt + 86400));
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
		$this->assertSame(0, $erinnerungen->veranstaltungenVersenden($this->jetzt + 86400));
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
			'geprueftAm'        => strtotime($ausgestellt) + 86400,
			'einschlaegig'      => 'nein',
			'gueltigBis'        => null === $gueltigBis ? null : strtotime($gueltigBis.' 00:00'),
		));
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
			new Benachrichtigung($this->mailer(), $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger()),
		);
	}
}
