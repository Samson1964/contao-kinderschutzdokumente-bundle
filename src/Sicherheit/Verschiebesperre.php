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
use Symfony\Component\HttpFoundation\Request;

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
	 * „act“ und „mode“ kommen über Aktion: kleingeschrieben, und ein Wert aus
	 * anderen Zeichen („cu&#116;“, „cu%00t“) führt schon dort zu 403. Contao
	 * ruft $dc->$act() auf, PHP-Methodennamen sind von der Schreibweise
	 * unabhängig, und 4.13 normalisiert „act“ in Input::get(); ein Vergleich
	 * mit dem rohen Wert ließe sich sonst umgehen.
	 *
	 * @param Request|null $anfrage Die laufende Anfrage; null (etwa auf der
	 *                              Kommandozeile) sperrt nichts
	 *
	 * @throws AccessDeniedException Bei einer Verschiebeaktion oder einem ungültigen „act“/„mode“
	 */
	public static function pruefe(?Request $anfrage): void
	{
		$act = Aktion::act($anfrage);
		$modus = Aktion::modus($anfrage);

		if (\in_array($act, array('cut', 'cutall'), true) || ('paste' === $act && 'cut' === $modus)) {
			throw new AccessDeniedException('Kinderschutz-Datensätze lassen sich nicht verschieben.');
		}
	}
}
