<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;

/**
 * Sperrt die Bearbeitungsmasken der Dokumentarten, Veranstaltungen und
 * Teilnahmen für alle ohne Prüferfreigabe.
 *
 * tl_kinderschutz prüft im NachweisListener selbst, weil dessen onload noch
 * mehr zu tun hat. Ein onload_callback läuft nur, wenn Contao für die
 * Tabelle einen Data Container baut – also nicht beim bloßen Laden der DCA
 * (etwa für die Rechteauswahl in tl_user_group).
 */
class ZugangListener
{
	/**
	 * @param Zugang $zugang Die Zugangsprüfung
	 */
	public function __construct(private readonly Zugang $zugang)
	{
	}

	/**
	 * Bricht für Unberechtigte mit „Zugriff verweigert“ ab.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 */
	#[AsCallback(table: 'tl_kinderschutz_dokumentart', target: 'config.onload')]
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'config.onload')]
	#[AsCallback(table: 'tl_kinderschutz_teilnahme', target: 'config.onload')]
	public function __invoke(): void
	{
		$this->zugang->verlange();
	}
}
