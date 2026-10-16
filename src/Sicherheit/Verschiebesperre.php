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
	 * „act“ und „mode“ werden vor dem Vergleich kleingeschrieben: Contao ruft
	 * für jede Aktion, die es nicht selbst abfängt, $dc->$act() auf, und
	 * PHP-Methodennamen sind von der Schreibweise unabhängig. „act=Cut“ oder
	 * „act=cutall“ landeten sonst in DC_Table::cut()/cutAll(), ohne dass ein
	 * Vergleich mit „cut“/„cutAll“ greift (in 4.13 und 5.7 gleich).
	 *
	 * @param Request|null $anfrage Die laufende Anfrage; null (etwa auf der
	 *                              Kommandozeile) sperrt nichts
	 *
	 * @throws AccessDeniedException Bei einer Verschiebeaktion
	 */
	public static function pruefe(?Request $anfrage): void
	{
		$act = self::aktion($anfrage);
		$modus = strtolower((string) $anfrage?->query->get('mode'));

		if (\in_array($act, array('cut', 'cutall'), true) || ('paste' === $act && 'cut' === $modus)) {
			throw new AccessDeniedException('Kinderschutz-Datensätze lassen sich nicht verschieben.');
		}
	}

	/**
	 * Liefert die Aktion der Anfrage („act“) in Kleinbuchstaben.
	 *
	 * Contao vergleicht „act“ an einigen Stellen wörtlich, ruft aber am Ende
	 * $dc->$act() auf; PHP-Methodennamen sind von der Schreibweise
	 * unabhängig. „act=editall“ führt also DC_Table::editAll() aus, obwohl
	 * ein Vergleich mit „editAll“ nicht greift. Wer eine Aktion sperren oder
	 * an ihr etwas einrichten will, vergleicht deshalb diesen Wert mit
	 * kleingeschriebenen Soll-Werten.
	 *
	 * @param Request|null $anfrage Die laufende Anfrage; null (etwa auf der
	 *                              Kommandozeile) ergibt eine leere Zeichenkette
	 *
	 * @return string Die Aktion in Kleinbuchstaben, leer ohne „act“
	 */
	public static function aktion(?Request $anfrage): string
	{
		return strtolower((string) $anfrage?->query->get('act'));
	}
}
