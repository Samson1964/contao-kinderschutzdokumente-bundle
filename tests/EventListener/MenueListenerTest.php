<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\MenueListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Prüft, dass die Menügruppe „Kinderschutz“ genau für Berechtigte erscheint.
 *
 * Contao baut eine Menügruppe nur, wenn der Benutzer über seine Gruppen
 * Rechte an mindestens einem ihrer Module hat (BackendUser::navigation(),
 * 4.13 wie 5.7). Eine Prüferin ohne solche Rechte sah die Gruppe im
 * Prüfstand deshalb nicht, obwohl sie die Module über die Adresse öffnen
 * konnte. Der Hook getUserNavigation ergänzt die Gruppe; das Menü-Ereignis
 * nimmt sie Unberechtigten wieder weg.
 */
class MenueListenerTest extends TestCase
{
	/**
	 * Setzt Module und Beschriftungen wie in einer echten Installation:
	 * die Gruppe des Bundles steht vor „system“, weil die Reihenfolge der
	 * Gruppen im Menü der in $GLOBALS['BE_MOD'] folgt.
	 */
	protected function setUp(): void
	{
		$GLOBALS['BE_MOD'] = array(
			'content'      => array('article' => array()),
			'kinderschutz' => array(
				'kinderschutz_uebersicht' => array('callback' => 'X', 'disablePermissionChecks' => true),
				'kinderschutz_nachweise'  => array('tables' => array('tl_kinderschutz'), 'disablePermissionChecks' => true),
				'kinderschutz_versteckt'  => array('tables' => array('tl_kinderschutz'), 'disablePermissionChecks' => true, 'hideInNavigation' => true),
			),
			'system'       => array('undo' => array()),
		);
		$GLOBALS['TL_LANG']['MOD']['kinderschutz'] = 'Kinderschutz';
		$GLOBALS['TL_LANG']['MOD']['kinderschutz_uebersicht'] = array('Übersicht', 'Wer ist vollständig');
		$GLOBALS['TL_LANG']['MOD']['kinderschutz_nachweise'] = array('Nachweise', 'Unterlagen & Prüfvermerke');
		$GLOBALS['TL_LANG']['MSC']['collapseNode'] = 'Bereich schließen';
	}

	/**
	 * Räumt die gesetzten Globalen wieder ab.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['BE_MOD'], $GLOBALS['TL_LANG']);
	}

	/**
	 * Eine Prüferin ohne Modulrechte bekommt die Gruppe mit allen sichtbaren
	 * Modulen, aufgebaut wie Contaos eigene Gruppen und an der Stelle, die
	 * $GLOBALS['BE_MOD'] vorgibt.
	 */
	public function testPrueferinOhneModulrechteBekommtDieGruppe(): void
	{
		$vorher = array('content' => array('label' => 'Inhalte'), 'system' => array('label' => 'System'));

		$nachher = $this->listener($this->backendBenutzer(7, '', '1'), 'kinderschutz_nachweise')->gruppeErgaenzen($vorher, true);

		$this->assertSame(array('content', 'kinderschutz', 'system'), array_keys($nachher));

		$gruppe = $nachher['kinderschutz'];
		$this->assertSame('group-kinderschutz node-expanded', $gruppe['class']);
		$this->assertSame('Bereich schließen', $gruppe['title']);
		$this->assertSame('Kinderschutz', $gruppe['label']);
		$this->assertSame('/contao?do=kinderschutz_nachweise&mtg=kinderschutz', $gruppe['href']);
		$this->assertSame('/contao', $gruppe['ajaxUrl']);

		$this->assertSame(array('kinderschutz_uebersicht', 'kinderschutz_nachweise'), array_keys($gruppe['modules']));
		$modul = $gruppe['modules']['kinderschutz_nachweise'];
		$this->assertSame('Nachweise', $modul['label']);
		$this->assertSame('Unterlagen &amp; Prüfvermerke', $modul['title']);
		$this->assertSame('navigation kinderschutz_nachweise', $modul['class']);
		$this->assertSame('/contao?do=kinderschutz_nachweise', $modul['href']);
		$this->assertFalse($modul['isActive']);
		$this->assertSame(array('tl_kinderschutz'), $modul['tables'], 'Die Modulkonfiguration bleibt erhalten wie bei Contao.');
	}

	/**
	 * Der Hauptadmin bekommt die Gruppe auch dann, wenn Contao sie nicht
	 * gebaut hat.
	 */
	public function testHauptadminBekommtDieGruppe(): void
	{
		$nachher = $this->listener($this->backendBenutzer(1, '', ''), null)->gruppeErgaenzen(array('system' => array()), true);

		$this->assertArrayHasKey('kinderschutz', $nachher);
		$this->assertSame('/contao?mtg=kinderschutz', $nachher['kinderschutz']['href']);
	}

