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
use Contao\Input;

/**
 * Ermittelt „act“ und „mode“ einer Backend-Anfrage so, wie Contao sie ausführt.
 *
 * Maßgeblich ist, was Contao aufruft: Backend.php liest die Aktion mit
 * Input::get('act') und ruft dann $dc->$act() auf (4.13 Z. 491/667, 5.7
 * Z. 378/464). Die Sperren des Bundles lesen deshalb denselben Wert über
 * denselben Weg – den Input-Adapter des ContaoFramework – statt über den
 * RequestStack. Der rohe Symfony-Wert taugt nicht:
 *
 * - Contao 4.13 normalisiert die **Werte** in Input::get() (decodeEntities,
 *   xssClean, stripTags; Input.php Z. 86–88): aus „cu&#116;“, „cu%00t“ oder
 *   „cu%0Dt“ wird „cut“.
 * - Contao 4.13 normalisiert auch die **Schlüssel**: Input::initialize()
 *   schickt $_GET durch cleanKey() (Z. 59, 425–448), bei doppelten Schlüsseln
 *   gewinnt der letzte. „ac&#116;=cut“, „ac%0Dt=cut“ oder „mo%0Dde=cut“ sind
 *   für Symfony andere Parameter, für Contao „act“ bzw. „mode“.
 * - PHP-Methodennamen sind von der Schreibweise unabhängig; „act=cutall“
 *   führt DC_Table::cutAll() aus. Deshalb wird kleingeschrieben verglichen.
 *
 * Contao 5.7 normalisiert weder Werte noch Schlüssel, Input::get() liest dort
 * aus der laufenden Anfrage und kodiert nur Sonderzeichen; der Weg über den
 * Adapter ist in beiden Fassungen derselbe.
 *
 * Zweite Absicherung: Auch der von Contao gelesene Wert muss aus Buchstaben
 * bestehen („mode“: Buchstaben und Ziffern, Contao nutzt „1“ und „2“), sonst
 * 403. Ein solcher Wert kann ohnehin keine Methode von DC_Table treffen; die
 * Prüfung verhindert, dass die Sperren von weiteren Feinheiten der
 * Normalisierung abhängen (5.7 liefert etwa „cu&amp;#116;“ kodiert zurück).
 * Wer über diese Grenze stolpert, hat die Adresse von Hand gebaut.
 */
final class Aktion
{
	/**
	 * Liefert „act“, wie Contao es liest, in Kleinbuchstaben.
	 *
	 * @param ContaoFramework $framework Das Framework; im Backend-onload ist es
	 *                                   initialisiert, ohne Anfrage (etwa auf der
	 *                                   Kommandozeile) liefert Input::get() null
	 *
	 * @return string Die Aktion in Kleinbuchstaben, leer ohne „act“
	 *
	 * @throws AccessDeniedException Wenn der Wert keine reine Buchstabenfolge ist
	 */
	public static function act(ContaoFramework $framework): string
	{
		return self::wert($framework, 'act', '/^[A-Za-z]*$/D');
	}

	/**
	 * Liefert „mode“, wie Contao es liest, in Kleinbuchstaben.
	 *
	 * @param ContaoFramework $framework Das Framework
	 *
	 * @return string Der Modus in Kleinbuchstaben, leer ohne „mode“
	 *
	 * @throws AccessDeniedException Wenn der Wert andere Zeichen als Buchstaben
	 *                               und Ziffern enthält
	 */
	public static function modus(ContaoFramework $framework): string
	{
		return self::wert($framework, 'mode', '/^[A-Za-z0-9]*$/D');
	}

	/**
	 * Liest einen Adressparameter über Contao\Input und prüft seine Form.
	 *
	 * Das Muster trägt den Schalter D: Ohne ihn ließe „$“ einen
	 * abschließenden Zeilenumbruch durch. Ein Feld („act[]=…“) wird ebenfalls
	 * abgewiesen.
	 *
	 * @param ContaoFramework $framework Das Framework
	 * @param string          $name      Name des Parameters
	 * @param string          $muster    Erlaubte Form als regulärer Ausdruck
	 *
	 * @return string Der Wert in Kleinbuchstaben, leer ohne Parameter
	 *
	 * @throws AccessDeniedException Bei einem Feld oder einem Wert außerhalb des Musters
	 */
	private static function wert(ContaoFramework $framework, string $name, string $muster): string
	{
		$wert = $framework->getAdapter(Input::class)->get($name);

		if (null === $wert || '' === $wert) {
			return '';
		}

		if (!\is_string($wert) || 1 !== preg_match($muster, $wert)) {
			throw new AccessDeniedException(sprintf('Ungültiger Wert für „%s“.', $name));
		}

		return strtolower($wert);
	}
}
