<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;

/**
 * Anträge auf Freischaltung: annehmen, prüfen und entscheiden.
 *
 * Freischalten heißt: Das Mitglied kommt in die Gruppe der freigeschalteten
 * Mitglieder (Einstellungen::gruppe()), die angekreuzten Veranstaltungen
 * werden wie eine Selbstanmeldung übernommen, und das Mitglied bekommt eine
 * Mail mit dem Link auf „Meine Unterlagen“. Entziehen gibt es hier nicht;
 * das erledigt ein Administrator über die Gruppe.
 *
 * Wie bei der Prüfung von Unterlagen speichert Contao die Entscheidung
 * selbst; nachSpeichern() zieht danach die Folgen genau einmal (Merker
 * „entschiedenAm“) und meldet eine gescheiterte Mail mit false.
 */
class Freischaltung
{
	/** Höchstlänge der Begründung „Wofür?“ in Zeichen */
	public const WOFUER_MAX = 500;

	/**
	 * Übernimmt die Repositories und Dienste, die Antrag, Freischaltung und
	 * Ablehnung brauchen; der Konstruktor selbst greift weder auf die
	 * Datenbank zu noch verschickt er etwas.
	 *
	 * @param AntragRepository        $antraege         Die Anträge
	 * @param PersonenRepository      $personen         Mitglieder, Gruppen, Prüferadressen
	 * @param VeranstaltungRepository $veranstaltungen  Wählbare Veranstaltungen und Anmeldung
	 * @param Einstellungen           $einstellungen    Die Freischaltgruppe
	 * @param Benachrichtigung        $benachrichtigung Die Mails
	 * @param Berechtigung            $berechtigung     Kennt den Hauptadmin
	 * @param LoggerInterface         $protokoll        Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly AntragRepository $antraege,
		private readonly PersonenRepository $personen,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly Einstellungen $einstellungen,
		private readonly Benachrichtigung $benachrichtigung,
		private readonly Berechtigung $berechtigung,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Nimmt einen Antrag aus dem Frontend an.
	 *
	 * Die Begründung wird mit bereinigeBegruendung() von echten HTML-Tags
	 * befreit (ein einzelnes „<“ im Text bleibt stehen), bekommt einheitliche
	 * Zeilenenden, wird an den Rändern getrimmt und auf die Höchstlänge
	 * WOFUER_MAX in Zeichen geprüft; von den angekreuzten
	 * Veranstaltungen bleiben nur die, die gerade wählbar sind – eine
	 * nachgebaute Anfrage kann also keine interne Veranstaltung
	 * unterschieben. Die Mail an die Prüfer darf scheitern; der Antrag gilt
	 * trotzdem.
	 *
	 * Die Regel „höchstens ein offener Antrag je Mitglied“ wird hier vor dem
	 * Anlegen geprüft, nicht durch die Datenbank erzwungen: Prüfen und
	 * Anlegen sind nicht atomar. Schickt dasselbe Mitglied zwei Anfragen
	 * gleichzeitig ab, können ausnahmsweise zwei offene Anträge entstehen.
	 * Das wird bei 20 bis 40 Personen hingenommen; die Prüfer sehen beide in
	 * der Liste, und eine Freischaltung schließt den anderen mit
	 * (AntragRepository::schliesseOffene()).
	 *
	 * Seiteneffekte: legt einen Antrag an, schreibt ins Systemprotokoll (nur
	 * IDs) und verschickt die Mail an Hauptadmin und Prüfer.
	 *
	 * @param int       $mitglied        ID des angemeldeten Mitglieds (aus dem Token)
	 * @param string    $wofuer          Begründung aus dem Formular
	 * @param list<int> $veranstaltungen Angekreuzte Veranstaltungs-IDs
	 * @param int       $jetzt           Aktueller Zeitpunkt
	 *
	 * @return string|null Fehlerschlüssel „gruppe“ (nicht eingerichtet),
	 *                     „wofuer“ (leer oder zu lang) oder „offen“ (es gibt
	 *                     schon einen offenen Antrag); null bei Erfolg
	 */
	public function beantragen(int $mitglied, string $wofuer, array $veranstaltungen, int $jetzt): ?string
	{
		if ($this->einstellungen->gruppe() <= 0) {
			return 'gruppe';
		}

		$wofuer = $this->bereinigeBegruendung($wofuer);

		if ('' === $wofuer || mb_strlen($wofuer) > self::WOFUER_MAX) {
			return 'wofuer';
		}

		if (null !== $this->antraege->offenerFuer($mitglied)) {
			return 'offen';
		}

		$waehlbar = array_map(static fn (array $zeile): int => (int) $zeile['id'], $this->veranstaltungen->waehlbare(Kalender::tag($jetzt)));
		$gewaehlt = array_values(array_intersect(array_unique(array_map('intval', $veranstaltungen)), $waehlbar));
		$antrag = $this->antraege->anlegen($mitglied, $wofuer, $gewaehlt, $jetzt);
		$this->protokoll->info(sprintf('Kinderschutz: Antrag %d von Mitglied %d eingegangen', $antrag, $mitglied));

		$person = $this->personen->mitglied($mitglied);

		if (null !== $person) {
			$this->benachrichtigung->neuerAntrag($person, $wofuer, $this->namen($gewaehlt), $this->personen->prueferAdressen($this->berechtigung->hauptadmin()));
		}

		return null;
	}

