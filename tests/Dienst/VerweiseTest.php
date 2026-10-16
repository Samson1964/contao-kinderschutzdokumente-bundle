<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Verweise;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prüft die Links der Mails: Unterlagenseite im Frontend, Übersicht im Backend.
 *
 * Statt Contao hochzufahren, liefert ein Framework-Mock den PageModel-Adapter;
 * die Seite selbst ist ein Mock ohne Konstruktor, dessen getAbsoluteUrl()
 * eine Adresse oder eine Ausnahme liefert.
 */
class VerweiseTest extends Testhilfen
{
	/**
	 * Eine nicht routbare Seite (etwa unveröffentlicht) ergibt einen leeren Link.
	 *
	 * PageModel::getAbsoluteUrl() wirft dann eine Ausnahme des Routing-
	 * Bausteins. Sie darf nicht bis in die Argumentliste der Mails und damit
	 * in Upload oder Cronjob durchschlagen.
	 */
	public function testNichtRoutbareSeiteErgibtLeerenLink(): void
	{
		$seite = $this->seite();
		$seite->method('getAbsoluteUrl')->willThrowException(new RouteNotFoundException('Keine Route für die Seite'));

		$this->assertSame('', $this->verweise7($seite)->unterlagenseite());
	}

	/**
	 * Eine erreichbare Seite liefert ihre absolute Adresse unverändert.
	 *
	 * Sichert ab, dass das Abfangen der Routing-Ausnahme nicht alles zu einem
	 * leeren Link macht.
	 */
	public function testErreichbareSeiteLiefertAdresse(): void
	{
		$seite = $this->seite();
		$seite->method('getAbsoluteUrl')->willReturn('https://example.org/unterlagen.html');

		$this->assertSame('https://example.org/unterlagen.html', $this->verweise7($seite)->unterlagenseite());
	}

	/**
	 * Ohne eingestellte oder ohne gefundene Seite ist der Link leer.
	 */
	public function testKeineSeiteErgibtLeerenLink(): void
	{
		// Einstellung leer: Das Framework wird gar nicht erst nach der Seite gefragt.
		$verweise = new Verweise($this->createMock(ContaoFramework::class), $this->createMock(UrlGeneratorInterface::class), $this->einstellungen());
		$this->assertSame('', $verweise->unterlagenseite());

		// Eingestellte Seite existiert nicht mehr: findByPk() liefert null.
		$this->assertSame('', $this->verweise7(null)->unterlagenseite());
	}

	/**
	 * Die Übersicht ist eine absolute Backend-Adresse, Parameter werden angehängt.
	 */
	public function testUebersichtMitFilter(): void
	{
		$router = $this->createMock(UrlGeneratorInterface::class);
		$router->expects($this->once())
			->method('generate')
			->with('contao_backend', array('do' => 'kinderschutz_uebersicht', 'filter' => 'wartend'), UrlGeneratorInterface::ABSOLUTE_URL)
			->willReturn('https://example.org/contao?do=kinderschutz_uebersicht&filter=wartend');

		$verweise = new Verweise($this->createMock(ContaoFramework::class), $router, $this->einstellungen());

		$this->assertSame('https://example.org/contao?do=kinderschutz_uebersicht&filter=wartend', $verweise->uebersicht(array('filter' => 'wartend')));
	}

	/**
	 * Der Link der Antragsmail führt auf die Liste der Anträge.
	 */
	public function testAntraege(): void
	{
		$router = $this->createMock(UrlGeneratorInterface::class);
		$router->expects($this->once())->method('generate')
			->with('contao_backend', array('do' => 'kinderschutz_antraege'), UrlGeneratorInterface::ABSOLUTE_URL)
			->willReturn('https://example.org/contao?do=kinderschutz_antraege');

		$verweise = new Verweise($this->createMock(ContaoFramework::class), $router, $this->einstellungen());

		$this->assertSame('https://example.org/contao?do=kinderschutz_antraege', $verweise->antraege());
	}

	/**
	 * Eine Seite ohne Konstruktor, deren getAbsoluteUrl() der Test festlegt.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject&PageModel Das Seitenmock
	 */
	private function seite()
	{
		return $this->getMockBuilder(PageModel::class)
			->disableOriginalConstructor()
			->onlyMethods(array('getAbsoluteUrl'))
			->getMock();
	}

	/**
	 * Verweise, die Seite 7 als Unterlagenseite kennen.
	 *
	 * Setzt kinderschutzSeite in der Konfiguration; setUp() räumt sie vor dem
	 * nächsten Test wieder weg.
	 *
	 * @param PageModel|null $seite Was findByPk(7) liefert; null für „Seite nicht gefunden“
	 *
	 * @return Verweise Die Verweise über einem Framework-Mock
	 */
	private function verweise7(?PageModel $seite): Verweise
	{
		$GLOBALS['TL_CONFIG']['kinderschutzSeite'] = '7';

		$adapter = $this->getMockBuilder(Adapter::class)
			->setConstructorArgs(array(PageModel::class))
			->addMethods(array('findByPk'))
			->getMock();
		$adapter->method('findByPk')->with(7)->willReturn($seite);

		$framework = $this->createMock(ContaoFramework::class);
		$framework->method('getAdapter')->with(PageModel::class)->willReturn($adapter);

		return new Verweise($framework, $this->createMock(UrlGeneratorInterface::class), $this->einstellungen());
	}
}
