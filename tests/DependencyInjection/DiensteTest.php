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
use Schachbulle\ContaoKinderschutzdokumenteBundle\DependencyInjection\ContaoKinderschutzdokumenteExtension;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\TabellenVoter;
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
		$this->assertTrue($container->has(TaeglicherCron::class));
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
