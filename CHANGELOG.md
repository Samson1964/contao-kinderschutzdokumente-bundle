# Änderungen

## Version 1.2.1 (2026-10-16)

* Fix: Gelöschte Datensätze aus allen Kinderschutz-Tabellen sind unter System → Wiederherstellen für alle ohne Prüferfreigabe unsichtbar und gesperrt – auch für Administratoren, denen Contao dort alle Einträge zeigt. Sie fehlen in Liste, Filterauswahl und Suche; „Details“, „Wiederherstellen“ und jede andere Aktion auf einen solchen Eintrag über eine von Hand gebaute Adresse ergeben 403. Verschieben (`cut`, `cutAll`, `paste`) ist für sie dort ganz gesperrt, sonst ließe sich über eine nachgebaute Anfrage der Besitzer eines verborgenen Eintrags ändern. Hauptadmin und Prüfer sind nicht betroffen. Die Kopien bleiben bis zum Ablauf der Speicherzeit für Undo-Schritte in der Datenbank

## Version 1.2.0 (2026-10-16)

* Add: Antrag auf Freischaltung im Frontend-Modul „Meine Unterlagen“ für Mitglieder außerhalb der Freischaltgruppe, mit Begründung („Wofür“, höchstens 500 Zeichen) und kommenden wählbaren Veranstaltungen; Mail an die Prüfer. Ein offener Antrag wird samt Veranstaltungen angezeigt, ein abgelehnter mit Begründung und neuem Formular
* Add: Backend-Menüpunkt „Anträge“: freischalten (Gruppe, Veranstaltungen, Mail), ablehnen mit Begründung per Mail, „Mitglied freischalten“ ohne Antrag; nach der Entscheidung nur noch lesbar; Hinweis auf offene Anträge in der Übersicht
* Add: Betreff und Text aller acht Mails unter Kinderschutz → Einstellungen pflegbar, leer = Standardtext (grau im Feld); drei neue Mails „Neuer Antrag“, „Freigeschaltet“ und „Antrag abgelehnt“
* Change: Einstellungen von System → Einstellungen in den Kinderschutzbereich verschoben (Menüpunkt „Einstellungen“, änderbar für Hauptadmin und Prüfer); die gespeicherten Werte bleiben. Eine „Gruppe der freigeschalteten Mitglieder“ ersetzt die kinderschutzpflichtigen Gruppen; die erste bisher gewählte gilt weiter und wird beim ersten Speichern der Maske übernommen. Mitglieder weiterer bisher gewählter Gruppen haben nach dem Update keinen Zugang zu den Unterlagen mehr und bekommen keine Erinnerungen, bis sie in die eine Gruppe aufgenommen sind. Nach dem Update den Seitenschutz der Unterlagenseite prüfen: Sie muss für alle angemeldeten Mitglieder erreichbar sein, sonst sehen nicht freigeschaltete Mitglieder die Zugriffsverweigerung statt des Antragsformulars
* Change: „Seite mit ‚Meine Unterlagen‘“ ist eine Auswahl der Seiten, auf denen das Frontend-Modul als Inhaltselement eingebunden ist, und damit auch für Prüfer ohne Rechte am Seitenmodul wählbar; die Erklärung nennt, dass das Modul dort eingebunden sein muss
* Fix: Verschieben (Kinderschutz-Tabellen) und Kopieren (Nachweise) über eine von Hand gebaute Adresse endeten teils mit HTTP 500 statt 403
* Fix: Die Sperren für Person und Dokumentart in „Mehrere bearbeiten“ bei Nachweisen ließen sich über eine von Hand gebaute Adresse in anderer Schreibweise (etwa `act=editall`) umgehen; die Aktion wird jetzt so geprüft, wie Contao sie ausführt

## Version 1.1.0 (2026-10-16)

* Add: Eigenes Symbol (Schild mit Herz) für die Menügruppe „Kinderschutz“ im Backend. Es nimmt die Farbe der übrigen Gruppensymbole an, in Contao 4.13 wie in Contao 5.7 hell und dunkel, und steht auf jeder Backend-Seite (eingebunden über `contao.backend.custom_css`; eigene Angaben des Projekts dort bleiben erhalten).

## Version 1.0.0 (2026-10-16)

* Add: Frontend-Modul „Kinderschutz: Meine Unterlagen“ mit Checkliste je Dokumentart, Upload (PDF, JPG, PNG, Typ am Inhalt erkannt, mit Ausstellungsdatum) und An- und Abmeldung zu Veranstaltungen; ein beanstandetes EFZ erscheint nur als „bitte Rücksprache halten“
* Add: Backend-Übersicht als Matrix Personen × Dokumentarten, für alle Personen oder je Veranstaltung (Stichtag: letzter Veranstaltungstag), mit Filtern und Zählern
* Add: Prüfmaske mit Dateivorschau, Abfrage „einschlägige Einträge“, Gültigkeit aus Ausstellungsdatum und Monatszahl der Dokumentart, Prüfvermerk ohne Datei, Ablehnung mit Begründung per Mail
* Add: Papierkorb für Dateien mit Wiederherstellen, endgültigem Löschen, Leeren und Löschfrist; geprüfte EFZ, abgelehnte und ersetzte Dateien sowie die Dateien im Backend gelöschter Mitglieder landen dort
* Add: Erinnerungsmails vor und bei Ablauf und vor Veranstaltungen über einen täglichen Cronjob, Hinweismail an die Prüfer bei neuen Einreichungen; synchroner Versand mit Rückmeldung bei gescheiterter Ablehnungsmail
* Add: Dokumentarten und Veranstaltungen samt Teilnehmern im Backend pflegbar; Löschsperren für Nachweise mit Datei und Dokumentarten mit Nachweisen
* Add: Zugang nur für den Hauptadmin und freigegebene Prüfer, auch nicht für andere Administratoren; Prüferfreigabe am Backend-Benutzer, die nur der Hauptadmin sieht und setzt
* Add: Dateiablage außerhalb des Webroots unter Zufallsnamen, Ausgabe nur über eine geschützte Backend-Route; Einträge des Bundles im Systemprotokoll ohne Namen und Mailadressen
* Add: Einstellungen unter System → Einstellungen und Bundle-Parameter `hauptadmin`, `speicherpfad` und `upload_max_mb`
* Add: Lauffähig unter Contao 4.13 und Contao 5.7 aus derselben Fassung, geprüft gegen Contao 4.13.58 und 5.7.7 mit PHP 8.4.24
