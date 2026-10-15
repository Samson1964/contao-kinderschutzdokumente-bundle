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
 * Prüft eine hochgeladene Datei auf Typ und Größe.
 *
 * Der Typ wird mit finfo am Inhalt erkannt, nicht an der Endung oder am
 * vom Browser mitgeschickten Content-Type – beides bestimmt der Absender.
 * Die gespeicherte Datei bekommt die Endung des erkannten Typs.
 */
final class UploadPruefer
{
	/** Zulässige MIME-Typen und die Endung, unter der sie gespeichert werden */
	public const TYPEN = array(
		'application/pdf' => 'pdf',
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
	);

	/**
	 * @param int $hoechstgroesse Größte zulässige Datei in Bytes
	 */
	public function __construct(private readonly int $hoechstgroesse)
	{
	}

	/**
	 * Erzeugt den Prüfer mit der wirksamen Höchstgröße dieser Installation.
	 *
	 * Fabrik für den Dienstcontainer (services.yaml), weil die php.ini erst zur
	 * Laufzeit gelesen werden kann.
	 *
	 * @param int $megabyte Bundle-Parameter upload_max_mb
	 *
	 * @return self Der Prüfer
	 */
	public static function mitGrenzen(int $megabyte): self
	{
		return new self(self::wirksameGroesse($megabyte, (string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size')));
	}

	/**
	 * Prüft eine Datei und nennt den ersten Fehler.
	 *
	 * @param string $pfad         Pfad der hochgeladenen Datei im Dateisystem
	 * @param int    $groesse      Größe in Bytes
	 * @param int    $uploadFehler PHP-Fehlercode des Uploads (UPLOAD_ERR_*)
	 *
	 * @return string|null Schlüssel der Fehlermeldung („zuGross“, „upload“,
	 *                     „leer“, „typ“), oder null, wenn die Datei passt
	 */
	public function fehler(string $pfad, int $groesse, int $uploadFehler): ?string
	{
		if (UPLOAD_ERR_INI_SIZE === $uploadFehler || UPLOAD_ERR_FORM_SIZE === $uploadFehler) {
			return 'zuGross';
		}

		if (UPLOAD_ERR_OK !== $uploadFehler || !is_file($pfad)) {
			return 'upload';
		}

		if ($groesse <= 0) {
			return 'leer';
		}

		if ($groesse > $this->hoechstgroesse) {
			return 'zuGross';
		}

		if (null === $this->endung($pfad)) {
			return 'typ';
		}

		return null;
	}

	/**
	 * Liefert die Endung, unter der die Datei gespeichert wird.
	 *
	 * @param string $pfad Pfad der Datei im Dateisystem
	 *
	 * @return string|null „pdf“, „jpg“ oder „png“, oder null bei einem
	 *                     unzulässigen Typ
	 */
	public function endung(string $pfad): ?string
	{
		$typ = (new \finfo(FILEINFO_MIME_TYPE))->file($pfad);

		return self::TYPEN[$typ] ?? null;
	}

	/**
	 * Liefert die Höchstgröße in Bytes.
	 *
	 * @return int Größte zulässige Datei
	 */
	public function hoechstgroesse(): int
	{
		return $this->hoechstgroesse;
	}

	/**
	 * Ermittelt die wirksame Höchstgröße aus Bundle-Parameter und php.ini.
	 *
	 * Ist upload_max_filesize oder post_max_size kleiner als der Parameter,
	 * käme eine größere Datei gar nicht erst an; dann soll die Meldung im
	 * Frontend die tatsächliche Grenze nennen.
	 *
	 * @param int    $megabyte  Bundle-Parameter upload_max_mb
	 * @param string $uploadMax Wert von upload_max_filesize, etwa „40M“
	 * @param string $postMax   Wert von post_max_size; „0“ heißt unbegrenzt
	 *
	 * @return int Die kleinste der Grenzen in Bytes
	 */
	public static function wirksameGroesse(int $megabyte, string $uploadMax, string $postMax): int
	{
		$grenzen = array($megabyte * 1024 * 1024);

		foreach (array($uploadMax, $postMax) as $wert) {
			$bytes = self::bytes($wert);

			if ($bytes > 0) {
				$grenzen[] = $bytes;
			}
		}

		return min($grenzen);
	}

	/**
	 * Wandelt eine php.ini-Größe („512K“, „40M“, „2G“) in Bytes.
	 *
	 * @param string $wert Wert aus der php.ini
	 *
	 * @return int Bytes, 0 bei leer oder unbegrenzt
	 */
	private static function bytes(string $wert): int
	{
		$wert = trim($wert);

		if ('' === $wert) {
			return 0;
		}

		$zahl = (int) $wert;

		return match (strtoupper(substr($wert, -1))) {
			'G' => $zahl * 1024 * 1024 * 1024,
			'M' => $zahl * 1024 * 1024,
			'K' => $zahl * 1024,
			default => $zahl,
		};
	}
}
