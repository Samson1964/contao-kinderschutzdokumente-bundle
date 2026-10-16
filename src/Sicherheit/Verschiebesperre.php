<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;

/**
 * Sperrt das Verschieben von Datensätzen in den Kinderschutz-Tabellen.
 *
 * Die Tabellen haben weder „pid“ noch „sorting“; es gibt nichts zu
 * verschieben, und die Listen bieten es nicht an. Eine von Hand gebaute
 * Adresse mit „act=cut“ endete im Prüfstand 5.7 trotzdem mit HTTP 500. Die
 * DCA trägt deshalb „notSortable“, doch darauf antworten die Fassungen
 * verschieden: Contao 5.7 wirft in DC_Table::cut()/cutAll() eine
 * AccessDeniedException (403), Contao 4.13 eine
 * InternalServerErrorException (500, DC_Table.php in cut() und cutAll()).
 * Die eigene Sperre im onload-Callback greift vorher und liefert in beiden
 * Fassungen 403.
 */
final class Verschiebesperre
{
	/**
	 * Bricht mit „Zugriff verweigert“ ab, wenn die Anfrage verschieben will.
	 *
	 * Gesperrt sind „act=cut“, „act=cutAll“ und „act=paste&mode=cut“ (das
	 * Einfügen aus der Zwischenablage nach dem Ausschneiden; Contao 4.13
	 * unterscheidet es in Backend.php beim Ermitteln des Bezugsdatensatzes
	 * ausdrücklich). „paste“ mit „mode=copy“ oder ohne Modus bleibt unberührt;
	 * Kopieren regelt „notCopyable“ der jeweiligen DCA.
	 *
	 * „act“ und „mode“ kommen über Aktion, also so, wie Contao sie für
	 * $dc->$act() liest (4.13 normalisiert Werte und Schlüssel), und
	 * kleingeschrieben. Ein Vergleich mit dem rohen Symfony-Wert ließe sich
	 * mit „cu&#116;“ oder „ac%0Dt=cut“ umgehen.
	 *
	 * @param ContaoFramework $framework Das Framework, dessen Input-Adapter
	 *                                   Aktion liest; ohne Anfrage keine Sperre
	 *
	 * @throws AccessDeniedException Bei einer Verschiebeaktion oder einem ungültigen „act“/„mode“
	 */
	public static function pruefe(ContaoFramework $framework): void
	{
		$act = Aktion::act($framework);
		$modus = Aktion::modus($framework);

		if (\in_array($act, array('cut', 'cutall'), true) || ('paste' === $act && 'cut' === $modus)) {
			throw new AccessDeniedException('Kinderschutz-Datensätze lassen sich nicht verschieben.');
		}
	}
}
