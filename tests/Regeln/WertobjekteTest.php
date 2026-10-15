<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Regeln;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;

/**
 * Prüft die Umwandlung von Datenbankzeilen in die Wertobjekte Nachweis und
 * Dokumentart.
 *
 * MySQL liefert alle Spalten als Zeichenketten und fehlende Werte als NULL.
 * Die Tests arbeiten deshalb mit genau solchen Zeilen und vergleichen
 * streng (assertSame), damit auch der Typ stimmen muss.
 */
class WertobjekteTest extends TestCase
{
	/**
	 * Eine vollständige Zeile aus tl_kinderschutz ergibt einen Nachweis mit
	 * den richtigen Typen und der richtigen Zuordnung der Spalten.
	 *
	 * Jedes Zeitfeld bekommt einen eigenen Wert, damit eine Verwechslung der
	 * Spalten auffällt. Zusätzliche Spalten wie tstamp oder bemerkung
	 * gehören nicht zum Wertobjekt und dürfen nicht stören.
	 */
	public function testNachweisAusVollstaendigerZeile(): void
	{
		$nachweis = Nachweis::ausZeile(array(
			'id'                => '12',
			'tstamp'            => '1760300000',
			'member'            => '5',
			'dokumentart'       => '10',
			'status'            => 'geprueft',
			'ausstellungsdatum' => '1760000000',
			'hochgeladenAm'     => '1760100000',
			'geprueftAm'        => '1760200000',
			'einschlaegig'      => 'nein',
			'gueltigBis'        => '1823000000',
			'ablehnungsgrund'   => 'nicht lesbar',
			'bemerkung'         => 'wird ignoriert',
			'erinnertVorAblauf' => '1790000000',
			'erinnertAblauf'    => '1795000000',
		));

		$this->assertSame(12, $nachweis->id);
		$this->assertSame(5, $nachweis->member);
		$this->assertSame(10, $nachweis->dokumentart);
		$this->assertSame(Nachweis::GEPRUEFT, $nachweis->status);
		$this->assertSame(1760000000, $nachweis->ausstellungsdatum);
		$this->assertSame(1760100000, $nachweis->hochgeladenAm);
		$this->assertSame(1760200000, $nachweis->geprueftAm);
		$this->assertSame('nein', $nachweis->einschlaegig);
		$this->assertSame(1823000000, $nachweis->gueltigBis);
		$this->assertSame('nicht lesbar', $nachweis->ablehnungsgrund);
		$this->assertSame(1790000000, $nachweis->erinnertVorAblauf);
		$this->assertSame(1795000000, $nachweis->erinnertAblauf);
	}

	/**
	 * Ein gueltigBis von NULL bedeutet „unbegrenzt“ und bleibt null; es darf
	 * nicht zu 0 (1. Januar 1970) werden, sonst wäre jeder unbegrenzte
	 * Nachweis sofort abgelaufen.
	 *
	 * Ein fehlender Schlüssel verhält sich wie NULL, ein Zeitstempel als
	 * Zeichenkette wird zur Zahl.
	 */
	public function testNachweisGueltigBisNullBleibtNull(): void
	{
		$basis = array('id' => '1', 'member' => '5', 'dokumentart' => '10', 'status' => 'geprueft');

		$mitNull = Nachweis::ausZeile($basis + array('gueltigBis' => null));
		$ohneSpalte = Nachweis::ausZeile($basis);
		$mitWert = Nachweis::ausZeile($basis + array('gueltigBis' => '1760000000'));

		$this->assertNull($mitNull->gueltigBis);
		$this->assertNull($ohneSpalte->gueltigBis);
		$this->assertSame(1760000000, $mitWert->gueltigBis);
	}

	/**
	 * Fehlen die optionalen Spalten, gelten die Vorgaben: Zeitfelder 0,
	 * Texte leer, keine Erinnerung versandt.
	 *
	 * Wichtig für frisch angelegte Zeilen, in denen Contao nicht jede
	 * Spalte füllt.
	 */
	public function testNachweisOhneOptionaleSpaltenNutztVorgaben(): void
	{
		$nachweis = Nachweis::ausZeile(array(
			'id'          => '3',
			'member'      => '7',
			'dokumentart' => '11',
			'status'      => 'eingereicht',
		));

		$this->assertSame(3, $nachweis->id);
		$this->assertSame(7, $nachweis->member);
		$this->assertSame(11, $nachweis->dokumentart);
		$this->assertSame(Nachweis::EINGEREICHT, $nachweis->status);
		$this->assertSame(0, $nachweis->ausstellungsdatum);
		$this->assertSame(0, $nachweis->hochgeladenAm);
		$this->assertSame(0, $nachweis->geprueftAm);
		$this->assertSame('', $nachweis->einschlaegig);
		$this->assertNull($nachweis->gueltigBis);
		$this->assertSame('', $nachweis->ablehnungsgrund);
		$this->assertSame(0, $nachweis->erinnertVorAblauf);
		$this->assertSame(0, $nachweis->erinnertAblauf);
	}

