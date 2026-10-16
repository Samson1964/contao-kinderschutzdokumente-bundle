<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\SammelbearbeitungListener;

/**
 * Prüft, dass die Listen der Kinderschutz-Module in beiden Fassungen
 * „Mehrere bearbeiten“ anbieten.
 */
class SammelbearbeitungListenerTest extends TestCase
{
	/** @var array<string, mixed>|null Gesicherter Inhalt von $GLOBALS['TL_DCA'] */
	private ?array $dca = null;

	/**
	 * Sichert $GLOBALS['TL_DCA'], damit der Test andere nicht beeinflusst.
	 */
	protected function setUp(): void
	{
		$this->dca = $GLOBALS['TL_DCA'] ?? null;
		$GLOBALS['TL_DCA'] = array();
	}

	/**
	 * Stellt $GLOBALS['TL_DCA'] wieder her.
	 */
	protected function tearDown(): void
	{
		if (null === $this->dca) {
			unset($GLOBALS['TL_DCA']);
		} else {
			$GLOBALS['TL_DCA'] = $this->dca;
		}
	}

	/**
	 * Fehlt „all“ (Contao 4.13), wird es ergänzt.
	 *
	 * Contao 4.13 zeigt eine globale Operation nur, wenn die DCA sie nennt;
	 * im Prüfstand 4.13 fehlte der Knopf „Mehrere bearbeiten“ deshalb in
	 * allen drei Listen.
	 *
	 * @dataProvider tabellen
	 *
	 * @param string $tabelle Name der Tabelle
	 */
	public function testErgaenztMehrereBearbeitenWennEsFehlt(string $tabelle): void
	{
		$GLOBALS['TL_DCA'][$tabelle] = array('list' => array('sorting' => array('mode' => 1)));

		(new SammelbearbeitungListener())->ergaenzen($tabelle);

		$alle = $GLOBALS['TL_DCA'][$tabelle]['list']['global_operations']['all'] ?? null;
		$this->assertIsArray($alle);
		$this->assertSame('act=select', $alle['href']);
		$this->assertSame('header_edit_all', $alle['class']);
	}

	/**
	 * Hat Contao „all“ schon ergänzt (5.7), bleibt dessen Eintrag unverändert.
	 *
	 * @dataProvider tabellen
	 *
	 * @param string $tabelle Name der Tabelle
	 */
	public function testBehaeltContaosEintrag(string $tabelle): void
	{
		$vorgabe = array('href' => 'act=select', 'prefetch' => true, 'class' => 'header_edit_all', 'attributes' => 'data-action="contao--scroll-offset#store" accesskey="e"', 'primary' => true);
		$GLOBALS['TL_DCA'][$tabelle]['list']['global_operations'] = array('all' => $vorgabe);

		(new SammelbearbeitungListener())->ergaenzen($tabelle);

		$this->assertSame(array('all' => $vorgabe), $GLOBALS['TL_DCA'][$tabelle]['list']['global_operations']);
	}

	/**
	 * Andere globale Operationen bleiben stehen.
	 */
	public function testBehaeltAndereOperationen(): void
	{
		$GLOBALS['TL_DCA']['tl_kinderschutz']['list']['global_operations'] = array('leeren' => array('href' => 'key=leeren'));

		(new SammelbearbeitungListener())->ergaenzen('tl_kinderschutz');

		$this->assertSame(array('leeren', 'all'), array_keys($GLOBALS['TL_DCA']['tl_kinderschutz']['list']['global_operations']));
	}

	/**
	 * Fremde und nicht geladene Tabellen bleiben unberührt.
	 */
	public function testFremdeTabellenBleibenUnberuehrt(): void
	{
		$GLOBALS['TL_DCA']['tl_news'] = array('list' => array('sorting' => array('mode' => 4)));

		$listener = new SammelbearbeitungListener();
		$listener->ergaenzen('tl_news');
		$listener->ergaenzen('tl_kinderschutz_teilnahme');
		$listener->ergaenzen('tl_kinderschutz');

		$this->assertSame(array('tl_news' => array('list' => array('sorting' => array('mode' => 4)))), $GLOBALS['TL_DCA']);
	}

	/**
	 * Die Tabellen mit eigenem Backend-Modul.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function tabellen(): array
	{
		return array(
			'Nachweise'       => array('tl_kinderschutz'),
			'Veranstaltungen' => array('tl_kinderschutz_veranstaltung'),
			'Dokumentarten'   => array('tl_kinderschutz_dokumentart'),
		);
	}
}
