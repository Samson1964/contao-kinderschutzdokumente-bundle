<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;

/**
 * Liest die Einstellungen des Bundles aus der localconfig (Maske im
 * Kinderschutzbereich, siehe dca/tl_kinderschutz_einstellungen.php).
 *
 * Kennt die Vorgaben der Spezifikation (30 Tage Papierkorb, 8 Wochen
 * Erinnerungsvorlauf, 6 Wochen vor Veranstaltungen), damit das Bundle auch
 * ohne gespeicherte Einstellungen vernünftig arbeitet. Initialisiert das
 * Contao-Framework selbst, weil der Cronjob auch von der Konsole läuft.
 */
class Einstellungen
{
	/**
	 * Die Mailarten in der Reihenfolge der Einstellungsmaske; zugleich die
	 * Schlüssel unter $GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'].
	 */
	public const MAILARTEN = array('neu', 'abgelehnt', 'vorAblauf', 'ablauf', 'veranstaltung', 'antrag', 'freigeschaltet', 'antragAbgelehnt');

	/**
	 * @param ContaoFramework $framework Zugang zu Config::get()
	 */
	public function __construct(private readonly ContaoFramework $framework)
	{
	}

	/**
	 * Liefert die Gruppe der freigeschalteten Mitglieder.
	 *
	 * Seit Fassung 1.2.0 ist es genau eine Gruppe („kinderschutzGruppe“); in
	 * sie nimmt das Freischalten auf. Ist sie nicht gesetzt, gilt zum
	 * Übergang die erste Gruppe der früheren Mehrfachauswahl
	 * „kinderschutzGruppen“, damit eine bestehende Installation nach dem
	 * Update ohne neues Speichern weiterläuft.
	 *
	 * @return int ID aus tl_member_group, 0 wenn keine gewählt ist
	 */
	public function gruppe(): int
	{
		$gruppe = (int) $this->wert('kinderschutzGruppe');

		if ($gruppe > 0) {
			return $gruppe;
		}

		$wert = $this->wert('kinderschutzGruppen');
		$liste = is_string($wert) && '' !== $wert ? @unserialize($wert, array('allowed_classes' => false)) : $wert;
		$ids = is_array($liste) ? array_values(array_filter(array_map('intval', $liste))) : array();

		return $ids[0] ?? 0;
	}

	/**
	 * Liefert die Gruppe als Liste für die bestehenden Aufrufer.
	 *
	 * Matrix, Erinnerungen, Frontend und Personenauswahl fragen weiterhin
	 * nach einer Liste von Gruppen; sie enthält jetzt höchstens eine.
	 *
	 * @return list<int> Die Gruppe, oder leer, wenn keine gewählt ist
	 */
	public function gruppen(): array
	{
		$gruppe = $this->gruppe();

		return $gruppe > 0 ? array($gruppe) : array();
	}

