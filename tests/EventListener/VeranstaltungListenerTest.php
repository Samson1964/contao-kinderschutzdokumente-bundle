<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\VeranstaltungListener;

/**
 * Prüft das virtuelle Feld „teilnehmer“ der Veranstaltungen.
 */
class VeranstaltungListenerTest extends TestCase
{
	/**
	 * Der load_callback liefert die Teilnehmer als Liste.
	 *
	 * „Mehrere bearbeiten → Überschreiben“ ruft den load_callback mit dem
	 * alten Wert als Array und mischt das Ergebnis per array_merge() mit der
	 * neuen Auswahl (DC_Table::save(), 4.13 wie 5.7). Lieferte der Callback
	 * eine serialisierte Zeichenkette, endete das im Prüfstand 5.7 mit
	 * „array_merge(): Argument #1 must be of type array, string given“.
	 */
	public function testLadenLiefertListeFuerUeberschreiben(): void
	{
		$alt = $this->listener(array(990097))->laden(array(), $this->dc(1));

		$this->assertSame(array('990097'), $alt);

		// So rechnet Contao im Modus „Ausgewählte Werte hinzufügen“
		$this->assertSame(array('990097', '990098'), array_values(array_unique(array_merge($alt, array('990098')))));
	}

	/**
	 * Ohne Teilnehmer kommt eine leere Liste.
	 */
	public function testLadenOhneTeilnehmer(): void
	{
		$this->assertSame(array(), $this->listener(array())->laden(null, $this->dc(2)));
	}

	/**
	 * Der save_callback schreibt die Auswahl zur Veranstaltung.
	 */
	public function testSpeichernSchreibtTeilnehmer(): void
	{
		$veranstaltungen = $this->createMock(VeranstaltungRepository::class);
		$veranstaltungen->expects($this->once())->method('setzeTeilnehmer')->with(2, array(990097, 990098), $this->isType('int'));

		$this->assertNull($this->listener(array(), $veranstaltungen)->speichern(serialize(array('990097', '990098')), $this->dc(2)));
	}

	/**
	 * Ohne Datensatz schreibt der save_callback nichts.
	 *
	 * Contao 4.13 baut in „Mehrere bearbeiten → Überschreiben“ nach dem
	 * Speichern aller Datensätze noch in derselben Anfrage das Formular neu
	 * auf, mit ID 0, und row() speichert dabei jedes Feld ein weiteres Mal
	 * (DC_Table::overrideAll(), erst danach folgt die Weiterleitung; 5.7
	 * leitet vorher weiter). Im Prüfstand 4.13 entstand so eine Teilnahme mit
	 * Veranstaltung 0.
	 */
	public function testSpeichernOhneDatensatzSchreibtNichts(): void
	{
		$veranstaltungen = $this->createMock(VeranstaltungRepository::class);
		$veranstaltungen->expects($this->never())->method('setzeTeilnehmer');

		$this->assertNull($this->listener(array(), $veranstaltungen)->speichern(serialize(array('990097')), $this->dc(0)));
	}

	/**
	 * Die Listenzeile nennt Zeitraum, Ort und Zahl der Teilnehmer.
	 */
	public function testBeschriftungMitZeitraumUndOrt(): void
	{
		$zeile = array('id' => 1, 'name' => '[Test] JWM', 'ort' => 'Heraklion', 'startDate' => mktime(0, 0, 0, 11, 15, 2026), 'endDate' => mktime(0, 0, 0, 11, 25, 2026));

		$this->assertSame('<strong>[Test] JWM</strong> <span class="tl_gray">(15.11.2026 – 25.11.2026, Heraklion) · 2 Teilnehmer</span>', $this->listener(array(990097, 990098))->beschriftung($zeile));
	}

	/**
	 * Ohne Beginn steht kein 01.01.1970 in der Liste.
	 *
	 * „Neu“ legt den Datensatz sofort an, Beginn und Ende sind NULL. Bleibt
	 * der Entwurf liegen (Maske ohne Speichern verlassen), zeigte die Liste im
	 * Prüfstand 4.13 „(01.01.1970) · 0 Teilnehmer“.
	 *
	 * @dataProvider entwuerfe
	 *
	 * @param array<string, mixed> $zeile    Der Datensatz
	 * @param string               $erwartet Die erwartete Beschriftung
	 */
	public function testBeschriftungOhneBeginn(array $zeile, string $erwartet): void
	{
		$this->assertSame($erwartet, $this->listener(array())->beschriftung($zeile + array('id' => 3, 'name' => '', 'startDate' => null, 'endDate' => null)));
	}

	/**
	 * Entwürfe ohne Beginn, mit und ohne Ort.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function entwuerfe(): array
	{
		return array(
			'leer'     => array(array('ort' => ''), '<strong></strong> <span class="tl_gray">· 0 Teilnehmer</span>'),
			'nur Ort'  => array(array('ort' => 'Berlin'), '<strong></strong> <span class="tl_gray">(Berlin) · 0 Teilnehmer</span>'),
			'Null-Tag' => array(array('ort' => '', 'startDate' => 0, 'endDate' => 0), '<strong></strong> <span class="tl_gray">· 0 Teilnehmer</span>'),
		);
	}

	/**
	 * Baut den Listener mit festen Teilnehmern.
	 *
	 * @param list<int>                    $teilnehmer      Mitglieds-IDs, die das Repository liefert
	 * @param VeranstaltungRepository|null $veranstaltungen Eigene Attrappe des
	 *                                                      Repositorys, etwa mit
	 *                                                      Erwartungen; sonst
	 *                                                      eine neue
	 *
	 * @return VeranstaltungListener Der Listener
	 */
	private function listener(array $teilnehmer, ?VeranstaltungRepository $veranstaltungen = null): VeranstaltungListener
	{
		$veranstaltungen ??= $this->createMock(VeranstaltungRepository::class);
		$veranstaltungen->method('teilnehmer')->willReturn($teilnehmer);

		return new VeranstaltungListener($veranstaltungen, $this->createMock(PersonenRepository::class), $this->createMock(Einstellungen::class));
	}

	/**
	 * Ein Data Container, der nur seine ID kennt.
	 *
	 * @param int $id ID der Veranstaltung
	 *
	 * @return DataContainer Die Attrappe
	 */
	private function dc(int $id): DataContainer
	{
		$dc = $this->createMock(DataContainer::class);
		$dc->method('__get')->willReturnMap(array(array('id', $id)));

		return $dc;
	}
}