	/**
	 * Zieht nach dem Speichern im Backend die Folgen der Entscheidung.
	 *
	 * Ein Datensatz ohne Stand stammt aus „Mitglied freischalten“ und wird
	 * hier zum direkt freigeschalteten Antrag. Entschiedene Anträge
	 * (entschiedenAm > 0) bleiben unberührt, offene ebenso.
	 *
	 * Seiteneffekte: schreibt tl_kinderschutz_antrag, tl_member.groups und
	 * tl_kinderschutz_teilnahme, schreibt ins Systemprotokoll und verschickt
	 * die Mail an das Mitglied.
	 *
	 * @param int $antrag  ID des gespeicherten Antrags
	 * @param int $pruefer ID des Prüfers in tl_user
	 * @param int $jetzt   Aktueller Zeitpunkt
	 *
	 * @return bool false, wenn die Mail an das Mitglied nicht verschickt
	 *              werden konnte; die Entscheidung bleibt dann trotzdem
	 */
	public function nachSpeichern(int $antrag, int $pruefer, int $jetzt): bool
	{
		$zeile = $this->antraege->zeile($antrag);

		if (null === $zeile || (int) $zeile['entschiedenAm'] > 0) {
			return true;
		}

		$status = (string) $zeile['status'];

		if ('' === $status && (int) $zeile['member'] > 0) {
			$this->antraege->aendern($antrag, array('status' => AntragRepository::FREIGESCHALTET, 'direkt' => '1', 'beantragtAm' => $jetzt));
			$status = AntragRepository::FREIGESCHALTET;
		}

		return match ($status) {
			AntragRepository::FREIGESCHALTET => $this->freischalten($zeile, $pruefer, $jetzt),
			AntragRepository::ABGELEHNT      => $this->ablehnen($zeile, $pruefer, $jetzt),
			default                          => true,
		};
	}

