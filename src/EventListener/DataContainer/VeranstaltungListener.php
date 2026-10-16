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
use Contao\Date;
use Contao\StringUtil;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;

/**
 * Callbacks von tl_kinderschutz_veranstaltung: das virtuelle Feld
 * „teilnehmer“, das Aufräumen beim Löschen und die Listenbeschriftung.
 */
class VeranstaltungListener
{
	/**
	 * @param VeranstaltungRepository $veranstaltungen Teilnahmen
	 * @param PersonenRepository      $personen        Namen der Mitglieder
	 * @param Einstellungen           $einstellungen   Kinderschutzpflichtige Gruppen
	 */
	public function __construct(
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly PersonenRepository $personen,
		private readonly Einstellungen $einstellungen,
	) {
	}

	/**
	 * Liefert die wählbaren Teilnehmer.
	 *
	 * Angeboten werden die Mitglieder der kinderschutzpflichtigen Gruppen und
	 * zusätzlich alle bereits eingetragenen – sonst würde eine Person, die
	 * inzwischen aus der Gruppe gefallen ist, beim nächsten Speichern
	 * stillschweigend ausgetragen.
	 *
	 * @param DataContainer|null $dc Der Data Container
	 *
	 * @return array<int, string> Mitglieds-ID → „Nachname, Vorname“
	 */
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'fields.teilnehmer.options')]
	public function optionen(?DataContainer $dc = null): array
	{
		$optionen = array();

		foreach ($this->personen->mitgliederInGruppen($this->einstellungen->gruppen(), time()) as $person) {
			$optionen[$person['id']] = PersonenRepository::name($person);
		}

		foreach (null === $dc ? array() : $this->veranstaltungen->teilnehmer((int) $dc->id) as $id) {
			if (!isset($optionen[$id])) {
				$person = $this->personen->mitglied($id);
				$optionen[$id] = null === $person ? '#'.$id : PersonenRepository::name($person);
			}
		}

		return $optionen;
	}

	/**
	 * Liest die Teilnehmer aus tl_kinderschutz_teilnahme.
	 *
	 * Geliefert wird ein Array, keine serialisierte Zeichenkette: Beim
	 * Bearbeiten nimmt das Checkbox-Widget beides, aber „Mehrere bearbeiten →
	 * Überschreiben“ mischt das Ergebnis in den Modi „hinzufügen“ und
	 * „entfernen“ per array_merge()/array_diff() mit der neuen Auswahl
	 * (DC_Table::save(), 4.13 wie 5.7) und bräche bei einer Zeichenkette mit
	 * einem TypeError ab.
	 *
	 * @param mixed         $wert Leer, das Feld hat keine Spalte; beim
	 *                            Überschreiben mehrerer Datensätze ein Array
	 * @param DataContainer $dc   Der Data Container
	 *
	 * @return list<string> Die Mitglieds-IDs als Zeichenketten, wie Contao
	 *                      sie mit den Optionswerten vergleicht
	 */
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'fields.teilnehmer.load')]
	public function laden(mixed $wert, DataContainer $dc): array
	{
		return array_map('strval', $this->veranstaltungen->teilnehmer((int) $dc->id));
	}

	/**
	 * Schreibt die Auswahl nach tl_kinderschutz_teilnahme.
	 *
	 * Gibt null zurück; zusammen mit „doNotSaveEmpty“ versucht Contao dann
	 * nicht, in eine Spalte „teilnehmer“ zu schreiben, die es nicht gibt.
	 *
	 * Ohne Datensatz-ID wird nichts geschrieben. Contao 4.13 baut in „Mehrere
	 * bearbeiten → Überschreiben“ nach dem Speichern aller Datensätze das
	 * Formular in derselben Anfrage noch einmal mit ID 0 auf, und row()
	 * speichert dabei jedes Feld erneut (DC_Table::overrideAll(), die
	 * Weiterleitung folgt erst danach; 5.7 leitet vorher weiter). Ohne diese
	 * Sperre entstanden Teilnahmen mit Veranstaltung 0.
	 *
	 * Seiteneffekt: schreibt und löscht Zeilen in tl_kinderschutz_teilnahme.
	 *
	 * @param mixed         $wert Serialisierte Auswahl
	 * @param DataContainer $dc   Der Data Container; ID 0 beim Formularaufbau
	 *                            von „Überschreiben“ in 4.13
	 *
	 * @return null Immer
	 */
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'fields.teilnehmer.save')]
	public function speichern(mixed $wert, DataContainer $dc): mixed
	{
		if ((int) $dc->id <= 0) {
			return null;
		}

		$this->veranstaltungen->setzeTeilnehmer((int) $dc->id, array_map('intval', StringUtil::deserialize($wert, true)), time());

		return null;
	}

	/**
	 * Löscht die Teilnahmen einer gelöschten Veranstaltung.
	 *
	 * @param DataContainer $dc Der Data Container
	 */
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'config.ondelete')]
	public function loeschen(DataContainer $dc): void
	{
		$this->veranstaltungen->loescheTeilnahmen((int) $dc->id);
	}

	/**
	 * Beschriftet eine Zeile: Name, Zeitraum, Ort, Zahl der Teilnehmer.
	 *
	 * Ohne Beginn entfällt der Zeitraum, ohne Zeitraum und Ort die Klammer.
	 * Das betrifft unfertige Entwürfe: „Neu“ legt den Datensatz sofort an,
	 * Beginn und Ende sind NULL, und verlässt der Prüfer die Maske ohne
	 * Speichern, bleibt er stehen (4.13 räumt ihn nur über „Zurück“ ab). Die
	 * Liste zeigte im Prüfstand 4.13 dafür „(01.01.1970)“.
	 *
	 * @param array<string, mixed> $zeile Der Datensatz
	 * @param string               $label Contaos Vorschlag, unbenutzt
	 * @param DataContainer|null   $dc    Der Data Container
	 * @param array<mixed>|null    $args  Spaltenwerte (nur 5.7), unbenutzt
	 *
	 * @return string HTML der Beschriftung
	 */
	#[AsCallback(table: 'tl_kinderschutz_veranstaltung', target: 'list.label.label')]
	public function beschriftung(array $zeile, string $label = '', ?DataContainer $dc = null, ?array $args = null): string
	{
		$beginn = (int) ($zeile['startDate'] ?? 0);
		$ende = (int) ($zeile['endDate'] ?? 0);
		$angaben = array();

		if ($beginn > 0) {
			$angaben[] = Date::parse('d.m.Y', $beginn).($ende > 0 && $ende !== $beginn ? ' – '.Date::parse('d.m.Y', $ende) : '');
		}

		if ('' !== (string) ($zeile['ort'] ?? '')) {
			$angaben[] = StringUtil::specialchars((string) $zeile['ort']);
		}

		$klammer = array() === $angaben ? '' : '('.implode(', ', $angaben).') ';
		$anzahl = count($this->veranstaltungen->teilnehmer((int) $zeile['id']));

		return sprintf('<strong>%s</strong> <span class="tl_gray">%s· %d Teilnehmer</span>', StringUtil::specialchars((string) $zeile['name']), $klammer, $anzahl);
	}
}
