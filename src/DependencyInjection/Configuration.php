<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Die Bundle-Parameter unter „contao_kinderschutzdokumente:“ in
 * config/config.yaml.
 *
 * Sie stehen bewusst nicht in der Oberfläche: Wer den Hauptadmin oder den
 * Ablageort ändern kann, kontrolliert den Zugang zu den Führungszeugnissen.
 */
class Configuration implements ConfigurationInterface
{
	/**
	 * Beschreibt die erlaubten Schlüssel und ihre Vorgaben.
	 *
	 * @return TreeBuilder Der Konfigurationsbaum
	 */
	public function getConfigTreeBuilder(): TreeBuilder
	{
		$baum = new TreeBuilder('contao_kinderschutzdokumente');

		$baum->getRootNode()
			->children()
				->integerNode('hauptadmin')
					->info('ID des Hauptadmins in tl_user; er sieht alles und vergibt die Prüferfreigaben.')
					->defaultValue(1)
					->min(1)
				->end()
				->scalarNode('speicherpfad')
					->info('Ablageort der hochgeladenen Dateien, außerhalb des Webroots.')
					->defaultValue('%kernel.project_dir%/var/kinderschutz')
					->cannotBeEmpty()
				->end()
				->integerNode('upload_max_mb')
					->info('Größte zulässige Datei in Megabyte; die php.ini kann weiter begrenzen.')
					->defaultValue(10)
					->min(1)
				->end()
			->end();

		return $baum;
	}
}
