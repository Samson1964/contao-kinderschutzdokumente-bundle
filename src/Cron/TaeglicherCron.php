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
	 * Führt den Lauf aus.
	 *
	 * @param string $scope „web“ oder „cli“, unbenutzt
	 */
	public function __invoke(string $scope = ''): void
	{
		$jetzt = time();
		$geloescht = $this->papierkorb->faelligeLoeschen($jetzt);
		$ablauf = $this->erinnerungen->ablaufVersenden($jetzt);
		$veranstaltung = $this->erinnerungen->veranstaltungenVersenden($jetzt);

		if ($geloescht + $ablauf + $veranstaltung > 0) {
			$this->protokoll->info(sprintf('Kinderschutz: täglicher Lauf – %d Dateien gelöscht, %d Ablauf- und %d Veranstaltungsmails', $geloescht, $ablauf, $veranstaltung));
		}
	}
}
