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
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Aktion;

/**
 * Verbirgt Kinderschutz-Kopien unter System → Wiederherstellen (tl_undo) vor
 * allen ohne Prüferfreigabe und sperrt ihren Einzelaufruf.
 *
 * Contao legt vor jedem Löschen eine Kopie des Datensatzes in tl_undo ab
 * (fromTable, data serialisiert, dazu in beiden Fassungen eine fertige
 * Vorschau in „preview“: RecordPreviewListener schreibt sie, 4.13 Z. 72,
 * Undo\LabelListener zeigt sie in der Liste, 4.13 Z. 67). Nicht-Administratoren sehen dort nur
 * eigene Schritte, Administratoren alle:
 *
 * - 4.13: tl_undo::checkPermission() (dca/tl_undo.php) setzt für
 *   Nicht-Admins „root“ auf die eigenen Schritte und verweigert jede Aktion
 *   auf fremde IDs; Admins kehren sofort zurück.
 * - 5.7: tl_undo::adjustDca() (contao/dca/tl_undo.php) hängt für
 *   Nicht-Admins den Filter „pid=?“ an, der UndoVoter prüft Einzelaufrufe
 *   gegen pid; ROLE_ADMIN darf alles.
 *
 * Damit läsen Administratoren ohne Freigabe gelöschte Nachweise und Anträge
 * 30 Tage lang im Klartext und könnten sie wiederherstellen. Dieser Listener
 * ergänzt deshalb für jeden Benutzer ohne Freigabe – Admin oder nicht –
 * einen Listenfilter und sperrt Einzelaufrufe einer Kinderschutz-Kopie. Der
 * Kernfilter bleibt unberührt; Hauptadmin und Prüfer sehen alles wie bisher.
 *
 * Der Filter wirkt in beiden Fassungen gleich: DC_Table übernimmt
 * list.sorting.filter (4.13 DC_Table Z. 281–295, 5.7 Z. 499–513) in
 * procedure/values, bevor Liste, Filterauswahl (SELECT DISTINCT über
 * dieselbe procedure), Suche und Auswahl („select“) rechnen. Auch die
 * 5.7-Anfrage mit Kopfzeile „Contao-Operations“ (Knöpfe einer einzelnen
 * Zeile) rendert über die gefilterte Liste und findet die Zeile nicht.
 *
 * Sammelaktionen: tl_undo ist in beiden Fassungen „closed“, „notEditable“,
 * „notCopyable“ und „notDeletable“; editAll, overrideAll, copyAll und
 * deleteAll brechen im Kern ab, „select“ zeigt nur die gefilterte Liste.
 * Nicht gesperrt ist dagegen das Verschieben (kein „notSortable“): cutAll
 * arbeitet mit den IDs aus einem POST, nicht aus der Liste. Deshalb sperrt
 * der Listener cut, cutAll und paste für Benutzer ohne Freigabe pauschal.
 */
class UndoListener
{
	/**
	 * Bedingung für den Listenfilter. „!“ macht den Unterstrich, der in LIKE
	 * sonst für ein beliebiges Zeichen steht, zum festen Zeichen. Ein
	 * eigenes Fluchtzeichen statt „\“, weil SQLite (Tests) ohne ESCAPE gar
	 * keines kennt und MySQL „\“ je nach sql_mode anders behandelt.
	 */
	public const FILTER = "fromTable NOT LIKE ? ESCAPE '!'";

	/**
	 * Gemeinsamer Anfang aller Tabellen des Bundles.
	 */
	public const PRAEFIX = 'tl_kinderschutz';

