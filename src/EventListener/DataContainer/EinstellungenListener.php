<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer;

use Contao\Config;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\SeitenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;

/**
 * Callbacks der Einstellungsmaske tl_kinderschutz_einstellungen.
 */
class EinstellungenListener
{
	/**
	 * @param Zugang          $zugang        Prüferfreigabe
	 * @param Einstellungen   $einstellungen Liefert die wirksame Gruppe, auch aus dem alten Schlüssel
	 * @param ContaoFramework $framework     Zugang zu Config, um den alten Schlüssel zu entfernen
	 * @param SeitenRepository $seiten       Seiten mit dem Frontend-Modul „Meine Unterlagen“
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly Einstellungen $einstellungen,
		private readonly ContaoFramework $framework,
		private readonly SeitenRepository $seiten,
	) {
	}

	/**
	 * Prüft den Zugang und setzt die Standardtexte als Platzhalter.
	 *
	 * DC_File prüft selbst keine Rechte; ohne diesen Callback stünde die
	 * Maske jedem offen, der die Adresse kennt. Die Standardtexte stammen
	 * aus der bereits geladenen Sprachdatei „default“; fehlt eine Mailart
	 * dort (etwa bei einer Backend-Sprache ohne Übersetzung), bleibt ihr
	 * Feld ohne Platzhalter. Die Mails selbst laden immer Deutsch.
	 *
	 * Seiteneffekt: ändert $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen'].
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Freigabe
	 */
	#[AsCallback(table: 'tl_kinderschutz_einstellungen', target: 'config.onload')]
	public function beimLaden(): void
	{
		$this->zugang->verlange();

		foreach (Einstellungen::MAILARTEN as $art) {
			$standard = $GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'][$art] ?? null;

			if (!\is_array($standard)) {
				continue;
			}

			$feld = 'kinderschutzMail'.ucfirst($art);
			$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields'][$feld.'Betreff']['eval']['placeholder'] = (string) $standard[0];
			$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields'][$feld.'Text']['eval']['placeholder'] = (string) $standard[1];
		}
	}

	/**
	 * Belegt die Gruppenauswahl im Übergang mit der wirksamen Gruppe vor.
	 *
	 * Nach dem Update steht in der localconfig nur der alte Schlüssel
	 * „kinderschutzGruppen“; die Maske zeigte die Auswahl sonst leer, obwohl
	 * die erste alte Gruppe wirkt. Wer dann speichert, übernimmt sie damit
	 * ausdrücklich (siehe gruppeSpeichern()).
	 *
	 * @param mixed $wert Gespeicherter Wert von „kinderschutzGruppe“ (ID als Zeichenkette, leer oder null)
	 *
	 * @return mixed Der gespeicherte Wert unverändert, wenn er eine Gruppe benennt; sonst die wirksame
	 *               Gruppe (ID), oder eine leere Zeichenkette, wenn es keine gibt
	 */
	#[AsCallback(table: 'tl_kinderschutz_einstellungen', target: 'fields.kinderschutzGruppe.load')]
	public function gruppeLaden(mixed $wert): mixed
	{
		if ((int) $wert > 0) {
			return $wert;
		}

		$gruppe = $this->einstellungen->gruppe();

		return $gruppe > 0 ? $gruppe : '';
	}

