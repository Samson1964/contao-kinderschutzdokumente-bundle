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
use Contao\DataContainer;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;

/**
 * Legt beim Löschen eines Mitglieds dessen Dateien in den Papierkorb.
 *
 * Die Nachweise bleiben als „Mitglied gelöscht (#ID)“ stehen, bis der
 * Prüfer sie entfernt; die Dateien verschwinden mit der Papierkorbfrist.
 */
class MitgliedListener
{
	/**
	 * @param Papierkorb $papierkorb Nimmt die Dateien auf
	 */
	public function __construct(private readonly Papierkorb $papierkorb)
	{
	}

	/**
	 * Wird vor dem Löschen eines Mitglieds aufgerufen.
	 *
	 * @param DataContainer $dc Der Data Container von tl_member
	 */
	#[AsCallback(table: 'tl_member', target: 'config.ondelete')]
	public function __invoke(DataContainer $dc): void
	{
		$this->papierkorb->mitgliedEntfernt((int) $dc->id, time());
	}
}
