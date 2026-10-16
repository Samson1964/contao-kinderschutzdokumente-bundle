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
use Contao\CoreBundle\Framework\ContaoFramework;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Verschiebesperre;

/**
 * Sperrt die Bearbeitungsmasken der Dokumentarten, Veranstaltungen und
 * Teilnahmen für alle ohne Prüferfreigabe und das Verschieben für alle.
 *
 * tl_kinderschutz prüft im NachweisListener selbst, weil dessen onload noch
 * mehr zu tun hat. Ein onload_callback läuft nur, wenn Contao für die
 * Tabelle einen Data Container baut – also nicht beim bloßen Laden der DCA
 * (etwa für die Rechteauswahl in tl_user_group).
 */
class ZugangListener
{
	/**
	 * Nimmt die Zugangsprüfung und das Framework entgegen.
	 *
	 * @param Zugang          $zugang    Die Zugangsprüfung
	 * @param ContaoFramework $framework Liefert über den Input-Adapter die Aktion,
	 *                                   wie Contao sie liest (Verschiebesperre)
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly ContaoFramework $framework,
	) {
	}

	/**
	 * Bricht für Unberechtigte und beim Verschieben mit „Zugriff verweigert“ ab.
	 *
	 * Verschieben (cut, cutAll, paste mit mode=cut) sperrt Verschiebesperre;
	 * „notSortable“ in der DCA allein ergäbe in Contao 4.13 HTTP 500 statt 403.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung oder beim Verschieben
	 */
	#[AsCallback(table: 'tl_kinderschutz_dokumentart', target: 'config.onload')]
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'config.onload')]
	#[AsCallback(table: 'tl_kinderschutz_teilnahme', target: 'config.onload')]
	public function __invoke(): void
	{
		$this->zugang->verlange();
		Verschiebesperre::pruefe($this->framework);
	}
}
