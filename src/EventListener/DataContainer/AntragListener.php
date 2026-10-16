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
use Contao\DataContainer;
use Contao\Date;
use Contao\Input;
use Contao\StringUtil;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Freischaltung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\MeldungListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Aktion;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Kopiersperre;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Verschiebesperre;

/**
 * Callbacks von tl_kinderschutz_antrag: Zugang, Palettenwahl,
 * Eingabeprüfung, Infokasten, Folgen nach dem Speichern, Beschriftung.
 */
class AntragListener
{
	/**
	 * Die Aktionen, die mehrere Datensätze auf einmal bearbeiten.
	 *
	 * Kleingeschrieben, weil „act“ vor dem Vergleich kleingeschrieben wird
	 * (siehe Aktion::act()).
	 */
	private const SAMMELAKTIONEN = array('select', 'editall', 'overrideall', 'deleteall', 'copyall');

	/**
	 * Nimmt die Dienste entgegen; der Listener selbst hält keinen Zustand.
	 *
	 * @param Zugang                  $zugang          Prüferfreigabe und ID des Prüfers
	 * @param AntragRepository        $antraege        Die Anträge
	 * @param PersonenRepository      $personen        Namen, Auswahl, Prüfernamen
	 * @param VeranstaltungRepository $veranstaltungen Namen der Veranstaltungen
	 * @param Einstellungen           $einstellungen   Die Freischaltgruppe
	 * @param Freischaltung           $freischaltung   Prüfung und Folgen
	 * @param MeldungListener         $meldungen       Hinweise, die „Speichern und schließen“ überstehen
	 * @param ContaoFramework         $framework       Input-Adapter: „act“, „mode“ und die
	 *                                                 Begründung, wie Contao sie liest
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly AntragRepository $antraege,
		private readonly PersonenRepository $personen,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly Einstellungen $einstellungen,
		private readonly Freischaltung $freischaltung,
		private readonly MeldungListener $meldungen,
		private readonly ContaoFramework $framework,
	) {
	}

	/**
	 * Prüft den Zugang und richtet die Maske für den Datensatz ein.
	 *
	 * - Alle Sammelaktionen (Auswahl, „Mehrere bearbeiten“, „Überschreiben“,
	 *   „Mehrere löschen“, „Mehrere kopieren“) sind gesperrt: Jede
	 *   Entscheidung braucht den Infokasten und zieht Gruppe, Teilnahmen und
	 *   Mails nach. Die Liste bietet sie nicht an, weil die DCA leere
	 *   global_operations trägt (sonst ergänzte Contao 5.7 „Mehrere
	 *   bearbeiten“ über den DefaultGlobalOperationsListener; 4.13 kennt den
	 *   Knopf ohnehin nur, wenn die DCA ihn nennt). Erreichbar wären sie nur
	 *   noch über eine von Hand gebaute Adresse.
	 * - Verschieben (cut, cutAll, paste mit mode=cut) sperrt
	 *   Verschiebesperre mit 403; „notSortable“ allein ergäbe in 4.13 HTTP 500.
	 * - Kopieren (copy, auch einzeln) sperrt Kopiersperre mit 403;
	 *   „notCopyable“ allein ergäbe in 4.13 HTTP 500.
	 * - Ein Datensatz ohne Stand kommt aus „Mitglied freischalten“ und
	 *   bekommt die Palette „direkt“; ein entschiedener nur den Infokasten.
	 *   Alle anderen Aktionen (Liste, show, delete) lassen die Paletten in Ruhe.
	 *
	 * Seiteneffekt: ändert $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default'].
	 *
	 * @param DataContainer|null $dc Der Data Container; null kommt in 4.13 bei
	 *                               manchen Aufrufen aus der Liste vor
	 *
	 * @throws AccessDeniedException Ohne Prüferfreigabe, bei einer Sammelaktion, beim Verschieben oder Kopieren
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'config.onload')]
	public function beimLaden(?DataContainer $dc = null): void
	{
		$this->zugang->verlange();
		Verschiebesperre::pruefe($this->framework);
		Kopiersperre::pruefe($this->framework);
		// Über Aktion: so, wie Contao „act“ für $dc->$act() liest (4.13
		// normalisiert Werte und Schlüssel), und kleingeschrieben, weil
		// „act=editall“ oder „act=Edit“ dieselbe Methode ausführen.
		$act = Aktion::act($this->framework);

		if (\in_array($act, self::SAMMELAKTIONEN, true)) {
			throw new AccessDeniedException('Anträge lassen sich nur einzeln bearbeiten.');
		}

		if ('edit' !== $act || null === $dc || (int) $dc->id <= 0) {
			return;
		}

		$zeile = $this->antraege->zeile((int) $dc->id);

		if (null === $zeile) {
			return;
		}

		if ('' === (string) $zeile['status']) {
			$GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default'] = $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['direkt'];
		} elseif ((int) $zeile['entschiedenAm'] > 0) {
			$GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default'] = '{antrag_legend},info';
		}
	}

	/**
	 * Prüft die Entscheidung, bevor Contao sie speichert.
	 *
	 * Die Begründung kommt aus dem Formular: Sie steht in der Palette nach
	 * dem Stand und ist beim Speichern des Stands noch nicht geschrieben.
	 * Gelesen wird über den Input-Adapter mit Input::post('grund', true) –
	 * genau so, wie das Textfeld (decodeEntities) den Wert speichert. Der
	 * Symfony-Request taugt nicht: Contao 4.13 normalisiert auch die Namen der
	 * POST-Felder (cleanKey, der letzte gleiche Name gewinnt), so dass
	 * „grund=x&gr%0Dund=“ die Prüfung mit „x“ bestanden und „“ gespeichert
	 * hätte. Der Wert kann je Fassung teils kodiert ankommen (4.13 ohne
	 * decodeEntities gemessen: „&lt;b&#62;&lt;/b&#62;“); pruefeEntscheidung()
	 * entkodiert und entfernt Tags vor der Leerprüfung, versteht also jede Form.
	 * Ein Feldsuffix wie in „Mehrere bearbeiten“ (grund_3) gibt es nicht,
	 * weil beimLaden() alle Sammelaktionen sperrt.
	 *
	 * @param mixed         $wert Gewählte Entscheidung
	 * @param DataContainer $dc   Der Data Container
	 *
	 * @return mixed Der unveränderte Wert
	 *
	 * @throws \RuntimeException Mit der Meldung, die Contao am Feld anzeigt
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'fields.status.save')]
	public function statusPruefen(mixed $wert, DataContainer $dc): mixed
	{
		$grund = (string) $this->framework->getAdapter(Input::class)->post('grund', true);
		$fehler = $this->freischaltung->pruefeEntscheidung($this->antraege->zeile((int) $dc->id), (string) $wert, $grund, time());

		if (null !== $fehler) {
			throw new \RuntimeException($GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$fehler] ?? $fehler);
		}

		return $wert;
	}

	/**
	 * Prüft die Mitgliedsauswahl in „Mitglied freischalten“.
	 *
	 * Die Auswahlliste enthält zwar nur passende Mitglieder, aber zwischen
	 * Laden und Speichern kann sich das ändern (gesperrt, in einem zweiten
	 * Fenster schon freigeschaltet, Gruppe in den Einstellungen geleert).
	 *
	 * @param mixed         $wert ID des Mitglieds
	 * @param DataContainer $dc   Der Data Container
	 *
	 * @return mixed Der unveränderte Wert
	 *
	 * @throws \RuntimeException Mit der Meldung, die Contao am Feld anzeigt
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'fields.member.save')]
	public function mitgliedPruefen(mixed $wert, DataContainer $dc): mixed
	{
		$fehler = $this->freischaltung->pruefeDirekt((int) $wert, time());

		if (null !== $fehler) {
			throw new \RuntimeException($GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$fehler] ?? $fehler);
		}

		return $wert;
	}

	/**
	 * Liefert die Mitglieder, die sich direkt freischalten lassen.
	 *
	 * Ohne gewählte Freischaltgruppe ist die Liste leer
	 * (PersonenRepository::aktiveOhneGruppe() bei Gruppe 0); das Speichern
	 * meldet dann „keineGruppe“ über mitgliedPruefen().
	 *
	 * @return array<int, string> ID → „Nachname, Vorname (Mail)“
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'fields.member.options')]
	public function mitgliedOptionen(): array
	{
		$optionen = array();

		foreach ($this->personen->aktiveOhneGruppe($this->einstellungen->gruppe(), time()) as $person) {
			$optionen[(int) $person['id']] = PersonenRepository::name($person).' ('.$person['email'].')';
		}

		return $optionen;
	}

	/**
	 * Baut den Infokasten mit dem Antrag und gegebenenfalls der Entscheidung.
	 *
	 * Alle Benutzereingaben werden maskiert (ohne doppelte Kodierung, kodiert
	 * gespeicherte Zeichen erscheinen also richtig); die Begründungen
	 * behalten ihre Zeilenumbrüche. Eine gelöschte Veranstaltung erscheint
	 * mit ihrer ID. Die Begründung erscheint nur bei einer Ablehnung.
	 *
	 * @param DataContainer $dc Der Data Container
	 *
	 * @return string HTML des Kastens, leer ohne Datensatz
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'fields.info.input_field')]
	public function info(DataContainer $dc): string
	{
		$zeile = $this->antraege->zeile((int) $dc->id);

		if (null === $zeile) {
			return '';
		}

		$t = $GLOBALS['TL_LANG']['tl_kinderschutz_antrag'];
		$namen = array();

		foreach (AntragRepository::veranstaltungenAus($zeile['veranstaltungen'] ?? null) as $id) {
			$veranstaltung = $this->veranstaltungen->eine($id);
			$namen[] = null === $veranstaltung ? '#'.$id : (string) $veranstaltung['name'];
		}

		$zeilen = array(
			$t['member'][0]           => StringUtil::specialchars($this->name((int) $zeile['member'], true)),
			$t['beantragtAm']         => (int) $zeile['beantragtAm'] > 0 ? Date::parse('d.m.Y H:i', (int) $zeile['beantragtAm']) : '–',
			$t['wofuer'][0]           => nl2br(StringUtil::specialchars((string) $zeile['wofuer'])),
			$t['veranstaltungenKopf'] => array() === $namen ? $t['keineVeranstaltungen'] : StringUtil::specialchars(implode(', ', $namen)),
		);

		if ((int) $zeile['entschiedenAm'] > 0) {
			$stand = ($t['stati'][(string) $zeile['status']] ?? (string) $zeile['status']).' '.sprintf($t['entschieden'], Date::parse('d.m.Y H:i', (int) $zeile['entschiedenAm']), $this->personen->benutzerName((int) $zeile['entschiedenVon']));
			$zeilen[$t['status'][0]] = StringUtil::specialchars($stand).('1' === (string) $zeile['direkt'] ? '<br>'.$t['direktVermerk'] : '');

			// Nur bei einer Ablehnung: Contao 4.13 speichert die Felder einzeln
			// und schreibt „grund“ auch dann, wenn die Prüfung des Stands
			// scheitert. Ein Rest aus einem gescheiterten Ablehnversuch stünde
			// sonst bei einem später freigeschalteten Antrag.
			if (AntragRepository::ABGELEHNT === (string) $zeile['status'] && '' !== trim((string) $zeile['grund'])) {
				$zeilen[$t['grund'][0]] = nl2br(StringUtil::specialchars((string) $zeile['grund']));
			}
		}

		$html = '';

		foreach ($zeilen as $kopf => $wert) {
			$html .= '<tr><th scope="row">'.StringUtil::specialchars((string) $kopf).'</th><td>'.$wert.'</td></tr>';
		}

		return '<div class="widget clr ks-antrag"><table class="tl_show"><tbody>'.$html.'</tbody></table></div>';
	}

	/**
	 * Zieht nach dem Speichern die Folgen und merkt einen Mailfehler vor.
	 *
	 * Der Hinweis geht wie bei der Prüfung von Unterlagen über den
	 * MeldungListener, weil DC_Table bei „Speichern und schließen“
	 * Message::reset() aufruft.
	 *
	 * Seiteneffekte: über Freischaltung::nachSpeichern() Schreibzugriffe auf
	 * Antrag, Mitgliedergruppen und Teilnahmen sowie die Mail an das Mitglied.
	 *
	 * @param DataContainer $dc Der Data Container
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'config.onsubmit')]
	public function nachSpeichern(DataContainer $dc): void
	{
		if (!$this->freischaltung->nachSpeichern((int) $dc->id, $this->zugang->benutzerId(), time())) {
			$this->meldungen->vormerken($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['antragMailGescheitert']);
		}
	}

	/**
	 * Beschriftet eine Zeile: Name, Antragsdatum, „direkt“, Anfang der Begründung.
	 *
	 * Den Stand zeigt der Gruppenkopf der Liste. Die Begründung wird vor dem
	 * Kürzen entkodiert und danach wieder maskiert, damit der Schnitt keine
	 * kodiert gespeicherte Entität („&#40;“) zerteilt.
	 *
	 * @param array<string, mixed> $zeile Der Datensatz
	 * @param string               $label Contaos Vorschlag, unbenutzt
	 * @param DataContainer|null   $dc    Der Data Container, unbenutzt
	 * @param array<mixed>|null    $args  Spaltenwerte (nur 5.7), unbenutzt
	 *
	 * @return string HTML der Beschriftung
	 */
	#[AsCallback(table: 'tl_kinderschutz_antrag', target: 'list.label.label')]
	public function beschriftung(array $zeile, string $label = '', ?DataContainer $dc = null, ?array $args = null): string
	{
		$datum = (int) ($zeile['beantragtAm'] ?? 0) > 0 ? Date::parse('d.m.Y', (int) $zeile['beantragtAm']) : '–';
		$direkt = '1' === (string) ($zeile['direkt'] ?? '') ? ' · '.($GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['direktKurz'] ?? 'direkt') : '';
		$wofuer = mb_substr(trim(html_entity_decode((string) ($zeile['wofuer'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 80);

		return sprintf('<strong>%s</strong> <span class="tl_gray">(%s%s)</span> %s', StringUtil::specialchars($this->name((int) ($zeile['member'] ?? 0), false)), $datum, $direkt, StringUtil::specialchars($wofuer));
	}

	/**
	 * Liefert den Anzeigenamen eines Mitglieds.
	 *
	 * @param int  $id      ID des Mitglieds
	 * @param bool $mitMail Mailadresse in Klammern anhängen
	 *
	 * @return string „Nachname, Vorname“, oder „Mitglied gelöscht (#ID)“,
	 *                wenn es das Mitglied nicht mehr gibt
	 */
	private function name(int $id, bool $mitMail): string
	{
		$person = $this->personen->mitglied($id);

		if (null === $person) {
			return sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['mitgliedGeloescht'] ?? '#%d', $id);
		}

		return PersonenRepository::name($person).($mitMail ? ' ('.$person['email'].')' : '');
	}
}
