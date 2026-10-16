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
 * Sperrt das Kopieren von Nachweisen und Anträgen.
 *
 * Beide Tabellen tragen „notCopyable“; die Listen bieten Kopieren nicht an.
 * Eine von Hand gebaute Adresse mit „act=copy“ oder „act=copyAll“ beantworten
 * die Fassungen aber verschieden: Contao 5.7 wirft eine
 * AccessDeniedException (403), Contao 4.13 eine InternalServerErrorException
 * („Table … is not copyable.“, HTTP 500, DC_Table::copy() und copyAll()).
 * Diese Sperre greift im onload-Callback vorher und liefert in beiden
 * Fassungen 403 – wie die Verschiebesperre für „cut“.
 */
final class Kopiersperre
{
	/**
	 * Bricht mit „Zugriff verweigert“ ab, wenn die Anfrage kopieren will.
	 *
	 * Gesperrt sind „act=copy“ und „act=copyAll“ in jeder Schreibweise
	 * (Contao ruft $dc->$act() auf, siehe Aktion::act()).
	 * „act=paste&mode=copy“ bleibt erlaubt: Es zeigt nur die Liste mit der
	 * Zwischenablage; der eigentliche Kopiervorgang läuft danach über
	 * „act=copy“ und wird dort gesperrt.
	 *
	 * @param ContaoFramework $framework Das Framework, dessen Input-Adapter
	 *                                   Aktion liest; ohne Anfrage keine Sperre
	 *
	 * @throws AccessDeniedException Bei einer Kopieraktion oder einem ungültigen „act“
	 */
	public static function pruefe(ContaoFramework $framework): void
	{
		if (\in_array(Aktion::act($framework), array('copy', 'copyall'), true)) {
			throw new AccessDeniedException('Kinderschutz-Datensätze lassen sich nicht kopieren.');
		}
	}
}
