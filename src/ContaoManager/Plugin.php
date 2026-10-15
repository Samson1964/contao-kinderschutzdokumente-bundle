<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\ContaoKinderschutzdokumenteBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Meldet das Bundle und seine Route beim Contao Manager an.
 */
class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
	/**
	 * Meldet das Bundle beim Kernel an.
	 *
	 * Das Bundle wird nach dem Contao-Core geladen, damit dessen DCA-Dateien
	 * (tl_user, tl_settings, tl_module) schon vorliegen, wenn das Bundle eigene
	 * Felder und Paletten ergänzt.
	 *
	 * @param ParserInterface $parser Wird nicht ausgewertet
	 *
	 * @return array<int, BundleConfig> Die Konfiguration dieses einen Bundles
	 */
	public function getBundles(ParserInterface $parser): array
	{
		return array(
			BundleConfig::create(ContaoKinderschutzdokumenteBundle::class)
				->setLoadAfter(array(ContaoCoreBundle::class)),
		);
	}

	/**
	 * Lädt die Route der Dateiausgabe im Backend.
	 *
	 * @param LoaderResolverInterface $resolver Findet den passenden Lader für YAML
	 * @param KernelInterface         $kernel   Wird nicht benötigt
	 *
	 * @return RouteCollection|null Die Routen aus Resources/config/routes.yaml,
	 *                              oder null, wenn kein YAML-Lader verfügbar ist
	 */
	public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
	{
		$datei = __DIR__.'/../Resources/config/routes.yaml';
		$loader = $resolver->resolve($datei);

		return false === $loader ? null : $loader->load($datei);
	}
}