	/**
	 * Entfernt beim Speichern den alten Schlüssel der Mehrfachauswahl und
	 * schreibt dabei den neuen Wert ausdrücklich.
	 *
	 * Einstellungen::gruppe() greift auf „kinderschutzGruppen“ zurück, wenn
	 * „kinderschutzGruppe“ leer ist. Ohne das Entfernen wirkte die alte
	 * Gruppe nach dem Leeren der neuen Auswahl wieder. DC_File ruft den
	 * save_callback bei jedem Absenden, also genügt der erste Speichervorgang.
	 *
	 * Der Wert wird hier selbst geschrieben, weil DC_File ihn nicht schriebe:
	 * Es vergleicht den Eingabewert lose mit dem aktuellen („7 != "7"“ ist
	 * falsch) und speichert nur bei einer Abweichung. Wurde die im Übergang
	 * vorbelegte Gruppe unverändert abgesendet, bliebe „kinderschutzGruppe“
	 * ungeschrieben, und mit dem entfernten alten Schlüssel wäre die Gruppe
	 * weg. Config::remove() ändert zudem nur die zu schreibende Datei: Im
	 * selben Request kann Config::get() den alten Wert weiter liefern, daher
	 * darf nichts davon abhängen, dass er verschwunden ist.
	 *
	 * Seiteneffekt: Config::persist() und Config::remove() merken die
	 * Änderung vor; Contao schreibt die localconfig.php am Ende der Anfrage,
	 * zusammen mit den übrigen Werten, die DC_File speichert. Die Methoden
	 * gibt es in Contao 4.13 und 5.7.
	 *
	 * @param mixed $wert Der neue Wert von „kinderschutzGruppe“ (ID, leer wenn geleert)
	 *
	 * @return mixed Der Wert unverändert
	 */
	#[AsCallback(table: 'tl_kinderschutz_einstellungen', target: 'fields.kinderschutzGruppe.save')]
	public function gruppeSpeichern(mixed $wert): mixed
	{
		$this->framework->initialize();
		$config = $this->framework->getAdapter(Config::class);

		if (null === $config->get('kinderschutzGruppen')) {
			return $wert;
		}

		if ((int) $wert > 0) {
			$config->persist('kinderschutzGruppe', (int) $wert);
		}

		$config->remove('kinderschutzGruppen');

		return $wert;
	}

	/**
	 * Liefert die Auswahl für „Seite mit Meine Unterlagen“.
	 *
	 * Ein Seitenbaum (pageTree) bräuchte Rechte am Seitenmodul, die ein Prüfer
	 * meist nicht hat: Der Knopf „Auswahl ändern“ war für ihn gesperrt, der
	 * Picker antwortete mit 400. Angeboten werden deshalb die Seiten, auf denen
	 * das Frontend-Modul als Inhaltselement eingebunden ist (siehe
	 * SeitenRepository), beschriftet als „Titel (Alias, ID)“. Die gespeicherte
	 * Seite steht immer dabei, auch wenn das Modul dort nicht (mehr) gefunden
	 * wird, etwa weil es über das Seitenlayout eingebunden ist; sonst ginge sie
	 * beim nächsten Speichern verloren. Eine gelöschte Seite erscheint als „#ID“.
	 *
	 * Die Beschriftungen werden maskiert (ohne doppelte Kodierung, von Contao
	 * kodiert gespeicherte Titel bleiben also richtig), weil das
	 * SelectMenu-Widget Optionstexte unverändert ausgibt; ein roh importierter
	 * Seitentitel könnte sonst HTML in die Maske bringen.
	 *
	 * @return array<int, string> Seiten-ID => Beschriftung (HTML-maskiert); leer,
	 *                            wenn es weder eine passende noch eine
	 *                            gespeicherte Seite gibt
	 */
	#[AsCallback(table: 'tl_kinderschutz_einstellungen', target: 'fields.kinderschutzSeite.options')]
	public function seitenOptionen(): array
	{
		$optionen = array();

		foreach ($this->seiten->mitUnterlagenModul() as $seite) {
			$optionen[$seite['id']] = self::seitenName($seite);
		}

		$gespeichert = $this->einstellungen->seite();

		if ($gespeichert > 0 && !isset($optionen[$gespeichert])) {
			$seite = $this->seiten->seite($gespeichert);
			$zusatz = (string) ($GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['seiteOhneModul'] ?? 'ohne Frontend-Modul');
			$optionen[$gespeichert] = (null === $seite ? '#'.$gespeichert : self::seitenName($seite)).' – '.$zusatz;
		}

		return array_map(static fn (string $text): string => StringUtil::specialchars($text), $optionen);
	}

	/**
	 * Beschriftet eine Seite als „Titel (Alias, ID)“.
	 *
	 * @param array{id: int, title: string, alias: string} $seite Die Seite
	 *
	 * @return string Die Beschriftung; ohne Alias „Titel (ID)“
	 */
	private static function seitenName(array $seite): string
	{
		return $seite['title'].' ('.('' === $seite['alias'] ? '' : $seite['alias'].', ').$seite['id'].')';
	}
}
