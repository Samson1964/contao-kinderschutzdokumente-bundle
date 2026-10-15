<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Erinnerungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;

/**
 * Der tägliche Lauf: Papierkorb leeren, was fällig ist, und Erinnerungen
 * verschicken.
 *
 * Contao ruft ihn mit „web“ (Web-Cron beim Seitenaufruf) oder „cli“
 * (contao:cron) auf; der Bundle-Lauf ist in beiden Fällen derselbe. Die
 * README empfiehlt den echten Cron, weil der Web-Cron bei wenig Besuch
 * ausbleibt.
 */
#[AsCronJob('daily')]
class TaeglicherCron
{
	/**
	 * @param Papierkorb      $papierkorb   Löscht fällige Dateien
	 * @param Erinnerungen    $erinnerungen Verschickt die Mails
	 * @param LoggerInterface $protokoll    Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly Papierkorb $papierkorb,
		private readonly Erinnerungen $erinnerungen,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Führt den Lauf aus: fällige Dateien löschen, dann die Ablauf- und die
	 * Veranstaltungsmails verschicken.
	 *
	 * Die drei Schritte sind voneinander unabhängig. Wirft einer, wird das mit
	 * Schrittnamen und Fehlerklasse protokolliert und der Lauf macht mit dem
	 * nächsten weiter; sonst hielte etwa ein gesperrtes Mailsystem Nacht für
	 * Nacht auch das Löschen fälliger Dateien auf. Der gescheiterte Schritt
	 * zählt in der Zusammenfassung als 0.
	 *
	 * Seiteneffekte: löscht Dateien, verschickt Mails und schreibt ins
	 * Systemprotokoll – bei Arbeit eine Zusammenfassung, bei Fehlern je Schritt
	 * einen Fehlereintrag. Gab es nichts zu tun und keinen Fehler, bleibt das
	 * Protokoll still.
	 *
	 * @param string $scope „web“ oder „cli“, unbenutzt
	 */
	public function __invoke(string $scope = ''): void
	{
		$jetzt = time();
		$geloescht = $this->schritt('faelligeLoeschen', fn (): int => $this->papierkorb->faelligeLoeschen($jetzt));
		$ablauf = $this->schritt('ablaufVersenden', fn (): int => $this->erinnerungen->ablaufVersenden($jetzt));
		$veranstaltung = $this->schritt('veranstaltungenVersenden', fn (): int => $this->erinnerungen->veranstaltungenVersenden($jetzt));

		if ($geloescht + $ablauf + $veranstaltung > 0) {
			$this->protokoll->info(sprintf('Kinderschutz: täglicher Lauf – %d Dateien gelöscht, %d Ablauf- und %d Veranstaltungsmails', $geloescht, $ablauf, $veranstaltung));
		}
	}

	/**
	 * Führt einen Schritt des Laufs aus und fängt jeden Fehler ab.
	 *
	 * Gefangen wird \Throwable, nicht nur \Exception: Auch ein Programmfehler
	 * (TypeError und Ähnliches) in einem Schritt darf die übrigen nicht
	 * aufhalten. Protokolliert werden nur der Schrittname und die
	 * Fehlerklasse, nicht der Text der Ausnahme, denn der kann Dateipfade oder
	 * Mailadressen enthalten und das Systemprotokoll steht allen
	 * Administratoren offen.
	 *
	 * Seiteneffekte: schreibt bei einem Fehler einen Eintrag ins
	 * Systemprotokoll.
	 *
	 * @param string          $name   Name des Schritts für das Protokoll
	 * @param callable(): int $aktion Der Dienstaufruf; liefert die Zahl der erledigten Fälle
	 *
	 * @return int Die Zahl aus dem Dienstaufruf, oder 0, wenn er scheiterte
	 */
	private function schritt(string $name, callable $aktion): int
	{
		try {
			return $aktion();
		} catch (\Throwable $fehler) {
			$this->protokoll->error(sprintf('Kinderschutz: täglicher Lauf – Schritt „%s“ fehlgeschlagen (%s)', $name, $fehler::class));

			return 0;
		}
	}
}
