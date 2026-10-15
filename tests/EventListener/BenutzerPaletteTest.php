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

	/**
	 * Die Freigabe wird beim Kopieren eines Benutzers nicht mitgenommen.
	 *
	 * DC_Table::copy() setzt Felder mit eval.doNotCopy auf den Leerwert der
	 * Spalte zurück. Ohne das Kennzeichen übernähme die Kopie eines Prüfers
	 * die Freigabe, und es entstünde ein neuer Prüfer, ohne dass der Hauptadmin
	 * ihn freigegeben hätte. Die übrigen eval-Angaben bleiben dabei erhalten.
	 */
	public function testFreigabeWirdNichtKopiert(): void
	{
		$GLOBALS['TL_DCA']['tl_user'] = array('palettes' => self::PALETTEN, 'fields' => array());
		include __DIR__.'/../../src/Resources/contao/dca/tl_user.php';
		$eval = $GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer']['eval'];
		unset($GLOBALS['TL_DCA']);

		$this->assertTrue($eval['doNotCopy'] ?? null);
		$this->assertSame('w50', $eval['tl_class'] ?? null);
	}

	/**
	 * Für alle außer dem Hauptadmin ist das Feld weder im Filter der
	 * Benutzerliste noch unter „Anzeigen“ zu sehen.
	 *
	 * Im Ausgangszustand, den der Hauptadmin sieht, ist das Feld filterbar und
	 * nicht ausgeblendet. Erst entferneFeld() nimmt es aus dem Filter
	 * (DC_Table::filterMenu() prüft „filter“ auf Gleichheit mit dem Panel) und
	 * setzt eval.doNotShow, das DC_Table::show() beachtet. Kopierschutz und
	 * Layoutklasse müssen dabei erhalten bleiben.
	 */
	public function testFeldIstFuerAndereUnsichtbar(): void
	{
		$GLOBALS['TL_DCA']['tl_user'] = array('palettes' => self::PALETTEN, 'fields' => array());
		include __DIR__.'/../../src/Resources/contao/dca/tl_user.php';
		$vorher = $GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer'];
		BenutzerListener::entferneFeld();
		$nachher = $GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer'];
		unset($GLOBALS['TL_DCA']);

		$this->assertTrue($vorher['filter']);
		$this->assertArrayNotHasKey('doNotShow', $vorher['eval']);

		$this->assertFalse($nachher['filter']);
		$this->assertTrue($nachher['eval']['doNotShow'] ?? null);
		$this->assertTrue($nachher['eval']['doNotCopy'] ?? null);
		$this->assertSame('w50', $nachher['eval']['tl_class'] ?? null);
	}
}
