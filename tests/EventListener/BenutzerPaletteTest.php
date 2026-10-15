<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\BenutzerListener;

/**
 * Prüft das Einfügen und Entfernen der Prüferfreigabe in den tl_user-Paletten.
 *
 * Die Paletten sind die von Contao 5.7 (gekürzt); 4.13 hat dieselbe
 * account_legend am Ende.
 */
class BenutzerPaletteTest extends TestCase
{
	private const PALETTEN = array(
		'login'   => '{name_legend},name,email;{password_legend},password',
		'admin'   => '{name_legend},username,name,email;{admin_legend},admin;{account_legend},disable,start,stop',
		'default' => '{name_legend},username,name,email;{admin_legend},admin;{groups_legend},groups,inherit;{account_legend},disable,start,stop',
		'group'   => '{name_legend},username;{groups_legend},groups,inherit;{account_legend},disable,start,stop',
		'extend'  => '{name_legend},username;{modules_legend},modules;{account_legend},disable,start,stop',
		'custom'  => '{name_legend},username;{modules_legend},modules;{account_legend},disable,start,stop',
	);

	/**
	 * Das Feld steht vor den Kontodaten, aber nie im eigenen Profil.
	 */
	public function testFeldWirdEingefuegt(): void
	{
		$GLOBALS['TL_DCA']['tl_user'] = array('palettes' => self::PALETTEN, 'fields' => array());
		include __DIR__.'/../../src/Resources/contao/dca/tl_user.php';
		$paletten = $GLOBALS['TL_DCA']['tl_user']['palettes'];
		unset($GLOBALS['TL_DCA']);

		$this->assertSame(self::PALETTEN['login'], $paletten['login']);
		$this->assertSame('{name_legend},username,name,email;{admin_legend},admin;{kinderschutz_legend},kinderschutzPruefer;{account_legend},disable,start,stop', $paletten['admin']);

		foreach (BenutzerListener::PALETTEN as $name) {
			$this->assertStringContainsString('{kinderschutz_legend},kinderschutzPruefer;{account_legend}', $paletten[$name], $name);
		}
	}

	/**
	 * Entfernen nimmt Feld und Legende wieder heraus.
	 */
	public function testFeldWirdEntfernt(): void
	{
		$GLOBALS['TL_DCA']['tl_user'] = array('palettes' => self::PALETTEN, 'fields' => array());
		include __DIR__.'/../../src/Resources/contao/dca/tl_user.php';
		BenutzerListener::entferneFeld();
		$paletten = $GLOBALS['TL_DCA']['tl_user']['palettes'];
		unset($GLOBALS['TL_DCA']);

		foreach (self::PALETTEN as $name => $palette) {
			$this->assertSame($palette, $paletten[$name], $name);
		}
	}
}
