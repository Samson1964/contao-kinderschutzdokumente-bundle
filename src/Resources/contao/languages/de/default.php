<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Mailtexte. Platzhalter in geschweiften Klammern werden ersetzt. Eigene
 * Fassungen gehören in contao/languages/de/default.php des Projekts.
 */
$GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'] = array
(
	'neu' => array
	(
		'Neue Kinderschutz-Unterlage: {dokumentart} von {mitglied}',
		"Guten Tag,\n\n{mitglied} hat eine neue Unterlage hochgeladen: {dokumentart}.\n\nZur Prüfung im Backend:\n{link}\n\nDiese Nachricht wurde automatisch verschickt.\n",
	),
	'abgelehnt' => array
	(
		'Ihre Unterlage „{dokumentart}“ wurde nicht anerkannt',
		"Guten Tag {name},\n\nIhre hochgeladene Unterlage „{dokumentart}“ konnte leider nicht anerkannt werden.\n\nBegründung:\n{grund}\n\nBitte laden Sie die Unterlage erneut hoch:\n{link}\n\nDiese Nachricht wurde automatisch verschickt.\n",
	),
	'vorAblauf' => array
	(
		'Ihre Unterlage „{dokumentart}“ läuft bald ab',
		"Guten Tag {name},\n\nIhre Unterlage „{dokumentart}“ gilt noch bis zum {datum}. Bitte reichen Sie rechtzeitig eine neue ein:\n{link}\n\nDiese Nachricht wurde automatisch verschickt.\n",
	),
	'ablauf' => array
	(
		'Ihre Unterlage „{dokumentart}“ ist abgelaufen',
		"Guten Tag {name},\n\nIhre Unterlage „{dokumentart}“ galt bis zum {datum}. Bitte reichen Sie eine neue ein:\n{link}\n\nDiese Nachricht wurde automatisch verschickt.\n",
	),
	'veranstaltung' => array
	(
		'Fehlende Unterlagen für „{veranstaltung}“',
		"Guten Tag {name},\n\nfür die Veranstaltung „{veranstaltung}“ ({zeitraum}) fehlen von Ihnen noch diese Unterlagen:\n\n{liste}\n\nBitte reichen Sie sie rechtzeitig ein:\n{link}\n\nDiese Nachricht wurde automatisch verschickt.\n",
	),
);

/*
 * Stand je Dokumentart im Backend (Matrix, Prüfmaske).
 */
$GLOBALS['TL_LANG']['MSC']['kinderschutz_status'] = array
(
	'fehlt'       => 'fehlt',
	'eingereicht' => 'eingereicht',
	'abgelehnt'   => 'abgelehnt',
	'gueltig'     => 'gültig',
	'laeuftAb'    => 'läuft bald ab',
	'abgelaufen'  => 'abgelaufen',
	'beanstandet' => 'beanstandet',
	'wartet'      => 'neue Einreichung wartet',
);

/*
 * Stand je Dokumentart im Frontend. „beanstandet“ erscheint dort bewusst
 * nicht wörtlich: Das Prüfergebnis gehört ins Gespräch, nicht auf die Seite.
 */
$GLOBALS['TL_LANG']['MSC']['kinderschutz_status_frontend'] = array
(
	'fehlt'       => 'noch nicht eingereicht',
	'eingereicht' => 'eingereicht, wird geprüft',
	'abgelehnt'   => 'nicht anerkannt',
	'gueltig'     => 'gültig',
	'laeuftAb'    => 'läuft bald ab',
	'abgelaufen'  => 'abgelaufen',
	'beanstandet' => 'bitte Rücksprache halten',
);

/*
 * Texte des Frontend-Moduls.
 */
$GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'] = array
(
	'nichtAngemeldet' => 'Bitte melden Sie sich an, um Ihre Unterlagen einzureichen.',
	'keinZugang'      => 'Für Ihr Konto sind keine Kinderschutz-Unterlagen vorgesehen.',
	'unterlagen'      => 'Meine Unterlagen',
	'pflicht'         => 'Pflicht',
	'gueltigBis'      => 'gültig bis %s',
	'unbegrenzt'      => 'unbegrenzt gültig',
	'grund'           => 'Begründung: %s',
	'wartet'          => 'Eine neue Einreichung wird geprüft.',
	'datei'           => 'Datei (PDF, JPG oder PNG, höchstens %s)',
	'ausgestellt'     => 'Ausstellungsdatum',
	'hochladen'       => 'Hochladen',
	'erneuern'        => 'Neue Fassung hochladen',
	'hochgeladen'     => 'Vielen Dank, die Unterlage „%s“ ist eingegangen und wird geprüft.',
	'veranstaltungen' => 'Meine Veranstaltungen',
	'vollstaendig'    => 'Unterlagen vollständig',
	'esFehlt'         => 'Es fehlt: %s',
	'gesperrt'        => 'Von der Kinderschutzbeauftragten zugeordnet',
	'abmelden'        => 'Abmelden',
	'anmelden'        => 'Anmelden',
	'waehlbar'        => 'Weitere Veranstaltungen',
	'keineWaehlbar'   => 'Derzeit stehen keine weiteren Veranstaltungen zur Auswahl.',
	'angemeldet'      => 'Sie sind für „%s“ angemeldet.',
	'abgemeldet'      => 'Sie sind von „%s“ abgemeldet.',
	'fehler'          => array
	(
		'zuGross' => 'Die Datei ist zu groß; erlaubt sind höchstens %s.',
		'upload'  => 'Die Datei ist nicht vollständig angekommen. Bitte versuchen Sie es erneut.',
		'leer'    => 'Die Datei ist leer.',
		'typ'     => 'Bitte laden Sie nur PDF-, JPG- oder PNG-Dateien hoch.',
		'datum'   => 'Bitte geben Sie das Ausstellungsdatum an; es darf nicht in der Zukunft liegen.',
		'art'     => 'Diese Unterlage wird nicht mehr angenommen.',
		'anmeldung' => 'Für diese Veranstaltung ist keine Anmeldung möglich.',
	),
);

