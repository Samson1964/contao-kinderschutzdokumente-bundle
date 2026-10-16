# Änderungen

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
