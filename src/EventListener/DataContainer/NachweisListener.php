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
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DataContainer;
use Contao\Date;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Pruefung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Callbacks von tl_kinderschutz: Zugang, Papierkorb-Ansicht, Vorbelegung,
 * Prüfmaske samt Dateivorschau, Eingabeprüfung, Folgen des Speicherns,
 * Löschsperre und Listenbeschriftung.
 */
class NachweisListener
{
	/**
	 * @param Zugang                $zugang        Prüft die Berechtigung
	 * @param Pruefung              $pruefung      Wertet das Speichern aus
	 * @param NachweisRepository    $nachweise     Zugriff auf tl_kinderschutz
	 * @param DokumentartRepository $arten         Namen und Regeln der Arten
	 * @param PersonenRepository    $personen      Namen von Mitgliedern und Prüfern
	 * @param Papierkorb            $papierkorb    Löschtermin im Papierkorb
	 * @param Einstellungen         $einstellungen Kinderschutzpflichtige Gruppen
	 * @param UrlGeneratorInterface $router        Datei-Route und Rücksprung
	 * @param RequestStack          $requestStack  Liefert do, act und id
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly Pruefung $pruefung,
		private readonly NachweisRepository $nachweise,
		private readonly DokumentartRepository $arten,
		private readonly PersonenRepository $personen,
		private readonly Papierkorb $papierkorb,
		private readonly Einstellungen $einstellungen,
		private readonly UrlGeneratorInterface $router,
		private readonly RequestStack $requestStack,
	) {
	}

	/**
	 * Prüft den Zugang und richtet die Ansicht ein.
	 *
	 * - do=kinderschutz_papierkorb: nur Datensätze mit Datei im Papierkorb,
	 *   eigene Operationen, nichts anlegen oder bearbeiten.
	 * - &member=ID ohne act: Liste auf eine Person gefiltert (Link aus der Matrix).
	 * - act=create mit member/dokumentart: Vorbelegung (Link aus der Matrix).
	 * - act=edit eines hochgeladenen Nachweises: Person und Art sind fest und
	 *   stehen nur noch im Kopf der Maske.
	 * - act=editAll und act=overrideAll („Mehrere bearbeiten“, „Überschreiben“):
	 *   Person und Art sind für alle Nachweise gesperrt, auch für Prüfvermerke
	 *   ohne Upload. Sonst ließe sich ein hochgeladenes Führungszeugnis dort
	 *   einer anderen Person oder Art zuordnen. Die Einzelmaske eines
	 *   Prüfvermerks ohne Upload bleibt der Weg, beides zu ändern.
	 *
	 * Zum Sperren reicht die Palette nicht: Die Feldauswahl beider Aktionen
	 * listet jedes Feld aus $GLOBALS['TL_DCA'][…]['fields'] mit inputType
	 * (DC_Table::editAll()/overrideAll(), Schritt 1, in 4.13 wie in 5.7), und
	 * „Überschreiben“ speichert die gewählten Felder ohne Blick auf die
	 * Palette. Die Auswahl selbst landet nur in der Session
	 * (CURRENT → tl_kinderschutz), act=select hat keine eigene Feldliste.
	 * Ohne inputType bietet Contao das Feld nicht an, und
	 * DataContainer::row() baut dafür kein Eingabefeld und speichert nichts –
	 * auch nicht, wenn eine nachgebaute Anfrage das Feld in all_fields
	 * schickt. Aus der Palette fliegen die Felder zusätzlich, damit
	 * „Mehrere bearbeiten“ row() für sie gar nicht erst aufruft.
	 *
	 * Seiteneffekt: verändert $GLOBALS['TL_DCA']['tl_kinderschutz'] für die
	 * laufende Anfrage.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'config.onload')]
	public function beimLaden(): void
	{
		$this->zugang->verlange();

		$anfrage = $this->requestStack->getCurrentRequest();
		$do = (string) $anfrage?->query->get('do');
		$act = (string) $anfrage?->query->get('act');
		$dca = &$GLOBALS['TL_DCA']['tl_kinderschutz'];

		if ('kinderschutz_papierkorb' === $do) {
			$this->papierkorbAnsicht();

			return;
		}

		$mitglied = (int) $anfrage?->query->get('member');

		if ('' === $act && $mitglied > 0) {
			$dca['list']['sorting']['filter'][] = array('member=?', $mitglied);
		}

		if ('create' === $act) {
			$dca['fields']['member']['default'] = $mitglied;
			$dca['fields']['dokumentart']['default'] = (int) $anfrage?->query->get('dokumentart');
		}

		if ('edit' === $act) {
			$zeile = $this->nachweise->zeile((int) $anfrage?->query->get('id'));

			if (null !== $zeile && 'frontend' === $zeile['quelle']) {
				$dca['palettes']['default'] = str_replace('{person_legend},member,dokumentart;', '', $dca['palettes']['default']);
			}
		}

		if ('editAll' === $act || 'overrideAll' === $act) {
			$dca['palettes']['default'] = str_replace('{person_legend},member,dokumentart;', '', $dca['palettes']['default']);
			unset($dca['fields']['member']['inputType'], $dca['fields']['dokumentart']['inputType']);
		}
	}

	/**
	 * Liefert die Auswahl der Personen für einen neuen Prüfvermerk.
	 *
	 * @param DataContainer|null $dc Der Data Container
	 *
	 * @return array<int, string> Mitglieder der kinderschutzpflichtigen Gruppen,
	 *                            dazu die bereits eingetragene Person
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'fields.member.options')]
	public function mitglieder(?DataContainer $dc = null): array
	{
		$optionen = array();

		foreach ($this->personen->mitgliederInGruppen($this->einstellungen->gruppen(), time()) as $person) {
			$optionen[$person['id']] = PersonenRepository::name($person);
		}

		$aktuell = null === $dc ? 0 : (int) ($this->nachweise->zeile((int) $dc->id)['member'] ?? 0);

		if ($aktuell > 0 && !isset($optionen[$aktuell])) {
			$person = $this->personen->mitglied($aktuell);
			$optionen[$aktuell] = null === $person ? '#'.$aktuell : PersonenRepository::name($person);
		}

		return $optionen;
	}

	/**
	 * Baut den Kopf der Prüfmaske: Person, Art, Verlauf und Dateivorschau.
	 *
	 * @param DataContainer $dc Der Data Container
	 *
	 * @return string HTML
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'fields.vorschau.input_field')]
	public function vorschau(DataContainer $dc): string
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be'];
		$zeile = $this->nachweise->zeile((int) $dc->id);

		if (null === $zeile) {
			return '';
		}

		$person = $this->personen->mitglied((int) $zeile['member']);
		$art = $this->arten->eine((int) $zeile['dokumentart']);
		$zeilen = array();

		if ((int) $zeile['member'] > 0) {
			$zeilen[] = '<strong>'.StringUtil::specialchars(null === $person ? sprintf($texte['mitgliedGeloescht'], (int) $zeile['member']) : PersonenRepository::name($person)).'</strong> · '.StringUtil::specialchars($art?->name ?? '');
		}

		if ((int) $zeile['hochgeladenAm'] > 0) {
			$zeilen[] = sprintf($texte['hochgeladenAm'], $this->datum((int) $zeile['hochgeladenAm'], true), StringUtil::specialchars((string) $zeile['dateiname']), $this->groesse((int) $zeile['dateigroesse']));
		}

		if ((int) $zeile['geprueftAm'] > 0) {
			$zeilen[] = sprintf($texte['entschieden'], $this->datum((int) $zeile['geprueftAm'], true), StringUtil::specialchars($this->personen->benutzerName((int) $zeile['geprueftVon'])));
		}

		if (Nachweis::GEPRUEFT === $zeile['status'] && Nachweis::GEPRUEFT === $zeile['verarbeitet']) {
			$zeilen[] = null === $zeile['gueltigBis'] ? $texte['unbegrenzt'] : sprintf($texte['gueltigBis'], $this->datum((int) $zeile['gueltigBis']));
		}

		return '<div class="widget clr ks-vorschau"><p>'.implode('<br>', $zeilen).'</p>'.$this->datei($zeile).'</div>';
	}

	/**
	 * Prüft die Eingaben der Prüfmaske, bevor Contao den Status speichert.
	 *
	 * Gelesen wird aus Input::post, weil die anderen Felder in diesem Moment
	 * noch nicht gespeichert sind. Für einen neuen Prüfvermerk kommt die
	 * Dokumentart aus dem Formular, sonst aus der Datenbank.
	 *
	 * In „Mehrere bearbeiten“ hängt Contao an jeden Feldnamen die ID des
	 * Datensatzes an (status_4, ablehnungsgrund_4). Die Endung wird deshalb
	 * am Eingabenamen dieses Felds abgelesen und an die anderen Felder
	 * gehängt; in der Prüfmaske und beim Überschreiben ist sie leer.
	 *
	 * @param mixed         $wert Der gewählte Status
	 * @param DataContainer $dc   Der Data Container
	 *
	 * @return mixed Der unveränderte Status
	 *
	 * @throws \RuntimeException Mit der Meldung am Feld, wenn etwas fehlt
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'fields.status.save')]
	public function statusPruefen(mixed $wert, DataContainer $dc): mixed
	{
		$endung = substr((string) $dc->inputName, \strlen((string) $dc->field));
		$artId = (int) (Input::post('dokumentart'.$endung) ?: ($this->nachweise->zeile((int) $dc->id)['dokumentart'] ?? 0));
		$art = $this->arten->eine($artId);
		$fehler = Pruefung::eingabefehler((string) $wert, (string) Input::post('ablehnungsgrund'.$endung), (string) Input::post('einschlaegig'.$endung), null !== $art && $art->einschlaegigAbfragen);

		if (null !== $fehler) {
			throw new \RuntimeException($GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$fehler] ?? $fehler);
		}

		return $wert;
	}

	/**
	 * Zieht nach dem Speichern die Folgen der Entscheidung.
	 *
	 * Konnte die Ablehnungsmail nicht verschickt werden, erscheint ein
	 * Fehlerhinweis über der Liste, damit der Prüfer die Person selbst
	 * informiert; die Entscheidung bleibt trotzdem gespeichert.
	 *
	 * @param DataContainer $dc Der Data Container
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'config.onsubmit')]
	public function nachSpeichern(DataContainer $dc): void
	{
		if (!$this->pruefung->nachSpeichern((int) $dc->id, $this->zugang->benutzerId(), time())) {
			Message::addError($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['mailGescheitert']);
		}
	}

	/**
	 * Verhindert das Löschen eines Nachweises, der noch eine Datei hat.
	 *
	 * Die Datei muss erst über den Papierkorb, damit auch hier ein
	 * versehentliches Löschen abgefangen wird. Abgebrochen wird mit einer
	 * Meldung und einer Umleitung zur Liste.
	 *
	 * @param DataContainer $dc Der Data Container
	 *
	 * @throws RedirectResponseException Wenn der Nachweis noch eine Datei hat
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'config.ondelete')]
	public function vorLoeschen(DataContainer $dc): void
	{
		$zeile = $this->nachweise->zeile((int) $dc->id);

		if (null === $zeile || '' === (string) $zeile['datei']) {
			return;
		}

		Message::addError($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['loeschenGesperrt']);

		throw new RedirectResponseException($this->router->generate('contao_backend', array('do' => 'kinderschutz_nachweise')));
	}

	/**
	 * Beschriftet eine Zeile der Liste.
	 *
	 * Die Signatur deckt 4.13 (drei Argumente) und 5.7 (vier) ab.
	 *
	 * @param array<string, mixed> $zeile Der Datensatz
	 * @param string               $label Contaos Vorschlag, unbenutzt
	 * @param DataContainer|null   $dc    Der Data Container
	 * @param array<mixed>|null    $args  Spaltenwerte (nur 5.7), unbenutzt
	 *
	 * @return string HTML der Beschriftung
	 */
	#[AsCallback(table: 'tl_kinderschutz', target: 'list.label.label')]
	public function beschriftung(array $zeile, string $label = '', ?DataContainer $dc = null, ?array $args = null): string
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be'];
		$person = $this->personen->mitglied((int) $zeile['member']);
		$name = null === $person ? sprintf($texte['mitgliedGeloescht'], (int) $zeile['member']) : PersonenRepository::name($person);
		$art = $this->arten->eine((int) $zeile['dokumentart'])?->name ?? '';
		$status = $GLOBALS['TL_LANG']['tl_kinderschutz']['stati'][$zeile['status']] ?? $zeile['status'];
		$zusatz = '';

