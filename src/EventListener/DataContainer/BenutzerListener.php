<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\DataContainer;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;

/**
 * Schützt die Prüferfreigabe am Backend-Benutzer.
 *
 * Nur der Hauptadmin sieht das Feld kinderschutzPruefer; für alle anderen –
 * auch Administratoren – wird es beim Laden aus den Paletten entfernt, und
 * ein save_callback weist Änderungen zusätzlich ab.
 */
class BenutzerListener
{
	/** Die Paletten, in die tl_user.php das Feld einfügt */
	public const PALETTEN = array('admin', 'default', 'group', 'extend', 'custom');

	/**
	 * @param Zugang $zugang Erkennt den Hauptadmin
	 */
	public function __construct(private readonly Zugang $zugang)
	{
	}

	/**
	 * Entfernt das Feld für alle außer dem Hauptadmin.
	 */
	#[AsCallback(table: 'tl_user', target: 'config.onload')]
	public function feldEinschraenken(): void
	{
		if ($this->zugang->istHauptadmin()) {
			return;
		}

		self::entferneFeld();
	}

	/**
	 * Weist Änderungen durch andere als den Hauptadmin ab.
	 *
	 * @param mixed         $wert Der neue Wert
	 * @param DataContainer $dc   Der Data Container
	 *
	 * @return mixed Der unveränderte Wert
	 *
	 * @throws \RuntimeException Für alle außer dem Hauptadmin
	 */
	#[AsCallback(table: 'tl_user', target: 'fields.kinderschutzPruefer.save')]
	public function freigabeSchuetzen(mixed $wert, DataContainer $dc): mixed
	{
		if (!$this->zugang->istHauptadmin()) {
			throw new \RuntimeException($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['nurHauptadmin'] ?? 'Nur der Hauptadmin darf das ändern.');
		}

		return $wert;
	}

	/**
	 * Entfernt Feld und Legende aus den tl_user-Paletten.
	 *
	 * Statisch und öffentlich, damit der Test genau diesen Weg prüft.
	 */
	public static function entferneFeld(): void
	{
		foreach (self::PALETTEN as $palette) {
			if (isset($GLOBALS['TL_DCA']['tl_user']['palettes'][$palette])) {
				PaletteManipulator::create()
					->removeField('kinderschutzPruefer', 'kinderschutz_legend')
					->applyToPalette($palette, 'tl_user');
			}
		}
	}
}
