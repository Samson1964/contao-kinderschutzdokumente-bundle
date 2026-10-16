<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;

/**
 * Bietet in den Listen der Kinderschutz-Module „Mehrere bearbeiten“ an.
 *
 * Contao 5.7 ergänzt die globale Operation „all“ (act=select) für jede
 * DC_Table-Tabelle selbst (DefaultGlobalOperationsListener, Hook
 * loadDataContainer mit Priorität 200). Contao 4.13 zeigt sie nur, wenn die
 * DCA sie nennt; im Prüfstand 4.13 fehlte der Knopf deshalb, obwohl
 * „Mehrere bearbeiten“ und „Überschreiben“ (mehrere Nachweise ablehnen,
 * Teilnehmer setzen) funktionieren.
 *
 * Der Eintrag steht absichtlich nicht in den DCA-Dateien: In 5.7 ersetzte er
 * Contaos Vorgabe (Vorabladen, Scroll-Position über Stimulus). Dieser Hook
 * läuft mit Standardpriorität nach Contaos Listener und ergänzt „all“ nur,
 * wenn es noch fehlt – in 5.7 also nie.
 *
 * Die Papierkorb-Ansicht ersetzt die globalen Operationen später im
 * onload_callback (NachweisListener::papierkorbAnsicht()) und bietet „all“
 * deshalb nicht an.
 */
class SammelbearbeitungListener
{
	/**
	 * Die Tabellen mit eigenem Backend-Modul; tl_kinderschutz_teilnahme hat
	 * keins.
	 */
	public const TABELLEN = array('tl_kinderschutz', 'tl_kinderschutz_veranstaltung', 'tl_kinderschutz_dokumentart');

	/**
	 * Ergänzt die globale Operation „all“, wenn sie fehlt.
	 *
	 * Der Eintrag entspricht dem der Kerntabellen von Contao 4.13 (etwa
	 * tl_user): act=select, Klasse header_edit_all, Tastenkürzel „e“. Die
	 * Beschriftung „Mehrere bearbeiten“ holt Contao selbst aus den
	 * Sprachdateien.
	 *
	 * Seiteneffekt: ändert $GLOBALS['TL_DCA'][$tabelle] für die laufende
	 * Anfrage. Fremde und nicht geladene Tabellen bleiben unberührt.
	 *
	 * @param string $tabelle Name der gerade geladenen Tabelle
	 */
	#[AsHook('loadDataContainer')]
	public function ergaenzen(string $tabelle): void
	{
		if (!\in_array($tabelle, self::TABELLEN, true) || !isset($GLOBALS['TL_DCA'][$tabelle]) || isset($GLOBALS['TL_DCA'][$tabelle]['list']['global_operations']['all'])) {
			return;
		}

		$GLOBALS['TL_DCA'][$tabelle]['list']['global_operations']['all'] = array(
			'href'       => 'act=select',
			'class'      => 'header_edit_all',
			'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
		);
	}
}