		if ((int) $zeile['papierkorbSeit'] > 0) {
			$zusatz = sprintf($texte['imKorbSeit'], $this->datum((int) $zeile['papierkorbSeit']), $this->datum($this->papierkorb->loeschtAm((int) $zeile['papierkorbSeit'])));
		} elseif (Nachweis::GEPRUEFT === $zeile['status'] && null !== $zeile['gueltigBis']) {
			$zusatz = sprintf($texte['gueltigBis'], $this->datum((int) $zeile['gueltigBis']));
		}

		return sprintf(
			'<strong>%s</strong> · %s · <span class="ks-liste-%s">%s</span>%s',
			StringUtil::specialchars($name),
			StringUtil::specialchars($art),
			StringUtil::specialchars((string) $zeile['status']),
			StringUtil::specialchars((string) $status),
			'' === $zusatz ? '' : ' <span class="tl_gray">('.$zusatz.')</span>'
		);
	}

	/**
	 * Richtet die Liste als Papierkorb ein.
	 *
	 * Gezeigt werden nur Nachweise, deren Datei im Papierkorb liegt, ältester
	 * Einwurf zuerst und nach Einwurftag gruppiert. Anlegen, Bearbeiten,
	 * Kopieren und Löschen sind aus; an ihre Stelle treten die eigenen
	 * Aktionen „Wiederherstellen“, „Endgültig löschen“ und „Papierkorb leeren“.
	 *
	 * Seiteneffekt: ändert $GLOBALS['TL_DCA']['tl_kinderschutz'] für diese
	 * Anfrage.
	 */
	private function papierkorbAnsicht(): void
	{
		$dca = &$GLOBALS['TL_DCA']['tl_kinderschutz'];
		$sprache = &$GLOBALS['TL_LANG']['tl_kinderschutz'];
		$frage = static fn (string $schluessel): string => 'onclick="if(!confirm(\''.StringUtil::specialchars($GLOBALS['TL_LANG']['tl_kinderschutz'][$schluessel] ?? '').'\'))return false"';

		$dca['config']['closed'] = true;
		$dca['config']['notEditable'] = true;
		$dca['config']['notDeletable'] = true;
		$dca['config']['notCopyable'] = true;
		$dca['list']['sorting']['fields'] = array('papierkorbSeit');
		// Nach Tag, damit Contao über den Gruppen ein Datum ausgibt und nicht
		// den rohen Zeitstempel (papierkorbSeit hat kein rgxp)
		$dca['list']['sorting']['flag'] = DataContainer::SORT_DAY_ASC;
		$dca['list']['sorting']['panelLayout'] = 'limit';
		$dca['list']['sorting']['filter'] = array(array('papierkorbSeit>?', 0), array('datei!=?', ''));
		$dca['list']['global_operations'] = array(
			'leeren' => array(
				'label'      => &$sprache['leeren'],
				'href'       => 'key=leeren',
				'class'      => 'header_delete_all',
				'icon'       => 'delete.svg',
				'attributes' => $frage('leerenFrage'),
			),
		);
		$dca['list']['operations'] = array(
			'wiederherstellen' => array(
				'label'   => &$sprache['wiederherstellen'],
				'href'    => 'key=wiederherstellen',
				'icon'    => 'undo.svg',
				'primary' => true,
			),
			'loeschen' => array(
				'label'      => &$sprache['loeschen'],
				'href'       => 'key=loeschen',
				'icon'       => 'delete.svg',
				'attributes' => $frage('loeschenFrage'),
				'primary'    => true,
			),
		);
	}

	/**
	 * Baut den Dateiteil der Vorschau.
	 *
	 * @param array<string, mixed> $zeile Der Datensatz
	 *
	 * @return string HTML
	 */
	private function datei(array $zeile): string
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be'];

		if ((int) $zeile['dateiGeloeschtAm'] > 0) {
			return '<p class="tl_info">'.sprintf($texte['dateiGeloescht'], $this->datum((int) $zeile['dateiGeloeschtAm'])).'</p>';
		}

		if ('' === (string) $zeile['datei']) {
			return '<p class="tl_info">'.$texte['keineDatei'].'</p>';
		}

		$adresse = StringUtil::specialchars($this->router->generate('kinderschutz_datei', array('id' => (int) $zeile['id'])));
		$link = '<p><a href="'.$adresse.'" target="_blank" rel="noopener">'.$texte['dateiOeffnen'].'</a></p>';

		if ((int) $zeile['papierkorbSeit'] > 0) {
			return '<p class="tl_info">'.sprintf($texte['dateiImKorb'], $this->datum($this->papierkorb->loeschtAm((int) $zeile['papierkorbSeit']))).'</p>'.$link;
		}

		if ('application/pdf' === $zeile['dateityp']) {
			return '<iframe class="ks-pdf" src="'.$adresse.'" title="'.StringUtil::specialchars((string) $zeile['dateiname']).'"></iframe>'.$link;
		}

		return '<img class="ks-bild" src="'.$adresse.'" alt="'.StringUtil::specialchars((string) $zeile['dateiname']).'">'.$link;
	}

	/**
	 * Formatiert ein Datum.
	 *
	 * @param int  $zeitstempel Zeitpunkt
	 * @param bool $mitUhrzeit  Uhrzeit anhängen
	 *
	 * @return string Etwa „15.10.2026“ oder „15.10.2026 14:03“
	 */
	private function datum(int $zeitstempel, bool $mitUhrzeit = false): string
	{
		return Date::parse($mitUhrzeit ? 'd.m.Y H:i' : 'd.m.Y', $zeitstempel);
	}

	/**
	 * Formatiert eine Dateigröße.
	 *
	 * @param int $bytes Größe in Bytes
	 *
	 * @return string Etwa „245 KB“ oder „1,2 MB“
	 */
	private function groesse(int $bytes): string
	{
		return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : max(1, (int) round($bytes / 1024)).' KB';
	}
}
