<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Legt hochgeladene Dateien außerhalb des Webroots ab.
 *
 * Aufbau: <speicherpfad>/<Mitglieds-ID>/<32 Hex-Zeichen>.<pdf|jpg|png>. Der
 * Name ist zufällig, damit niemand Dateinamen erraten kann, falls der Ordner
 * durch eine Fehlkonfiguration doch erreichbar wäre. Zusätzlich liegt im
 * Wurzelordner eine .htaccess mit „Require all denied“.
 *
 * Relative Pfade aus der Datenbank werden vor jedem Zugriff gegen ein festes
 * Muster geprüft. So kann ein manipulierter Datensatz keine Datei außerhalb
 * des Ordners lesen oder löschen lassen.
 */
class Dateiablage
{
	/** Muster gültiger relativer Pfade */
	private const MUSTER = '#^\d+/[0-9a-f]{32}\.(pdf|jpg|png)$#';

	/**
	 * @param string $wurzel Absoluter Speicherpfad (Bundle-Parameter „speicherpfad“)
	 */
	public function __construct(private readonly string $wurzel)
	{
	}

	/**
	 * Verschiebt eine hochgeladene Datei in die Ablage.
	 *
	 * @param UploadedFile $datei    Die geprüfte Datei
	 * @param int          $mitglied ID des Mitglieds, wird Unterordner
	 * @param string       $endung   Endung aus dem UploadPruefer
	 *
	 * @return string Der relative Pfad für die Spalte „datei“
	 */
	public function speichere(UploadedFile $datei, int $mitglied, string $endung): string
	{
		$this->schuetzeWurzel();

		$relativ = $mitglied.'/'.bin2hex(random_bytes(16)).'.'.$endung;
		$datei->move($this->wurzel.'/'.$mitglied, basename($relativ));

		return $relativ;
	}

	/**
	 * Liefert den absoluten Pfad einer abgelegten Datei.
	 *
	 * @param string $relativ Pfad aus der Spalte „datei“
	 *
	 * @return string|null Absoluter Pfad, oder null, wenn der Pfad ungültig ist
	 *                     oder die Datei fehlt
	 */
	public function pfad(string $relativ): ?string
	{
		if (1 !== preg_match(self::MUSTER, $relativ)) {
			return null;
		}

		$pfad = $this->wurzel.'/'.$relativ;

		return is_file($pfad) ? $pfad : null;
	}

	/**
	 * Löscht eine abgelegte Datei.
	 *
	 * Eine schon fehlende Datei gilt als gelöscht; das ist kein Fehler, weil
	 * das Ziel – die Datei ist weg – erreicht ist.
	 *
	 * Die Warnung von unlink() wird unterdrückt, weil das Scheitern als
	 * eigene Ausnahme gemeldet wird: Symfony schriebe sie sonst samt
	 * absolutem Pfad ins Protokoll und machte im Debug-Modus eine
	 * ErrorException daraus, die an den Aufrufern (sie fangen
	 * RuntimeException) vorbei auf eine Fehlerseite führte.
	 *
	 * @param string $relativ Pfad aus der Spalte „datei“
	 *
	 * @throws \RuntimeException Wenn eine vorhandene Datei nicht gelöscht
	 *                           werden konnte; die Meldung nennt nur den
	 *                           relativen Pfad
	 */
	public function loesche(string $relativ): void
	{
		$pfad = $this->pfad($relativ);

		if (null !== $pfad && !@unlink($pfad)) {
			throw new \RuntimeException('Datei konnte nicht gelöscht werden: '.$relativ);
		}
	}

	/**
	 * Legt den Wurzelordner samt .htaccess an, falls nötig.
	 */
	private function schuetzeWurzel(): void
	{
		if (!is_dir($this->wurzel) && !mkdir($this->wurzel, 0770, true) && !is_dir($this->wurzel)) {
			throw new \RuntimeException('Ablageordner konnte nicht angelegt werden: '.$this->wurzel);
		}

		if (!is_file($this->wurzel.'/.htaccess')) {
			file_put_contents($this->wurzel.'/.htaccess', "Require all denied\n");
		}
	}
}