	/**
	 * Liefert Betreff und Text einer eigenen Mailvorlage.
	 *
	 * Der Betreff ist ein einzeiliges Textfeld und wird nur entkodiert, der
	 * Text ist verpackt gespeichert (siehe verpacke()).
	 *
	 * @param string $art Eine der MAILARTEN
	 *
	 * @return array{0: string, 1: string} Betreff und Text; eine leere
	 *                                     Zeichenkette heißt „Standardtext
	 *                                     aus der Sprachdatei“
	 */
	public function mailvorlage(string $art): array
	{
		$schluessel = 'kinderschutzMail'.ucfirst($art);
		$betreff = html_entity_decode((string) $this->wert($schluessel.'Betreff'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return array(trim($betreff), self::entpacke($this->wert($schluessel.'Text')));
	}

	/**
	 * Verpackt einen mehrzeiligen Mailtext für die localconfig.
	 *
	 * Config::persist() fasst beim Schreiben jeden Weißraum zu einem
	 * Leerzeichen zusammen (Config::escape(), in 4.13 und 5.7 gleich); ein
	 * Mailtext verlöre so alle Zeilenumbrüche. Als JSON-Zeichenkette stehen
	 * die Umbrüche als „\n“ in einer Zeile und überstehen das.
	 *
	 * @param mixed $text Der Text aus dem Eingabefeld
	 *
	 * @return string JSON-Zeichenkette, oder leer für einen leeren Text
	 */
	public static function verpacke(mixed $text): string
	{
		$text = str_replace(array("\r\n", "\r"), "\n", (string) $text);

		return '' === trim($text) ? '' : (string) json_encode($text, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Entpackt einen gespeicherten Mailtext.
	 *
	 * Contao speichert Eingaben teils mit Entitäten („<“ als „&#60;“), auch
	 * innerhalb der JSON-Zeichenkette; deshalb erst entkodieren, dann
	 * entpacken. Ein Wert, der kein JSON ist, kommt entkodiert zurück.
	 *
	 * @param mixed $gespeichert Wert aus der localconfig
	 *
	 * @return string Der Text mit Zeilenumbrüchen, oder leer
	 */
	public static function entpacke(mixed $gespeichert): string
	{
		if (!is_string($gespeichert) || '' === $gespeichert) {
			return '';
		}

		$roh = html_entity_decode($gespeichert, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = json_decode($roh);

		return is_string($text) ? $text : $roh;
	}

	/**
	 * Liefert die Seite mit dem Frontend-Modul, für Links in Mails.
	 *
	 * @return int ID aus tl_page, 0 wenn nicht gesetzt
	 */
	public function seite(): int
	{
		return (int) $this->wert('kinderschutzSeite');
	}

	/**
	 * Liefert die Absenderadresse der Mails.
	 *
	 * @return string Die eigene Absenderadresse, ersatzweise die
	 *                Administrator-Adresse von Contao; leer, wenn beide fehlen
	 */
	public function absender(): string
	{
		$absender = trim((string) $this->wert('kinderschutzAbsender'));

		return '' !== $absender ? $absender : trim((string) $this->wert('adminEmail'));
	}

	/**
	 * Liefert die Frist des Papierkorbs.
	 *
	 * @return int Tage, Vorgabe 30, mindestens 1
	 */
	public function papierkorbTage(): int
	{
		return $this->zahl('kinderschutzPapierkorbTage', 30);
	}

	/**
	 * Liefert den Vorlauf der Erinnerung vor Ablauf.
	 *
	 * @return int Tage (eingestellt sind Wochen, Vorgabe 8)
	 */
	public function vorlaufAblaufTage(): int
	{
		return 7 * $this->zahl('kinderschutzVorlaufAblauf', 8);
	}

	/**
	 * Liefert den Vorlauf der Mail vor einer Veranstaltung.
	 *
	 * @return int Tage (eingestellt sind Wochen, Vorgabe 6)
	 */
	public function vorlaufVeranstaltungTage(): int
	{
		return 7 * $this->zahl('kinderschutzVorlaufVeranstaltung', 6);
	}

	/**
	 * Liest eine positive Zahl mit Vorgabe.
	 *
	 * @param string $schluessel Name in der localconfig
	 * @param int    $vorgabe    Wert, wenn nichts oder 0 eingetragen ist
	 *
	 * @return int Der Wert, mindestens 1
	 */
	private function zahl(string $schluessel, int $vorgabe): int
	{
		$wert = (int) $this->wert($schluessel);

		return $wert > 0 ? $wert : $vorgabe;
	}

	/**
	 * Liest einen Wert aus der localconfig.
	 *
	 * @param string $schluessel Name in der localconfig
	 *
	 * @return mixed Der gespeicherte Wert, oder null
	 */
	private function wert(string $schluessel): mixed
	{
		$this->framework->initialize();

		return $this->framework->getAdapter(Config::class)->get($schluessel);
	}
}
