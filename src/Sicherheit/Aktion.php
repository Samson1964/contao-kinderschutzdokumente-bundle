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
 * Ermittelt „act“ und „mode“ einer Backend-Anfrage so, wie Contao sie ausführt.
 *
 * Die Sperren des Bundles (Verschiebesperre, Kopiersperre, Sammelaktionen,
 * Palettenwahl) lesen die Adresse über den RequestStack, Contao dagegen über
 * Contao\Input und ruft am Ende $dc->$act() auf. Beides kann auseinanderfallen:
 *
 * - PHP-Methodennamen sind von der Schreibweise unabhängig; „act=cutall“
 *   führt DC_Table::cutAll() aus.
 * - Contao 4.13 normalisiert den Wert in Input::get() (decodeEntities,
 *   xssClean, stripTags; Input.php Z. 86–88). Aus „cu&#116;“, „cu%00t“ oder
 *   „cu%0Dt“ wird dort „cut“, während der rohe Symfony-Wert anders lautet.
 *   Contao 5.7 kodiert nur und normalisiert nicht; dort scheitert ein solcher
 *   Wert am Methodenaufruf.
 *
 * Deshalb gilt: Ein Wert aus anderen Zeichen als den erlaubten wird gar nicht
 * erst gedeutet, sondern mit 403 abgewiesen; ein gültiger Wert kommt
 * kleingeschrieben zurück. Contao selbst verwendet für „act“ nur Buchstaben,
 * für „mode“ Buchstaben und Ziffern („cut“, „copy“, „create“, „1“, „2“).
 */
final class Aktion
{
	/**
	 * Liefert „act“ der Anfrage in Kleinbuchstaben.
	 *
	 * @param Request|null $anfrage Die laufende Anfrage; null (etwa auf der
	 *                              Kommandozeile) ergibt eine leere Zeichenkette
	 *
	 * @return string Die Aktion in Kleinbuchstaben, leer ohne „act“
	 *
	 * @throws AccessDeniedException Wenn „act“ keine reine Buchstabenfolge ist
	 */
	public static function act(?Request $anfrage): string
	{
		return self::wert($anfrage, 'act', '/^[A-Za-z]*$/D');
	}

	/**
	 * Liefert „mode“ der Anfrage in Kleinbuchstaben.
	 *
	 * @param Request|null $anfrage Die laufende Anfrage; null ergibt eine
	 *                              leere Zeichenkette
	 *
	 * @return string Der Modus in Kleinbuchstaben, leer ohne „mode“
	 *
	 * @throws AccessDeniedException Wenn „mode“ andere Zeichen als Buchstaben
	 *                               und Ziffern enthält
	 */
	public static function modus(?Request $anfrage): string
	{
		return self::wert($anfrage, 'mode', '/^[A-Za-z0-9]*$/D');
	}

	/**
	 * Liest einen Adressparameter und prüft ihn gegen ein Muster.
	 *
	 * Das Muster trägt den Schalter D: Ohne ihn ließe „$“ einen
	 * abschließenden Zeilenumbruch („cut%0A“) durch.
	 *
	 * Gelesen wird über query->all(), damit auch ein Feld („act[]=…“) nicht
	 * an der Prüfung vorbeikommt; InputBag::get() würde es je nach
	 * Symfony-Fassung anders behandeln.
	 *
	 * @param Request|null $anfrage Die laufende Anfrage
	 * @param string       $name    Name des Parameters
	 * @param string       $muster  Erlaubte Form als regulärer Ausdruck
	 *
	 * @return string Der Wert in Kleinbuchstaben, leer ohne Parameter
	 *
	 * @throws AccessDeniedException Bei einem Feld oder einem Wert außerhalb des Musters
	 */
	private static function wert(?Request $anfrage, string $name, string $muster): string
	{
		$wert = null === $anfrage ? null : ($anfrage->query->all()[$name] ?? null);

		if (null === $wert || '' === $wert) {
			return '';
		}

		if (!\is_string($wert) || 1 !== preg_match($muster, $wert)) {
			throw new AccessDeniedException(sprintf('Ungültiger Wert für „%s“.', $name));
		}

		return strtolower($wert);
	}
}
