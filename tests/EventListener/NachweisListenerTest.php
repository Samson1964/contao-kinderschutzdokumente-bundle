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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Pruefung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\NachweisListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prüft, wie der NachweisListener die Liste als Papierkorb einrichtet.
 */
class NachweisListenerTest extends TestCase
{
	/**
	 * Lädt die DCA-Datei von tl_kinderschutz.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA'] = array();
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../../src/Resources/contao/dca/tl_kinderschutz.php';
	}

	/**
	 * Räumt die gesetzten Globalen wieder ab.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
	}

	/**
	 * Der Papierkorb gruppiert nach dem Tag, an dem die Datei hineinkam.
	 *
	 * Contao schreibt über jede Gruppe der Liste den Wert der ersten
	 * Sortierspalte. Mit einer reinen Aufsteigend-Sortierung stand dort der
	 * rohe Zeitstempel („1792096649“), im Prüfstand 5.7 gesehen. Erst eine
	 * Tagessortierung lässt Contao den Wert als Datum ausgeben.
	 */
	public function testPapierkorbGruppiertNachTag(): void
	{
		$this->listener('kinderschutz_papierkorb')->beimLaden();

		$sortierung = $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting'];
		$this->assertSame(array('papierkorbSeit'), $sortierung['fields']);
		$this->assertSame(DataContainer::SORT_DAY_ASC, $sortierung['flag']);
		$this->assertTrue($GLOBALS['TL_DCA']['tl_kinderschutz']['config']['notCopyable']);
	}

	/**
	 * Die Nachweisliste behält ihre Sortierung nach dem Zeitstempel.
	 */
	public function testNachweislisteBleibtUnveraendert(): void
	{
		$vorher = $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting'];

		$this->listener('kinderschutz_nachweise')->beimLaden();

		$this->assertSame($vorher, $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting']);
	}

	/**
	 * Baut den Listener für eine Anfrage an ein Backend-Modul.
	 *
	 * Alle Dienste sind Attrappen; die Zugangsprüfung lässt durch.
	 *
	 * @param string $do Wert von „do“
	 *
	 * @return NachweisListener Der Listener
	 */
	private function listener(string $do): NachweisListener
	{
		$anfragen = new RequestStack();
		$anfragen->push(new Request(array('do' => $do)));

		return new NachweisListener(
			$this->createMock(Zugang::class),
			$this->createMock(Pruefung::class),
			$this->createMock(NachweisRepository::class),
			$this->createMock(DokumentartRepository::class),
			$this->createMock(PersonenRepository::class),
			$this->createMock(Papierkorb::class),
			$this->createMock(Einstellungen::class),
			$this->createMock(UrlGeneratorInterface::class),
			$anfragen,
		);
	}
}
