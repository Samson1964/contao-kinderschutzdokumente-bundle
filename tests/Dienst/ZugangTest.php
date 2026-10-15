<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\FrontendUser;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Prüft die Zugangsregel am angemeldeten Benutzer – vor allem, dass ein
 * Contao-Administrator ohne Freigabe nichts sieht.
 */
class ZugangTest extends TestCase
{
	/**
	 * Hauptadmin, Prüfer, Administrator ohne Freigabe, normaler Benutzer.
	 */
	public function testBackendBenutzer(): void
	{
		$this->assertTrue($this->zugang($this->backendBenutzer(1, '1', ''))->darfPruefen());
		$this->assertTrue($this->zugang($this->backendBenutzer(1, '1', ''))->istHauptadmin());
		$this->assertTrue($this->zugang($this->backendBenutzer(7, '', '1'))->darfPruefen());
		$this->assertFalse($this->zugang($this->backendBenutzer(7, '', '1'))->istHauptadmin());
		$this->assertFalse($this->zugang($this->backendBenutzer(3, '1', ''))->darfPruefen());
		$this->assertFalse($this->zugang($this->backendBenutzer(4, '', ''))->darfPruefen());
	}

	/**
	 * Ein Administrator ohne Freigabe wird abgewiesen.
	 */
	public function testVerlangeWirft(): void
	{
		$this->expectException(AccessDeniedException::class);

		$this->zugang($this->backendBenutzer(3, '1', ''))->verlange();
	}

	/**
	 * Ohne Anmeldung oder mit Frontend-Anmeldung gibt es keinen Zugang.
	 */
	public function testOhneBackendAnmeldung(): void
	{
		$this->assertFalse((new Zugang(new TokenStorage(), new Berechtigung(1)))->darfPruefen());
		$this->assertSame(0, (new Zugang(new TokenStorage(), new Berechtigung(1)))->benutzerId());

		$mitglied = $this->getMockBuilder(FrontendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$mitglied->method('getRoles')->willReturn(array('ROLE_MEMBER'));
		$this->assertFalse($this->zugang($mitglied)->darfPruefen());
	}

	/**
	 * Ein Frontend-Mitglied, dessen ID zufällig die des Hauptadmins ist,
	 * bekommt keinen Zugang.
	 *
	 * Die Mitglieds-ID 1 in tl_member hat nichts mit der Benutzer-ID 1 in
	 * tl_user zu tun. Nur ein angemeldeter Backend-Benutzer zählt; ein
	 * Mitglied bleibt ein Fremder, auch wenn die Zahl übereinstimmt.
	 */
	public function testFrontendMitgliedMitHauptadminIdHatKeinenZugang(): void
	{
		$zugang = $this->zugang($this->frontendMitglied(1));

		$this->assertFalse($zugang->darfPruefen());
		$this->assertFalse($zugang->istHauptadmin());
		$this->assertSame(0, $zugang->benutzerId());
	}

	/**
	 * verlange() weist ein Frontend-Mitglied mit der ID des Hauptadmins ab.
	 */
	public function testVerlangeWeistFrontendMitgliedMitHauptadminIdAb(): void
	{
		$this->expectException(AccessDeniedException::class);

		$this->zugang($this->frontendMitglied(1))->verlange();
	}

	/**
	 * verlange() lässt den Hauptadmin und einen freigegebenen Prüfer ohne
	 * Ausnahme durch.
	 *
	 * Gegenstück zu den Abweisungen: Eine Prüfung, die immer wirft, wäre
	 * sonst nicht zu erkennen. Der Hauptadmin braucht keine Freigabe, der
	 * Prüfer ist kein Administrator.
	 */
	public function testVerlangeLaesstBerechtigteDurch(): void
	{
		$this->expectNotToPerformAssertions();

		$this->zugang($this->backendBenutzer(1, '1', ''))->verlange();
		$this->zugang($this->backendBenutzer(7, '', '1'))->verlange();
	}

	/**
	 * Ohne Anmeldung wirft verlange() eine AccessDeniedException.
	 */
	public function testVerlangeOhneAnmeldungWirft(): void
	{
		$this->expectException(AccessDeniedException::class);

		(new Zugang(new TokenStorage(), new Berechtigung(1)))->verlange();
	}

	/**
	 * Baut ein Frontend-Mitglied.
	 *
	 * Wie beim Backend-Benutzer wird nur getRoles ersetzt, damit die ID wie
	 * bei Contao im Datenarray des Benutzers landet.
	 *
	 * @param int $id ID in tl_member
	 *
	 * @return FrontendUser Das Mitglied
	 */
	private function frontendMitglied(int $id): FrontendUser
	{
		$mitglied = $this->getMockBuilder(FrontendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$mitglied->method('getRoles')->willReturn(array('ROLE_MEMBER'));
		$mitglied->id = $id;

		return $mitglied;
	}

	/**
	 * Baut einen Backend-Benutzer.
	 *
	 * Nur getRoles wird ersetzt; __get und __set bleiben echt, damit die
	 * Spaltenwerte wie bei Contao im Datenarray landen.
	 *
	 * @param int    $id     ID in tl_user
	 * @param string $admin  '1' für Administrator
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

	/**
	 * Der Zugangsdienst mit einem angemeldeten Benutzer.
	 *
	 * @param BackendUser|FrontendUser $benutzer Der Benutzer
	 *
	 * @return Zugang Der Dienst mit Hauptadmin 1
	 */
	private function zugang(BackendUser|FrontendUser $benutzer): Zugang
	{
		$speicher = new TokenStorage();
		$speicher->setToken(new UsernamePasswordToken($benutzer, 'contao_backend', $benutzer->getRoles()));

		return new Zugang($speicher, new Berechtigung(1));
	}
}