	/**
	 * Prüft eine Entscheidung in der Maske, bevor Contao sie speichert.
	 *
	 * Beim Freischalten muss das Mitglied wie bei pruefeDirekt() aktiv sein
	 * (vorhanden, nicht gesperrt, im Aktivierungszeitraum): Ein gesperrtes
	 * Mitglied käme sonst in die Gruppe, könnte sich aber nicht anmelden und
	 * bekäme trotzdem die Mail mit dem Link. Ablehnen geht auch bei einem
	 * gesperrten Mitglied.
	 *
	 * Beim Ablehnen zählt die Begründung erst nach bereinigeBegruendung():
	 * Besteht sie nur aus Tags wie „<b></b>“ (roh oder als „&#60;b&#62;“
	 * kodiert), gilt sie als fehlend. Im Prüfstand 5.7 ging „<b></b>“ sonst
	 * durch und erschien wörtlich als Begründung in Maske, Frontend und Mail.
	 *
	 * @param array<string, mixed>|null $gespeichert Der Antrag vor dem Speichern
	 * @param string                    $neu         Gewählte Entscheidung
	 * @param string                    $grund       Eingegebene Begründung
	 * @param int|null                  $jetzt       Aktueller Zeitpunkt für die Prüfung des
	 *                                               Aktivierungszeitraums; null nimmt die
	 *                                               aktuelle Zeit (so rufen die Masken auf,
	 *                                               Tests geben einen festen Wert mit)
	 *
	 * @return string|null Schlüssel unter $GLOBALS['TL_LANG']['MSC']['kinderschutz_be']:
	 *                     „antragEntschieden“ (Entscheidung wird nachträglich
	 *                     geändert), „antragGrundFehlt“, „keineGruppe“,
	 *                     „mitgliedFehlt“ (gelöscht, gesperrt oder abgelaufen);
	 *                     null, wenn alles passt
	 */
	public function pruefeEntscheidung(?array $gespeichert, string $neu, string $grund, ?int $jetzt = null): ?string
	{
		if (null === $gespeichert) {
			return null;
		}

		if ((int) $gespeichert['entschiedenAm'] > 0) {
			return $neu === (string) $gespeichert['status'] ? null : 'antragEntschieden';
		}

		// Eine Begründung, die nach dem Entfernen echter Tags leer ist, fehlt.
		// Entitäten erst entkodieren: Contao legt „<“ als „&#60;“ ab, und je
		// nach Weg kommt der Wert so kodiert an; „&#60;b>&#60;/b>“ wäre sonst
		// für die Tag-Bereinigung bloßer Text.
		if (AntragRepository::ABGELEHNT === $neu && '' === $this->bereinigeBegruendung(html_entity_decode($grund, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
			return 'antragGrundFehlt';
		}

		if (AntragRepository::FREIGESCHALTET === $neu) {
			if ($this->einstellungen->gruppe() <= 0) {
				return 'keineGruppe';
			}

			if (!$this->personen->istAktivesMitglied((int) $gespeichert['member'], $jetzt ?? time())) {
				return 'mitgliedFehlt';
			}
		}

		return null;
	}

	/**
	 * Prüft die Auswahl in „Mitglied freischalten“.
	 *
	 * @param int $mitglied Gewähltes Mitglied
	 * @param int $jetzt    Aktueller Zeitpunkt
	 *
	 * @return string|null „keineGruppe“, „mitgliedFehlt“ (gelöscht oder
	 *                     inaktiv), „schonFreigeschaltet“; null, wenn alles passt
	 */
	public function pruefeDirekt(int $mitglied, int $jetzt): ?string
	{
		if ($this->einstellungen->gruppe() <= 0) {
			return 'keineGruppe';
		}

		if (!$this->personen->istAktivesMitglied($mitglied, $jetzt)) {
			return 'mitgliedFehlt';
		}

		if ($this->personen->istInGruppen($mitglied, $this->einstellungen->gruppen(), $jetzt)) {
			return 'schonFreigeschaltet';
		}

		return null;
	}

	/**
	 * Schaltet frei: Gruppe, Veranstaltungen, Merker, Mail.
	 *
	 * Angemeldet wird zu den Veranstaltungen des Antrags und zu denen aller
	 * übrigen offenen Anträge des Mitglieds, die dabei mit geschlossen werden
	 * (AntragRepository::weitereOffene() und schliesseOffene()). Eine
	 * Veranstaltung, die inzwischen nicht mehr wählbar oder vorbei ist, fällt
	 * still weg: VeranstaltungRepository::anmelden() lehnt sie ab, die
	 * Freischaltung selbst gilt trotzdem.
	 *
	 * Fehlen Mitglied oder Gruppe trotz Eingabeprüfung (etwa gleichzeitig
	 * gelöscht), geht der Antrag zurück auf „offen“, statt halb zu gelten.
	 * Dieser Rückfallweg ist praktisch nicht erreichbar: pruefeEntscheidung()
	 * und pruefeDirekt() haben Mitglied und Gruppe unmittelbar vor dem
	 * Speichern geprüft, und er greift nur, wenn eines von beiden in den
	 * Sekundenbruchteilen dazwischen verschwindet. Er ist ein Sicherheitsnetz,
	 * damit ein Antrag nie als freigeschaltet gilt, ohne dass das Mitglied in
	 * der Gruppe ist.
	 *
	 * @param array<string, mixed> $zeile   Der Antrag
	 * @param int                  $pruefer ID des Prüfers
	 * @param int                  $jetzt   Aktueller Zeitpunkt
	 *
	 * @return bool false, wenn die Mail scheiterte
	 */
	private function freischalten(array $zeile, int $pruefer, int $jetzt): bool
	{
		$antrag = (int) $zeile['id'];
		$mitglied = (int) $zeile['member'];
		$person = $this->personen->mitglied($mitglied);
		$gruppe = $this->einstellungen->gruppe();

		if (null === $person || $gruppe <= 0) {
			$this->antraege->aendern($antrag, array('status' => AntragRepository::OFFEN));
			$this->protokoll->error(sprintf('Kinderschutz: Antrag %d nicht freigeschaltet, es fehlt %s', $antrag, null === $person ? 'das Mitglied' : 'die Gruppe'));

			return true;
		}

		$this->personen->inGruppeAufnehmen($mitglied, $gruppe);
		$heute = Kalender::tag($jetzt);

		// Auch die Veranstaltungen der Anträge, die gleich mit geschlossen werden,
		// müssen vorher übernommen werden: Danach sind sie nicht mehr „offen“,
		// und der Wunsch des Mitglieds verfiele stillschweigend.
		$wuensche = array(AntragRepository::veranstaltungenAus($zeile['veranstaltungen']));

		foreach ($this->antraege->weitereOffene($mitglied, $antrag) as $weiterer) {
			$wuensche[] = AntragRepository::veranstaltungenAus($weiterer['veranstaltungen']);
		}

		foreach (array_unique(array_merge(...$wuensche)) as $veranstaltung) {
			$this->veranstaltungen->anmelden($veranstaltung, $mitglied, $heute, $jetzt);
		}

		$this->antraege->aendern($antrag, array('entschiedenAm' => $jetzt, 'entschiedenVon' => $pruefer, 'tstamp' => $jetzt));
		$this->antraege->schliesseOffene($mitglied, $antrag, $pruefer, $jetzt);
		$this->protokoll->info(sprintf('Kinderschutz: Antrag %d freigeschaltet (Mitglied %d)', $antrag, $mitglied));

		return $this->benachrichtigung->freigeschaltet($person);
	}

	/**
	 * Lehnt ab: Merker und Mail mit der Begründung.
	 *
	 * @param array<string, mixed> $zeile   Der Antrag
	 * @param int                  $pruefer ID des Prüfers
	 * @param int                  $jetzt   Aktueller Zeitpunkt
	 *
	 * @return bool false, wenn die Mail scheiterte; true auch, wenn das
	 *              Mitglied inzwischen gelöscht ist
	 */
	private function ablehnen(array $zeile, int $pruefer, int $jetzt): bool
	{
		$antrag = (int) $zeile['id'];
		$this->antraege->aendern($antrag, array('entschiedenAm' => $jetzt, 'entschiedenVon' => $pruefer, 'tstamp' => $jetzt));
		$this->protokoll->info(sprintf('Kinderschutz: Antrag %d abgelehnt', $antrag));
		$person = $this->personen->mitglied((int) $zeile['member']);

		return null === $person ? true : $this->benachrichtigung->antragAbgelehnt($person, (string) $zeile['grund']);
	}

	/**
	 * Bereinigt die Begründung aus dem Formular.
	 *
	 * Entfernt werden nur echte Tags („<b>“, „</a>“, „<a href=…>“): ein „<“,
	 * dem ein Buchstabe oder „/“ und ein Buchstabe folgen, bis zum nächsten
	 * „>“. strip_tags() ist dafür zu grob; es hielte „<18-Jährigen“ für den
	 * Anfang eines Tags und schnitte den Rest der Eingabe ab. Ein einzelnes
	 * „<“ bleibt deshalb Text. Das ist unbedenklich, weil jede Ausgabe
	 * maskiert wird und die Mails reiner Text sind; das Entfernen der Tags
	 * ist nur eine zusätzliche Vorsicht. Es läuft mehrfach, bis nichts mehr
	 * wegfällt, damit aus „<<b>i>“ nicht durch das Entfernen von „<b>“ ein
	 * neues „<i>“ entsteht.
	 *
	 * Die Zeilenenden werden vorher auf „\n“ vereinheitlicht. Ein Browser
	 * sendet Zeilenumbrüche im Textfeld als „\r\n“, zählt sie fürs
	 * Attribut „maxlength“ aber als ein Zeichen; ohne die Vereinheitlichung
	 * zählten Formular und Serverprüfung verschieden.
	 *
	 * @param string $wofuer Eingabe aus dem Formular
	 *
	 * @return string Der bereinigte Text, an den Rändern ohne Leerraum;
	 *                leer, wenn nichts übrig bleibt
	 */
	private function bereinigeBegruendung(string $wofuer): string
	{
		$wofuer = str_replace(array("\r\n", "\r"), "\n", $wofuer);

		do {
			$vorher = $wofuer;
			$wofuer = (string) preg_replace('~</?[a-zA-Z][^<>]*>~', '', $wofuer);
		} while ($wofuer !== $vorher);

		return trim($wofuer);
	}

	/**
	 * Liefert die Namen von Veranstaltungen für die Mail.
	 *
	 * @param list<int> $ids IDs der Veranstaltungen
	 *
	 * @return list<string> Namen; gelöschte als „#ID“
	 */
	private function namen(array $ids): array
	{
		$namen = array();

		foreach ($ids as $id) {
			$zeile = $this->veranstaltungen->eine($id);
			$namen[] = null === $zeile ? '#'.$id : (string) $zeile['name'];
		}

		return $namen;
	}
}
