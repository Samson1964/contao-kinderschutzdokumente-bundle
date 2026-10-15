<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\ContaoKinderschutzdokumenteBundle;
use Schachbulle\ContaoKinderschutzdokumenteBundle\ContaoManager\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\YamlFileLoader;

/**
 * Prüft die Anmeldung des Bundles und seiner Route beim Contao Manager.
 */
class PluginTest extends TestCase
{
	/**
	 * Das Bundle wird genau einmal und nach dem Contao-Core angemeldet.
	 */
	public function testBundleWirdNachDemCoreGeladen(): void
	{
		$bundles = (new Plugin())->getBundles($this->createMock(ParserInterface::class));

		$this->assertCount(1, $bundles);
		$this->assertSame(ContaoKinderschutzdokumenteBundle::class, $bundles[0]->getName());
		$this->assertSame(array(ContaoCoreBundle::class), $bundles[0]->getLoadAfter());
	}

	/**
	 * Die Datei-Route liegt im Backend-Bereich und nimmt nur Zahlen als ID.
	 */
	public function testDateiRoute(): void
	{
		$routen = (new Plugin())->getRouteCollection(new LoaderResolver(array(new YamlFileLoader(new FileLocator()))), $this->createMock(KernelInterface::class));
		$route = $routen->get('kinderschutz_datei');

		$this->assertSame('/%contao.backend.route_prefix%/kinderschutz/datei/{id}', '/'.ltrim($route->getPath(), '/'));
		$this->assertSame('backend', $route->getDefault('_scope'));
		$this->assertSame('\d+', $route->getRequirement('id'));
		$this->assertSame(array('GET'), $route->getMethods());
	}
}