	/**
	 * Ein Administrator ohne Freigabe und ein gewöhnlicher Benutzer bekommen
	 * nichts dazu.
	 */
	public function testUnberechtigteBekommenNichts(): void
	{
		$vorher = array('content' => array('label' => 'Inhalte'), 'system' => array('label' => 'System'));

		$this->assertSame($vorher, $this->listener($this->backendBenutzer(3, '1', ''), null)->gruppeErgaenzen($vorher, true));
		$this->assertSame($vorher, $this->listener($this->backendBenutzer(4, '', ''), null)->gruppeErgaenzen($vorher, true));
	}

	/**
	 * Hat Contao die Gruppe schon gebaut (Modulrechte über die Benutzergruppe),
	 * bleibt sie unverändert.
	 */
	public function testVorhandeneGruppeBleibtUnveraendert(): void
	{
		$vorher = array('kinderschutz' => array('label' => 'von Contao'), 'system' => array());

		$this->assertSame($vorher, $this->listener($this->backendBenutzer(7, '', '1'), null)->gruppeErgaenzen($vorher, true));
	}

	/**
	 * Das Menü-Ereignis nimmt die Gruppe Unberechtigten weg und lässt sie
	 * Berechtigten.
	 */
	public function testMenueEreignisEntferntGruppeNurFuerUnberechtigte(): void
	{
		foreach (array(array(3, '1', '', false), array(7, '', '1', true), array(1, '', '', true)) as list($id, $admin, $pruefer, $bleibt)) {
			$fabrik = new MenuFactory();
			$baum = $fabrik->createItem('mainMenu');
			$baum->addChild('kinderschutz');
			$baum->addChild('system');

			$this->listener($this->backendBenutzer($id, $admin, $pruefer), null)(new MenuEvent($fabrik, $baum));

			$this->assertSame($bleibt, null !== $baum->getChild('kinderschutz'), 'Benutzer '.$id);
			$this->assertNotNull($baum->getChild('system'));
		}
	}

	/**
	 * Das Kopfzeilenmenü bleibt unberührt.
	 */
	public function testAnderesMenueBleibtUnberuehrt(): void
	{
		$fabrik = new MenuFactory();
		$baum = $fabrik->createItem('headerMenu');
		$baum->addChild('kinderschutz');

		$this->listener($this->backendBenutzer(3, '1', ''), null)(new MenuEvent($fabrik, $baum));

		$this->assertNotNull($baum->getChild('kinderschutz'));
	}

	/**
	 * Baut den Listener mit einem angemeldeten Benutzer.
	 *
	 * Der Router liefert „/contao“ mit den Parametern als Abfrage; leere
	 * Parameter fallen wie bei Symfony weg.
	 *
	 * @param BackendUser $benutzer Der angemeldete Benutzer
	 * @param string|null $do       Wert von „do“ in der aktuellen Anfrage
	 *
	 * @return MenueListener Der Listener mit Hauptadmin 1
	 */
	private function listener(BackendUser $benutzer, ?string $do): MenueListener
	{
		$speicher = new TokenStorage();
		$speicher->setToken(new UsernamePasswordToken($benutzer, 'contao_backend', $benutzer->getRoles()));

		$router = $this->createMock(UrlGeneratorInterface::class);
		$router->method('generate')->willReturnCallback(static function (string $route, array $parameter = array()): string {
			$abfrage = http_build_query($parameter);

			return '/contao'.('' === $abfrage ? '' : '?'.$abfrage);
		});

		$anfragen = new RequestStack();
		$anfragen->push(new Request(null === $do ? array() : array('do' => $do)));

		return new MenueListener(new Zugang($speicher, new Berechtigung(1)), $router, $anfragen);
	}

	/**
	 * Baut einen Backend-Benutzer wie im ZugangTest.
	 *
	 * @param int    $id      ID in tl_user
	 * @param string $admin   '1' für Administrator
	 * @param string $pruefer Wert von kinderschutzPruefer
	 *
	 * @return BackendUser Der Benutzer
	 */
	private function backendBenutzer(int $id, string $admin, string $pruefer): BackendUser
	{
		$benutzer = $this->getMockBuilder(BackendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$benutzer->method('getRoles')->willReturn(array('ROLE_USER'));
		$benutzer->id = $id;
		$benutzer->admin = $admin;
		$benutzer->kinderschutzPruefer = $pruefer;

		return $benutzer;
	}
}
