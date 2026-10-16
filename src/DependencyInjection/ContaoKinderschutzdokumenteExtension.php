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
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Lädt Parameter und Dienste des Bundles in den Container.
 *
 * Die Basisklasse liegt in Symfony 5.4 (Contao 4.13) wie in Symfony 7
 * (Contao 5.7) unter demselben Namensraum.
 */
class ContaoKinderschutzdokumenteExtension extends Extension implements PrependExtensionInterface
{
	/**
	 * Hängt das Stylesheet mit dem Symbol der Menügruppe an Contaos
	 * contao.backend.custom_css an.
	 *
	 * Die Menügruppe steht auf jeder Backend-Seite; das „stylesheet“ der
	 * Module in $GLOBALS['BE_MOD'] lädt Contao aber nur im jeweils offenen
	 * Modul. custom_css bindet BackendTemplate::addBackendConfig() dagegen
	 * überall ein, in Contao 4.13 wie in 5.7. Weil Symfony Listen beim
	 * Zusammenführen anhängt, bleiben eigene custom_css-Angaben des Projekts
	 * erhalten.
	 *
	 * @param ContainerBuilder $container Der Container; nur die Konfiguration
	 *                                    der Erweiterung „contao“ wird ergänzt
	 */
	public function prepend(ContainerBuilder $container): void
	{
		$container->prependExtensionConfig('contao', array(
			'backend' => array(
				'custom_css' => array('bundles/contaokinderschutzdokumente/menue.css'),
			),
		));
	}

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
