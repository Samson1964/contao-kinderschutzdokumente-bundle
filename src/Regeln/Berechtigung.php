<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln;

/**
 * Entscheidet, wer die Kinderschutz-Daten im Backend sehen darf.
 *
 * Zugang haben genau zwei Gruppen: der Hauptadmin (Bundle-Parameter
 * „hauptadmin“, Vorgabe ID 1) und Backend-Benutzer, bei denen er das Feld
 * kinderschutzPruefer gesetzt hat. Das Admin-Kennzeichen von Contao zählt
 * bewusst nicht – ein Administrator ohne Freigabe sieht nichts.
 */
final class Berechtigung
{
	/**
	 * @param int $hauptadmin ID des Hauptadmins in tl_user
	 */
	public function __construct(private readonly int $hauptadmin)
	{
	}

	/**
	 * Liefert die ID des Hauptadmins, etwa für den Mailversand.
	 *
	 * @return int ID in tl_user
	 */
	public function hauptadmin(): int
	{
		return $this->hauptadmin;
	}

	/**
	 * Sagt, ob ein Benutzer der Hauptadmin ist.
	 *
	 * @param int $benutzerId ID aus tl_user, 0 für „nicht angemeldet“
	 *
	 * @return bool true nur für den konfigurierten Hauptadmin
	 */
	public function istHauptadmin(int $benutzerId): bool
	{
		return $benutzerId > 0 && $benutzerId === $this->hauptadmin;
	}

	/**
	 * Sagt, ob ein Benutzer prüfen darf.
	 *
	 * @param int  $benutzerId  ID aus tl_user, 0 für „nicht angemeldet“
	 * @param bool $freigegeben Wert von tl_user.kinderschutzPruefer
	 *
	 * @return bool true für den Hauptadmin und freigegebene Benutzer
	 */
	public function darfPruefen(int $benutzerId, bool $freigegeben): bool
	{
		return $benutzerId > 0 && ($freigegeben || $this->istHauptadmin($benutzerId));
	}
}