	/**
	 * Nimmt Zugangsprüfung, Framework und Datenbank entgegen.
	 *
	 * @param Zugang          $zugang    Sagt, ob jemand angemeldet ist und prüfen darf
	 * @param ContaoFramework $framework Liefert über den Input-Adapter „act“ und
	 *                                   „id“, wie Contao sie liest
	 * @param Connection      $db        Liest die Herkunftstabelle einer Kopie
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly ContaoFramework $framework,
		private readonly Connection $db,
	) {
	}

	/**
	 * Setzt für Benutzer ohne Freigabe den Filter und sperrt Einzelaufrufe.
	 *
	 * Läuft als onload_callback, also erst, wenn Contao für tl_undo einen
	 * Data Container baut – vor $dc->show(), $dc->undo() usw. (4.13 und 5.7:
	 * Konstruktor von DC_Table). Ohne Backend-Benutzer (Konsole, Cron) und für
	 * Hauptadmin und Prüfer geschieht nichts.
	 *
	 * Seiteneffekt: ergänzt $GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter'].
	 *
	 * @throws AccessDeniedException Bei cut, cutAll und paste (in jeder
	 *                               Schreibweise), bei einem Einzelaufruf („act“
	 *                               mit „id“) einer Kinderschutz-Kopie, bei einer ID, die nicht
	 *                               nur aus Ziffern besteht, oder einem „act“
	 *                               außerhalb der Buchstaben (Aktion::act)
	 */
	#[AsCallback(table: 'tl_undo', target: 'config.onload')]
	public function __invoke(): void
	{
		if (0 === $this->zugang->benutzerId() || $this->zugang->darfPruefen()) {
			return;
		}

		$GLOBALS['TL_DCA']['tl_undo']['list']['sorting']['filter'][] = array(self::FILTER, str_replace('_', '!_', self::PRAEFIX).'%');

		$act = Aktion::act($this->framework);

		if ('' === $act) {
			return;
		}

		// tl_undo ist nicht „notSortable“: Ein nachgebauter tl_select-POST mit
		// „act=cutAll&pid=N&mode=2“ schriebe sonst über cut() und
		// getNewPosition() den Besitzer (pid) auch verborgener Schritte um
		// (5.7 DC_Table::cutAll() ab Z. 977). Contao bietet Verschieben hier
		// nirgends an; ohne ID-Bezug geht das nur pauschal.
		if (\in_array($act, array('cut', 'cutall', 'paste'), true)) {
			throw new AccessDeniedException('Verschieben ist unter „Wiederherstellen“ gesperrt.');
		}

		$id = $this->framework->getAdapter(Input::class)->get('id');

		if (null === $id || '' === $id) {
			return;
		}

		// MySQL läse „5abc“ als 5; eine solche Adresse ist von Hand gebaut
		if (!\is_string($id) || 1 !== preg_match('/^\d+$/D', $id)) {
			throw new AccessDeniedException('Ungültige ID für „Wiederherstellen“.');
		}

		if ($this->istKinderschutz((int) $id)) {
			throw new AccessDeniedException(sprintf('Kein Zugang zum Wiederherstellungsschritt %d.', $id));
		}
	}

	/**
	 * Sagt, ob eine Kopie aus einer Tabelle des Bundles stammt.
	 *
	 * Verglichen wird wie im Listenfilter ohne Rücksicht auf die Schreibweise
	 * (MySQL vergleicht mit der Sortierfolge der Spalte meist ebenso).
	 * Maßgeblich ist fromTable: Keine fremde Tabelle führt eine Tabelle des
	 * Bundles als ctable, eine Kinderschutz-Zeile steckt also nie in der Kopie
	 * eines anderen Datensatzes.
	 *
	 * @param int $id ID in tl_undo
	 *
	 * @return bool true für eine Kinderschutz-Kopie; false für fremde und
	 *              unbekannte IDs (dann antwortet Contao selbst)
	 */
	private function istKinderschutz(int $id): bool
	{
		$tabelle = $this->db->fetchOne('SELECT fromTable FROM tl_undo WHERE id = ?', array($id));

		return \is_string($tabelle) && str_starts_with(strtolower($tabelle), self::PRAEFIX);
	}
}
