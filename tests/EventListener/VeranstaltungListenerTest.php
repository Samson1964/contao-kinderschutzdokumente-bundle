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
	 * Baut den Listener mit festen Teilnehmern.
	 *
	 * @param list<int> $teilnehmer Mitglieds-IDs, die das Repository liefert
	 *
	 * @return VeranstaltungListener Der Listener
	 */
	private function listener(array $teilnehmer): VeranstaltungListener
	{
		$veranstaltungen = $this->createMock(VeranstaltungRepository::class);
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