/*
 * Texte im Backend (Übersicht, Prüfmaske, Papierkorb).
 */
$GLOBALS['TL_LANG']['MSC']['kinderschutz_be'] = array
(
	'auswahl'         => 'Ansicht',
	'allePersonen'    => 'Alle Personen (Stichtag heute)',
	'stichtag'        => 'Stichtag: %s',
	'nurUnvollstaendig' => 'nur unvollständige',
	'nurWartend'      => 'nur mit wartender Einreichung',
	'anzeigen'        => 'Anzeigen',
	'zaehler'         => '%d vollständig · %d unvollständig · %d warten auf Prüfung',
	'person'          => 'Person',
	'keinePersonen'   => 'Keine Personen gefunden. Sind unter System → Einstellungen kinderschutzpflichtige Mitgliedergruppen gewählt?',
	'keineArten'      => 'Es gibt noch keine aktiven Dokumentarten.',
	'vollstaendig'    => 'vollständig',
	'unvollstaendig'  => 'unvollständig',
	'pruefen'         => 'Einreichung prüfen',
	'neu'             => 'Prüfvermerk anlegen',
	'alleNachweise'   => 'Alle Nachweise dieser Person',
	'keineDatei'      => 'Keine Datei (Prüfvermerk ohne Upload).',
	'dateiImKorb'     => 'Die Datei liegt im Papierkorb und wird am %s endgültig gelöscht.',
	'dateiGeloescht'  => 'Die Datei wurde am %s endgültig gelöscht.',
	'dateiFehlt'      => 'Die Datei fehlt auf dem Server.',
	'dateiOeffnen'    => 'Datei in neuem Fenster öffnen',
	'hochgeladenAm'   => 'Hochgeladen am %s: %s (%s)',
	'entschieden'     => 'Entschieden am %s von %s',
	'gueltigBis'      => 'Gültig bis %s',
	'unbegrenzt'      => 'Unbegrenzt gültig',
	'mitgliedGeloescht' => 'Mitglied gelöscht (#%d)',
	'imKorbSeit'      => 'im Papierkorb seit %s, wird am %s gelöscht',
	'inKorbGelegt'    => 'Die Datei liegt jetzt im Papierkorb.',
	'nichtImKorb'     => 'Es wurde nichts geändert: Dieser Nachweis hat keine Datei, oder sie liegt schon im Papierkorb.',
	'wiederhergestellt' => 'Die Datei wurde wiederhergestellt.',
	'geloescht'       => 'Die Datei wurde endgültig gelöscht.',
	'nichtsZuTun'     => 'Die Datei liegt nicht (mehr) im Papierkorb; es wurde nichts geändert.',
	'loeschenFehlgeschlagen' => 'Die Datei konnte nicht gelöscht werden. Bitte die Dateirechte auf dem Server prüfen.',
	'geleert'         => 'Der Papierkorb wurde geleert (%d Dateien).',
	'loeschenGesperrt' => 'Ein Nachweis mit Datei lässt sich nicht direkt löschen. Bitte die Datei zuerst in den Papierkorb legen und dort endgültig löschen.',
	'artGesperrt'     => 'Zu dieser Dokumentart gibt es Nachweise. Bitte deaktivieren statt löschen.',
	'ungueltigesToken' => 'Die Aktion war nicht bestätigt. Bitte erneut auslösen.',
	'grundFehlt'      => 'Bitte eine Begründung für die Ablehnung angeben; sie geht an die Person.',
	'einschlaegigFehlt' => 'Bitte angeben, ob einschlägige Einträge vorliegen.',
	'mailGescheitert' => 'Die Ablehnung ist gespeichert, aber die Mail an die Person konnte nicht verschickt werden. Bitte die Person direkt informieren.',
	'nurHauptadmin'   => 'Die Prüferfreigabe kann nur der Hauptadmin ändern.',
);
