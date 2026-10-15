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
 * auch Administratoren – wird es beim Laden aus den Paletten, aus dem Filter
 * der Benutzerliste und aus der Ansicht „Anzeigen“ genommen, und ein
 * save_callback weist Änderungen zusätzlich ab.
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
	 * Macht das Feld für alle außer dem Hauptadmin unsichtbar.
	 *
	 * Läuft im onload-Callback von tl_user. Der Hauptadmin bleibt unberührt;
	 * für jeden anderen Backend-Benutzer übernimmt entferneFeld() das Entfernen
	 * aus Paletten, Filter und „Anzeigen“. Die Änderung gilt nur für diese
	 * Anfrage, weil sie an $GLOBALS['TL_DCA'] erfolgt und nichts speichert.
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
	 * Entfernt das Feld aus Paletten, Filter und der Ansicht „Anzeigen“.
	 *
	 * Drei Stellen verraten das Feld, auch wenn es in keiner Palette mehr
	 * steht:
	 *
	 * - Die Paletten: Feld und die Legende kinderschutz_legend werden entfernt.
	 * - Der Filter der Benutzerliste: DC_Table::filterMenu() nimmt jedes Feld,
	 *   dessen „filter“ dem Panel entspricht; false schließt es aus.
	 * - Die Ansicht „Anzeigen“ (act=show): DC_Table::show() überspringt Felder
	 *   mit eval.doNotShow, sonst stünde der Wert dort im Klartext.
	 *
	 * Statisch und öffentlich, damit der Test genau diesen Weg prüft. Ändert
	 * nur $GLOBALS['TL_DCA'] der laufenden Anfrage. Fehlt das Feld im DCA (etwa
	 * weil tl_user.php nicht geladen wurde), legt die Methode einen Eintrag
	 * ohne inputType an, den Contao ignoriert.
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

		$GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer']['filter'] = false;
		$GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer']['eval']['doNotShow'] = true;
	}
}
