<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\DependencyInjection;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Configuration as ContaoKonfiguration;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\PapierkorbAktionen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\Uebersicht;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\UebersichtModul;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Controller\DateiController;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Controller\FrontendModule\UnterlagenController;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Cron\TaeglicherCron;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\DependencyInjection\ContaoKinderschutzdokumenteExtension;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Freischaltung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\AntragListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\EinstellungenListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\TabellenVoter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Prüft, dass sich alle Dienste des Bundles verdrahten lassen.
 *
 * Die Dienste, die Contao liefert, werden als synthetische Dienste
 * vorgegeben. Vor dem Kompilieren werden alle Dienste des Bundles öffentlich
 * gemacht; sonst entfernte Symfony unbenutzte private Dienste (Frontend-Modul,
 * Listener) und prüfte deren Autowiring gar nicht.
 */
class DiensteTest extends TestCase
{
	/**
	 * Alle Dienste lassen sich mit den Vorgaben verdrahten.
	 */
	public function testContainerLaesstSichKompilieren(): void
	{
		$container = $this->container(array());

		$this->assertTrue($container->getDefinition(DateiController::class)->isPublic());
		$this->assertTrue($container->getDefinition(PapierkorbAktionen::class)->isPublic());
		$this->assertTrue($container->getDefinition(Uebersicht::class)->isPublic());
		$this->assertSame(array(array('priority' => 200)), $container->getDefinition(TabellenVoter::class)->getTag('security.voter'));
		// Ausgeschlossene Einzeldateien stehen bis zum Kompilieren als „container.excluded“ da
		$this->assertTrue($container->getDefinition(UebersichtModul::class)->hasTag('container.excluded'));

		foreach ($container->getDefinitions() as $id => $definition) {
			if (str_starts_with($id, 'Schachbulle\\') && !$definition->hasTag('container.excluded')) {
				$definition->setPublic(true);
			}
		}

		$container->compile();

		$this->assertFalse($container->has(UebersichtModul::class));
		$this->assertFalse($container->has(Nachweis::class));

		$this->assertTrue($container->has(UnterlagenController::class));
		// Das Frontend-Modul nimmt Anträge an und zeigt den offenen bzw. letzten
		$argumente = array_map('strval', $container->getDefinition(UnterlagenController::class)->getArguments());
		$this->assertContains(Freischaltung::class, $argumente);
		$this->assertContains(AntragRepository::class, $argumente);
		$this->assertTrue($container->has(TaeglicherCron::class));
		// Die Maske „Anträge“ braucht acht Dienste, darunter den MeldungListener
		$this->assertTrue($container->has(AntragListener::class));
		// Die Einstellungsmaske braucht Zugang, Einstellungen und das Framework
		$this->assertTrue($container->has(EinstellungenListener::class));
		$this->assertSame(1, $container->getParameter('contao_kinderschutzdokumente.hauptadmin'));
		$this->assertSame(10, $container->getParameter('contao_kinderschutzdokumente.upload_max_mb'));
	}

	/**
	 * Eigene Werte aus der config.yaml kommen bei den Diensten an.
	 */
	public function testEigeneKonfiguration(): void
	{
		$container = $this->container(array(array('hauptadmin' => 5, 'speicherpfad' => '/srv/ablage', 'upload_max_mb' => 4)));

		$this->assertSame(array('%contao_kinderschutzdokumente.hauptadmin%'), $container->getDefinition(Berechtigung::class)->getArguments());
		$this->assertSame(5, $container->getParameter('contao_kinderschutzdokumente.hauptadmin'));
		$this->assertSame('/srv/ablage', $container->getParameter('contao_kinderschutzdokumente.speicherpfad'));
		$this->assertSame(array('%contao_kinderschutzdokumente.speicherpfad%'), $container->getDefinition(Dateiablage::class)->getArguments());
		$this->assertSame(4, $container->getParameter('contao_kinderschutzdokumente.upload_max_mb'));
	}

	/**
	 * Das Symbol der Menügruppe kommt über contao.backend.custom_css auf jede
	 * Backend-Seite, und Contaos Konfigurationsbaum nimmt den Eintrag an.
	 *
	 * Geprüft wird gegen den Baum des installierten Kerns (Contao 5.7); der
	 * Schlüssel backend.custom_css steht in 4.13 gleich aufgebaut in
	 * Configuration.php. Eine eigene custom_css-Angabe des Projekts geht
	 * dabei nicht verloren: Listen werden beim Zusammenführen angehängt.
	 */
	public function testMenueSymbolAufJederBackendSeite(): void
	{
		$container = new ContainerBuilder();
		(new ContaoKinderschutzdokumenteExtension())->prepend($container);

		$stil = 'bundles/contaokinderschutzdokumente/menue.css';
		$this->assertSame(array(array('backend' => array('custom_css' => array($stil)))), $container->getExtensionConfig('contao'));

		$kern = (new Processor())->processConfiguration(
			new ContaoKonfiguration(\dirname(__DIR__, 2)),
			array(array('backend' => array('custom_css' => array('files/eigenes.css'))), $container->getExtensionConfig('contao')[0]),
		);
		$this->assertSame(array('files/eigenes.css', $stil), $kern['backend']['custom_css']);

		// Die Maske liegt neben der CSS-Datei, die sie relativ anspricht
		$oeffentlich = \dirname(__DIR__, 2).'/src/Resources/public/';
		$this->assertStringContainsString('url("menue.svg")', (string) file_get_contents($oeffentlich.'menue.css'));
		$this->assertStringContainsString('.group-kinderschutz', (string) file_get_contents($oeffentlich.'menue.css'));
		$this->assertNotFalse(simplexml_load_file($oeffentlich.'menue.svg'));
	}

	/**
	 * Baut einen Container mit den Contao-Diensten als Platzhaltern.
	 *
	 * @param array<int, array<string, mixed>> $konfiguration Inhalt von contao_kinderschutzdokumente:
	 *
	 * @return ContainerBuilder Der noch nicht kompilierte Container
	 */
	private function container(array $konfiguration): ContainerBuilder
	{
		$container = new ContainerBuilder();
		$container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
		$container->setParameter('contao.csrf_token_name', 'contao_csrf_token');

		$platzhalter = array(
			Connection::class,
			TokenStorageInterface::class,
			RequestStack::class,
			ContaoFramework::class,
			// Die Benachrichtigung verschickt synchron über den Standardtransport,
			// nicht über MailerInterface; ohne diesen Platzhalter scheitert das Autowiring
			TransportInterface::class,
			UrlGeneratorInterface::class,
			ContaoCsrfTokenManager::class,
			// Das Frontend-Modul erbt von AbstractController; dessen Dienstlocator
			// richtet im echten Kernel das FrameworkBundle ein
			ContainerInterface::class,
			'monolog.logger.contao.general',
		);

		foreach ($platzhalter as $dienst) {
			$container->register($dienst)->setSynthetic(true)->setPublic(true);
		}

		(new ContaoKinderschutzdokumenteExtension())->load($konfiguration, $container);

		return $container;
	}
}
