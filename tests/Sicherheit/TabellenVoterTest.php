<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Sicherheit;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\TabellenVoter;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Prüft, dass Unberechtigte die Kinderschutz-Tabellen über die
 * Contao-5-Rechte nicht lesen können (Backend-Suche, Listen).
 */
class TabellenVoterTest extends TestCase
{
	/**
	 * Admin ohne Freigabe: verweigert; Prüfer und Hauptadmin: Enthaltung.
	 */
	public function testEigeneTabellen(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));
		$lesen = new ReadAction('tl_kinderschutz', array('id' => 3));
		$aendern = new UpdateAction('tl_kinderschutz_veranstaltung', array('id' => 2));

		$this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(3, ''), $lesen, array('contao_dc.tl_kinderschutz')));
		$this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(3, ''), $aendern, array('contao_dc.tl_kinderschutz_veranstaltung')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(7, '1'), $lesen, array('contao_dc.tl_kinderschutz')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(1, ''), $lesen, array('contao_dc.tl_kinderschutz')));
	}

	/**
	 * Fremde Tabellen und fremde Rechte bleiben unberührt.
	 */
	public function testFremdes(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));

		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), new ReadAction('tl_news', array('id' => 1)), array('contao_dc.tl_news')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), new ReadAction('tl_kinderschutz', array('id' => 1)), array('contao_user.modules')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), 'tl_kinderschutz', array('contao_dc.tl_kinderschutz')));
	}

	/**
	 * Baut ein Token mit einem Backend-Benutzer.
	 *
	 * @param int    $id      ID in tl_user
	 * @param string $pruefer Wert von kinderschutzPruefer
	 *
	 * @return UsernamePasswordToken Das Token
	 */
	private function token(int $id, string $pruefer): UsernamePasswordToken
	{
		$benutzer = $this->getMockBuilder(BackendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$benutzer->method('getRoles')->willReturn(array('ROLE_USER'));
		$benutzer->id = $id;
		$benutzer->admin = '1';
		$benutzer->kinderschutzPruefer = $pruefer;

		return new UsernamePasswordToken($benutzer, 'contao_backend', array('ROLE_USER'));
	}
}
