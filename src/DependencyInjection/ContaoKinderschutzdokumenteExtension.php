<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Lädt Parameter und Dienste des Bundles in den Container.
 *
 * Die Basisklasse liegt in Symfony 5.4 (Contao 4.13) wie in Symfony 7
 * (Contao 5.7) unter demselben Namensraum.
 */
class ContaoKinderschutzdokumenteExtension extends Extension
{
	/**
	 * Setzt die Parameter aus der Konfiguration und liest die services.yaml.
	 *
	 * @param array<int|string, mixed> $mergedConfig Die Konfigurationen aller
	 *                                               config.yaml-Dateien
	 * @param ContainerBuilder         $container    Der Container
	 */
	public function load(array $mergedConfig, ContainerBuilder $container): void
	{
		$konfiguration = $this->processConfiguration(new Configuration(), $mergedConfig);

		$container->setParameter('contao_kinderschutzdokumente.hauptadmin', $konfiguration['hauptadmin']);
		$container->setParameter('contao_kinderschutzdokumente.speicherpfad', $konfiguration['speicherpfad']);
		$container->setParameter('contao_kinderschutzdokumente.upload_max_mb', $konfiguration['upload_max_mb']);

		$loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
		$loader->load('services.yaml');
	}
}