	/**
	 * Eine vollständige Zeile aus tl_kinderschutz_dokumentart ergibt eine
	 * Dokumentart mit richtigen Typen.
	 *
	 * Die Ja/Nein-Spalten des Bundles sind char(1) mit '1' für ja; die
	 * Gültigkeit in Monaten kommt als Zeichenkette und wird zur Zahl.
	 */
	public function testDokumentartAusVollstaendigerZeile(): void
	{
		$art = Dokumentart::ausZeile(array(
			'id'                     => '10',
			'name'                   => 'Erweitertes Führungszeugnis',
			'hinweis'                => 'Nicht älter als drei Monate',
			'pflicht'                => '1',
			'gueltigkeit'            => '24',
			'papierkorbNachPruefung' => '1',
			'einschlaegigAbfragen'   => '1',
		));

		$this->assertSame(10, $art->id);
		$this->assertSame('Erweitertes Führungszeugnis', $art->name);
		$this->assertSame('Nicht älter als drei Monate', $art->hinweis);
		$this->assertTrue($art->pflicht);
		$this->assertSame(24, $art->gueltigkeit);
		$this->assertTrue($art->papierkorbNachPruefung);
		$this->assertTrue($art->einschlaegigAbfragen);
	}

	/**
	 * Jede der drei Ja/Nein-Spalten wird für sich ausgewertet: '1' ist ja,
	 * die leere Zeichenkette nein.
	 *
	 * Die Fälle schalten je eine Spalte ein und lassen die anderen aus, damit
	 * eine Verwechslung der Spalten nicht unbemerkt bleibt.
	 *
	 * @dataProvider jaNeinSpalten
	 *
	 * @param string $pflicht                Rohwert der Spalte pflicht
	 * @param string $papierkorbNachPruefung Rohwert der Spalte papierkorbNachPruefung
	 * @param string $einschlaegigAbfragen   Rohwert der Spalte einschlaegigAbfragen
	 * @param bool   $erwartetPflicht        Erwartete Auswertung von pflicht
	 * @param bool   $erwartetPapierkorb     Erwartete Auswertung von papierkorbNachPruefung
	 * @param bool   $erwartetEinschlaegig   Erwartete Auswertung von einschlaegigAbfragen
	 */
	public function testDokumentartJaNeinSpalten(string $pflicht, string $papierkorbNachPruefung, string $einschlaegigAbfragen, bool $erwartetPflicht, bool $erwartetPapierkorb, bool $erwartetEinschlaegig): void
	{
		$art = Dokumentart::ausZeile(array(
			'id'                     => '11',
			'name'                   => 'Ehrenkodex',
			'pflicht'                => $pflicht,
			'papierkorbNachPruefung' => $papierkorbNachPruefung,
			'einschlaegigAbfragen'   => $einschlaegigAbfragen,
		));

		$this->assertSame($erwartetPflicht, $art->pflicht);
		$this->assertSame($erwartetPapierkorb, $art->papierkorbNachPruefung);
		$this->assertSame($erwartetEinschlaegig, $art->einschlaegigAbfragen);
	}

	/**
	 * Liefert Rohwerte der drei Ja/Nein-Spalten und die erwartete Auswertung.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool}>
	 */
	public function jaNeinSpalten(): array
	{
		return array(
			'alle leer'          => array('', '', '', false, false, false),
			'alle gesetzt'       => array('1', '1', '1', true, true, true),
			'nur Pflicht'        => array('1', '', '', true, false, false),
			'nur Papierkorb'     => array('', '1', '', false, true, false),
			'nur einschlägig'    => array('', '', '1', false, false, true),
		);
	}

	/**
	 * Fehlen die optionalen Spalten der Dokumentart, gelten die Vorgaben:
	 * kein Hinweis, keine Pflicht, unbegrenzte Gültigkeit, kein Papierkorb,
	 * keine Abfrage einschlägiger Einträge.
	 *
	 * Auch NULL aus der Datenbank gilt als „nein“.
	 */
	public function testDokumentartOhneOptionaleSpaltenNutztVorgaben(): void
	{
		$ohneSpalten = Dokumentart::ausZeile(array('id' => '12', 'name' => 'Freiwillig'));
		$mitNull = Dokumentart::ausZeile(array(
			'id'                     => '12',
			'name'                   => 'Freiwillig',
			'hinweis'                => null,
			'pflicht'                => null,
			'gueltigkeit'            => null,
			'papierkorbNachPruefung' => null,
			'einschlaegigAbfragen'   => null,
		));

		foreach (array($ohneSpalten, $mitNull) as $art) {
			$this->assertSame(12, $art->id);
			$this->assertSame('Freiwillig', $art->name);
			$this->assertSame('', $art->hinweis);
			$this->assertFalse($art->pflicht);
			$this->assertSame(0, $art->gueltigkeit);
			$this->assertFalse($art->papierkorbNachPruefung);
			$this->assertFalse($art->einschlaegigAbfragen);
		}
	}
}
