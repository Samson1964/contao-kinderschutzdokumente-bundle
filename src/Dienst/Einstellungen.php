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
 * Liest die Einstellungen des Bundles aus System → Einstellungen.
 *
 * Kennt die Vorgaben der Spezifikation (30 Tage Papierkorb, 8 Wochen
 * Erinnerungsvorlauf, 6 Wochen vor Veranstaltungen), damit das Bundle auch
 * ohne gespeicherte Einstellungen vernünftig arbeitet. Initialisiert das
 * Contao-Framework selbst, weil der Cronjob auch von der Konsole läuft.
 */
class Einstellungen
{
	/**
	 * @param ContaoFramework $framework Zugang zu Config::get()
	 */
	public function __construct(private readonly ContaoFramework $framework)
	{
	}

	/**
	 * Liefert die kinderschutzpflichtigen Mitgliedergruppen.
	 *
	 * @return list<int> IDs aus tl_member_group, leer, wenn nichts gewählt ist
	 */
	public function gruppen(): array
	{
		$wert = $this->wert('kinderschutzGruppen');
		$liste = is_string($wert) && '' !== $wert ? @unserialize($wert, array('allowed_classes' => false)) : $wert;

		return is_array($liste) ? array_values(array_filter(array_map('intval', $liste))) : array();
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
