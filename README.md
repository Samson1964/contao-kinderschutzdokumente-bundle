# Kinderschutz-Unterlagen für Contao

Trainer, Betreuer und Ehrenamtliche reichen ihre Kinderschutz-Unterlagen – etwa das erweiterte
Führungszeugnis (EFZ), den Ehrenkodex und den Schulungsnachweis – über ihr Mitgliedskonto ein.
Die Prüfer sehen im Backend auf einen Blick, wer vollständig ist und was fehlt, auch für eine
bestimmte Veranstaltung. Nach der Einsichtnahme bleibt beim EFZ nur der Prüfvermerk; die Datei
wandert in einen Papierkorb und wird nach einer Frist endgültig gelöscht.

Läuft unter Contao 4.13 und Contao 5.7 mit PHP ab 8.1 (geprüft mit PHP 8.4).

## Funktionen

* **Meine Unterlagen** (Frontend-Modul): Checkliste je Dokumentart mit Stand, Gültigkeit und
  Upload (PDF, JPG, PNG; der Typ wird am Inhalt erkannt), dazu die eigenen Veranstaltungen mit
  „Unterlagen vollständig“ oder „Es fehlt: …“ und die Selbstanmeldung zu freigegebenen
  Veranstaltungen. Mitglieder können Dateien nicht herunterladen, auch die eigenen nicht.
* **Antrag auf Freischaltung** im selben Frontend-Modul: Wer noch nicht freigeschaltet ist,
  sieht statt der Checkliste ein Formular („Wofür brauchen Sie die Freischaltung?“ und die
  kommenden wählbaren Veranstaltungen zum Ankreuzen). Die Prüfer bekommen eine Mail.
* **Anträge** (Backend): freischalten (Aufnahme in die Gruppe der freigeschalteten Mitglieder,
  Übernahme der angekreuzten Veranstaltungen, Mail) oder ablehnen (Begründung per Mail);
  „Mitglied freischalten“ ohne Antrag. Offene Anträge meldet die Übersicht.
* **Übersicht** (Backend): Matrix Personen × Dokumentarten, wahlweise mit Stichtag heute oder
  für eine Veranstaltung (Stichtag ist ihr letzter Tag), Filter „nur unvollständige“ und „nur
  mit wartender Einreichung“.
* **Prüfmaske** mit Dateivorschau: Ausstellungsdatum bestätigen, beim EFZ „einschlägige
  Einträge ja/nein“, geprüft oder abgelehnt (Begründung geht per Mail an die Person).
  Prüfvermerke ohne Datei für vorgezeigte Papierdokumente.
* **Papierkorb**: Geprüfte EFZ, abgelehnte und ersetzte Dateien landen dort, ebenso die Dateien
  im Backend gelöschter Mitglieder und alles, was ein Prüfer von Hand hineinlegt.
  Wiederherstellen, einzeln endgültig löschen, ganz leeren; nach der eingestellten Frist löscht
  der Cronjob.
* **Mails**: an die Prüfer bei neuen Einreichungen und neuen Anträgen, an die Person bei
  Ablehnung, vor und bei Ablauf eines Nachweises, vor Veranstaltungen, wenn Unterlagen fehlen,
  sowie bei Freischaltung und abgelehntem Antrag. Betreff und Text aller acht Mails lassen sich
  im Backend anpassen.
* **Einstellungen** (Backend, Menüpunkt im Kinderschutzbereich): Gruppe, Seite, Absender,
  Fristen und Mailtexte – änderbar für Hauptadmin und Prüfer.
* **Dokumentarten** und **Veranstaltungen** samt Teilnehmern im Backend pflegbar.
* **Zugang** nur für den Hauptadmin und von ihm freigegebene Prüfer – auch andere
  Administratoren sehen nichts.

## Installation

```bash
composer require schachbulle/contao-kinderschutzdokumente-bundle
```

Danach die Datenbank aktualisieren (Contao Manager oder
`vendor/bin/contao-console contao:migrate`). Das gilt auch **nach jedem Update** des Bundles:
Neue Spalten und Tabellen kommen nur so in die Datenbank (wer eine Entwicklungsfassung vor
1.0.0 im Einsatz hatte, bekommt dabei etwa `tl_kinderschutz.gueltigkeit`, mit 1.2.0 kommt die
Tabelle `tl_kinderschutz_antrag` dazu).

## Einrichtung

1. Eine **eigene Mitgliedergruppe** anlegen, etwa „Kinderschutz“ – **nicht** die Gruppe, in die
   jede Registrierung fällt (etwa „Standardmitglied“). Wer in dieser Gruppe ist, gilt als
   freigeschaltet: Er sieht die Checkliste und steht in der Übersicht. Alle anderen angemeldeten
   Mitglieder sehen das Antragsformular; „Freischalten“ nimmt sie in die Gruppe auf. Die
   Mitglieder brauchen ein Konto mit Anmeldung; eine Selbstregistrierung bringt das Bundle nicht
   mit (Contaos Registrierungsmodul genügt).
2. **Kinderschutz → Dokumentarten** anlegen. Neue Arten sind zunächst inaktiv: „Aktiv“ setzen,
   bei verlangten Unterlagen außerdem „Pflichtdokument“. Für das EFZ: Gültigkeit 24 Monate,
   „Datei nach der Prüfung in den Papierkorb“ und „Einschlägige Einträge abfragen“.
3. Eine **Seite** mit dem Anmeldemodul und dem Frontend-Modul „Kinderschutz: Meine Unterlagen“
   anlegen. Wird die Seite geschützt, dann für **alle angemeldeten Mitglieder** (etwa für die
   Gruppe, in die jede Registrierung fällt), **nicht** nur für die Gruppe aus Schritt 1: Sonst
   sehen noch nicht freigeschaltete Mitglieder die Zugriffsverweigerung statt des
   Antragsformulars. Das Frontend-Modul gehört als **Inhaltselement „Modul“ in einen
   Artikel** der Seite: Nur so findet die Einstellungsmaske die Seite. Über das Seitenlayout oder
   in einem verschachtelten Element eingebundene Module erkennt sie nicht. Am Startpunkt muss die
   Domain eingetragen sein, sonst stimmen die Links in den Mails nicht: Der Cronjob läuft ohne
   Seitenaufruf und kennt die Domain sonst nicht.
4. **Kinderschutz → Einstellungen**, Abschnitte „Allgemein“ und „Fristen“:
   * „Gruppe der freigeschalteten Mitglieder“ – die Gruppe aus Schritt 1,
   * „Seite mit „Meine Unterlagen““ – zur Wahl stehen die Seiten, auf denen das Frontend-Modul
     als Inhaltselement eingebunden ist (auch unveröffentlichte); sie wird in den Mails verlinkt,
   * „Absenderadresse“ (leer: die Adresse des Systemadministrators),
   * „Papierkorbfrist in Tagen“ (leer: 30),
   * „Erinnerung vor Ablauf in Wochen“ (leer: 8; derselbe Vorlauf entscheidet in der Übersicht
     über „läuft bald ab“),
   * „Erinnerung vor Veranstaltungen in Wochen“ (leer: 6).

   Darunter folgen zugeklappt die Mailtexte (siehe [Mailversand](#mailversand)). Die Maske
   ändern Hauptadmin und Prüfer; unter System → Einstellungen steht nichts mehr vom Bundle.
5. **Prüfer freigeben**: Der Hauptadmin (Vorgabe: Backend-Benutzer mit ID 1) setzt unter
   Benutzerverwaltung → Benutzer beim jeweiligen Benutzer in der Legende „Kinderschutz“ das
   Häkchen „Kinderschutz-Prüfer“. Weitere Rechte sind nicht nötig: Ein freigegebener Prüfer
   sieht die Menügruppe „Kinderschutz“ auch ohne jede Modul- oder Gruppenberechtigung. In der
   Modulauswahl der Benutzergruppen erscheinen die Kinderschutz-Module gar nicht.
6. **Cronjob**: Erinnerungen und Papierkorbfrist laufen einmal täglich über den Contao-Cron. Der
   Web-Cron bleibt bei wenig Besuch aus; besser per System-Cron minütlich
   `vendor/bin/contao-console contao:cron` aufrufen.
7. Prüfen, dass die Installation **Mails verschicken** kann (siehe [Mailversand](#mailversand)).

## Update von 1.0 oder 1.1

1. **Datenbank aktualisieren** (Contao Manager oder `vendor/bin/contao-console contao:migrate`):
   Die Anträge brauchen die neue Tabelle `tl_kinderschutz_antrag`.
2. Die Einstellungen stehen jetzt unter **Kinderschutz → Einstellungen**; die gespeicherten
   Werte bleiben. Aus der früheren Mehrfachauswahl „Kinderschutzpflichtige Mitgliedergruppen“
   wird die eine „Gruppe der freigeschalteten Mitglieder“: Bis zum ersten Speichern der Maske
   gilt die **erste** der bisher gewählten Gruppen weiter, die Maske zeigt sie vorgewählt, und
   das erste Speichern übernimmt sie (die alte Einstellung verschwindet dabei). Mitglieder
   weiterer bisher gewählter Gruppen gelten ab dem Update nicht mehr als freigeschaltet –
   sie in die eine Gruppe aufnehmen oder Anträge stellen lassen.
3. Wer bisher eine Gruppe gewählt hatte, in die jede Registrierung fällt („Standardmitglied“
   o. ä.), legt eine eigene Gruppe an, nimmt die Betroffenen dort auf (in der
   Mitgliederverwaltung oder über „Mitglied freischalten“) oder lässt sie Anträge stellen, und
   wählt die neue Gruppe in den Einstellungen.
4. Die Seite mit „Meine Unterlagen“ prüfen: Eine bisher gewählte Seite, auf der das Modul nicht
   als Inhaltselement gefunden wird (etwa weil es über das Seitenlayout eingebunden ist), bleibt
   gewählt und erscheint mit dem Zusatz „ohne Frontend-Modul“.
5. **Seitenschutz der Unterlagenseite prüfen**: Ist die Seite nur für die Gruppe der
   freigeschalteten Mitglieder freigegeben, sehen alle anderen angemeldeten Mitglieder die
   Zugriffsverweigerung statt des neuen Antragsformulars. Die Seite für alle angemeldeten
   Mitglieder freigeben (etwa für die Gruppe, in die jede Registrierung fällt).

## Konfiguration

Drei Werte stehen absichtlich nicht in der Oberfläche, sondern in `config/config.yaml`: Wer den
Hauptadmin oder den Ablageort ändern kann, kontrolliert den Zugang zu den Führungszeugnissen.

```yaml
contao_kinderschutzdokumente:
    hauptadmin: 1                                        # ID in tl_user, mindestens 1
    speicherpfad: '%kernel.project_dir%/var/kinderschutz' # außerhalb des Webroots
    upload_max_mb: 10                                    # mindestens 1
```

* `upload_max_mb`: Wirksam ist der kleinste Wert aus dieser Angabe, `upload_max_filesize` und
  `post_max_size` der PHP-Einstellungen; das Frontend nennt diese wirksame Grenze.
* `speicherpfad`: In der Datenbank stehen nur Pfade relativ zu diesem Ordner. Wer ihn ändert,
  zieht den Inhalt des alten Ordners mit um.

## Mailversand

Die Mails gehen **synchron** über den Standardtransport des Symfony-Mailers (den ersten aus
`framework.mailer`, meist `MAILER_DSN`) – nicht über die Warteschlange und nicht über Contaos
eigenen Mailer. Nur so erfährt das Bundle sofort, ob eine Mail hinausging.

* Hochladen und Speichern warten, bis der Mailserver geantwortet hat. Im Prüfstand, dessen
  Mailserver die Verbindung abwies, dauerte ein Upload dadurch rund 4 Sekunden; antwortet der
  Server gar nicht, wartet die Anfrage bis zum Timeout der Verbindung.
* Die Transportwahl am Startpunkt samt der Absenderadresse, die unter
  `contao.mailer.transports` für diesen Transport hinterlegt ist, und in Contao 5.7
  `contao.mailer.override_from` gelten für diese Mails nicht.
* Ohne Absenderadresse (weder in den Einstellungen noch als Adresse des Systemadministrators)
  geht keine Mail hinaus. Mitglieder ohne E-Mail-Adresse bekommen keine Mail; solange eine
  Erinnerung fällig ist, steht dann bei jedem täglichen Lauf ein Fehlereintrag im Protokoll.
* Scheitert eine Mail, schreibt das Bundle einen Eintrag ins Systemprotokoll, der nur Mailart,
  Zahl der Empfänger und Fehlerklasse nennt – keine Adresse.
  * **Ablehnung**: Die Entscheidung bleibt gespeichert, das Backend zeigt „Die Ablehnung ist
    gespeichert, aber die Mail an die Person konnte nicht verschickt werden. Bitte die Person
    direkt informieren.“ Einen zweiten Versuch gibt es nicht.
  * **Freischaltung und abgelehnter Antrag**: Die Entscheidung bleibt gespeichert, das Backend
    zeigt „Die Entscheidung ist gespeichert, aber die Mail an das Mitglied konnte nicht
    verschickt werden. Bitte das Mitglied direkt informieren.“
  * **Neue Einreichung** und **neuer Antrag**: Upload bzw. Antrag gelten trotzdem; es bleibt
    beim Protokolleintrag.
  * **Erinnerungen**: Der Versandmerker wird nur bei Erfolg gesetzt; der nächste tägliche Lauf
    versucht es erneut.

### Mailtexte

Betreff und Text jeder der acht Mails lassen sich unter **Kinderschutz → Einstellungen** in
einem eigenen, zugeklappten Abschnitt ändern. **Ein leeres Feld heißt Standardtext**; der
Standardtext steht grau als Platzhalter im leeren Feld. Die Mails sind reiner Text ohne HTML.
Platzhalter in geschweiften Klammern werden ersetzt, unbekannte bleiben stehen; welche es je
Mail gibt, nennt die Maske unter dem Textfeld und [docs/bedienung.md](docs/bedienung.md#einstellungen-und-mailtexte).

* Zeilenumbrüche bleiben erhalten. Mehrere Leerzeichen hintereinander innerhalb einer Zeile
  fasst Contao beim Speichern zu einem zusammen.
* Die Standardtexte stehen in `src/Resources/contao/languages/de/default.php` unter
  `$GLOBALS['TL_LANG']['MSC']['kinderschutz_mail']` und lassen sich im Projekt weiterhin in
  `contao/languages/de/default.php` überschreiben; ein Text aus den Einstellungen geht beidem
  vor.
* Was in die Platzhalter kommt (Namen, Begründungen, Antragstext), speichert Contao teils
  HTML-kodiert; für die Textmail wird es vor dem Einsetzen entkodiert, damit etwa „<“ oder „(“
  nicht als Entität ankommen.

## Zugriffsschutz und seine Grenzen

Die Kinderschutz-Module sind nur für den Hauptadmin und freigegebene Prüfer sichtbar – **auch
andere Administratoren sehen nichts**. Die Menügruppe wird für alle anderen ausgeblendet, jede
Maske, jede Aktion und die Dateiausgabe prüfen selbst (sonst HTTP 403), und unter
Contao 5 sperrt ein eigener Voter die Tabellen auch für die Backend-Suche. Contaos eigene Modul-
und Tabellenrechte sind für diese Module abgeschaltet; die Freigabe allein genügt.

* Das gilt auch für die **Anträge** und die **Einstellungen** samt Mailtexten: Beide
  Menüpunkte sehen und ändern nur Hauptadmin und Prüfer; andere Administratoren bekommen auch
  beim Absenden der Einstellungsmaske 403.
* Das Feld „Kinderschutz-Prüfer“ sieht und ändert nur der Hauptadmin; für alle anderen fehlt es
  in der Maske, im Filter der Benutzerliste und unter „Details“. Beim Kopieren eines Benutzers
  wird die Freigabe nicht mitkopiert, und im eigenen Profil kann sich niemand selbst freigeben.
* Nachweise lassen sich nicht kopieren (eine Kopie zeigte auf dieselbe Datei), Anträge ebenso
  wenig. Für Nachweise ist die Versionierung aus, es entstehen keine Kopien in `tl_version`.
* Was die Oberfläche nicht anbietet, ist auch über eine von Hand gebaute Adresse gesperrt
  (HTTP 403): Verschieben (`cut`, `cutAll`, `paste` mit `mode=cut`) in allen
  Kinderschutz-Tabellen, Kopieren (`copy`, `copyAll`) von Nachweisen und Anträgen und bei den
  Anträgen alle Sammelaktionen (Auswahl, „Mehrere bearbeiten“, „Überschreiben“, mehrere löschen
  oder kopieren). Die Sperren gelten unabhängig von der Schreibweise (`act=editall`,
  `act=Cut`) und lesen die Aktion so, wie Contao sie ausführt; enthält `act` andere Zeichen als
  Buchstaben oder `mode` andere als Buchstaben und Ziffern (etwa Entitäten oder Steuerzeichen),
  gibt es ebenfalls 403.
* Die Dateien liefert nur eine eigene Backend-Route aus; jeder Abruf steht im Systemprotokoll.
* Unter **System → Wiederherstellen** (`tl_undo`) sind gelöschte Kinderschutz-Datensätze
  (Nachweise, Anträge, Dokumentarten, Veranstaltungen) für alle ohne Freigabe **unsichtbar und
  gesperrt** – auch für Administratoren, denen Contao dort sonst alle Einträge zeigt. Sie fehlen
  in der Liste, in der Filterauswahl und in der Suche; „Details“, „Wiederherstellen“ und jede
  andere Aktion auf einen solchen Eintrag über eine von Hand gebaute Adresse
  (`act=show&id=…`, `act=undo&id=…`) ergeben 403, ebenso eine ID aus anderen Zeichen als
  Ziffern. Hauptadmin und Prüfer sehen und holen sie zurück wie bisher (Nicht-Administratoren
  wie im Kern nur die eigenen Schritte).

Das schützt vor Neugier im Backend, nicht vor einem Administrator mit bösen Absichten (er kann
etwa Erweiterungen installieren) und nicht vor Zugriff auf Server oder Datenbank. Bekannte
Grenzen rund um Contaos „Wiederherstellen“ (`tl_undo`):

* Löscht ein Prüfer einen Nachweis oder einen Antrag, legt Contao vorher eine Kopie in
  `tl_undo` ab, im Klartext. Sie bleibt dort, solange die „Speicherzeit für Undo-Schritte“
  läuft (`undoPeriod`, Vorgabe 2 592 000 Sekunden = 30 Tage). Beim Nachweis gehören dazu
  „einschlägige Einträge“, Ablehnungsgrund, Bemerkung und Dateiname, beim Antrag Mitglied,
  „Wofür“, Stand und Begründung einer Ablehnung. Im Backend sehen sie nur Hauptadmin und
  Prüfer; **wer direkt auf die Datenbank zugreift** (phpMyAdmin, Sicherungen), sieht sie
  trotzdem. Wer das nicht will, kürzt diese Speicherzeit (System → Einstellungen →
  Speicherzeiten; gilt für die ganze Installation).
* Beim **gesperrten Löschversuch** eines Nachweises mit Datei (ebenso einer Dokumentart mit
  Nachweisen) bleibt trotzdem eine Kopie in `tl_undo`, weil Contao sie vor der Löschsperre des
  Bundles anlegt. Der Datensatz selbst bleibt bestehen.
* Das **Wiederherstellen einer solchen Kopie** kann doppelte oder kollidierende Datensätze
  erzeugen. Kopien noch bestehender Datensätze deshalb nicht wiederherstellen.

## Datenschutz

* Für das EFZ gilt § 72a SGB VIII: einsehen und dokumentieren, nicht dauerhaft speichern. Bei
  Dokumentarten mit „Datei nach der Prüfung in den Papierkorb“ geht die Datei nach der
  Entscheidung „geprüft“ in den Papierkorb und wird nach der Frist gelöscht. Im Nachweis bleiben
  Person, Dokumentart, Ausstellungsdatum, Entscheidung mit Datum und Prüfer, „einschlägige
  Einträge ja/nein“, „gültig bis“, gegebenenfalls Ablehnungsgrund und interne Bemerkung sowie
  Name, Typ, Größe und Hochladezeit der ursprünglichen Datei.
* **Bitte den Ablauf mit dem Datenschutzbeauftragten abstimmen**, vor allem Papierkorbfrist,
  Kreis der Prüfer und die Aufbewahrung von Sicherungen.
* Die Dateien liegen unverschlüsselt in `var/kinderschutz/` außerhalb des Webroots (unter
  zufälligen Namen, mit `.htaccess` „Require all denied“). Der Ordner gehört ins Server-Backup,
  aber in kein Git und in kein Deployment, das `var/` neu anlegt. Was in einer Sicherung liegt,
  überdauert die Papierkorbfrist.
* Mails nennen nie ein Prüfergebnis wie „einschlägige Einträge“. Die Begründung einer Ablehnung
  geht allerdings wörtlich an die Person.
* Die Einträge des Bundles im Systemprotokoll nennen nur IDs (und die Dokumentart), keine Namen
  oder Adressen. **Contao 5.7 selbst** schreibt aber zu jeder Mail die Empfängeradresse ins
  Protokoll („An e-mail has been sent to …“ bzw. „Failed to send e-mail to …“); zusammen mit
  den zeitgleichen Einträgen des Bundles lässt sich so ablesen, wer etwa eine Ablehnung bekam.
  Das ist Verhalten des Kerns und lässt sich vom Bundle aus nicht abstellen.
* Wird ein Mitglied **im Backend** gelöscht, wandern seine Dateien in den Papierkorb; die
  Nachweise bleiben als „Mitglied gelöscht (#ID)“ stehen, bis ein Prüfer sie löscht. Das
  Frontend-Modul „Konto schließen“ mit Löschen des Kontos umgeht diesen Weg: Die Dateien bleiben
  dann liegen, bis ein Prüfer sie unter „Nachweise“ selbst in den Papierkorb legt.
* **Anträge** bleiben nach der Entscheidung mit „Wofür“, angekreuzten Veranstaltungen,
  Entscheidung, Datum und Prüfer stehen, bis ein Prüfer sie löscht; die Anträge gelöschter
  Mitglieder erscheinen als „Mitglied gelöscht (#ID)“. Der Antragstext geht in der Mail „Neuer
  Antrag“ an die Prüfer, die Begründung einer Ablehnung wörtlich an das Mitglied.

Die Bedienung für Prüfer beschreibt [docs/bedienung.md](docs/bedienung.md).

## Entwicklung

```bash
composer update
vendor/bin/phpunit
```

Die Tests laufen ohne Contao-Installation (SQLite im Arbeitsspeicher, PHP-Erweiterung
`pdo_sqlite`). Das Zusammenspiel mit Contao wird an zwei Testinstallationen (4.13 und 5.7) mit
den Skripten unter `pruefstand/` geprüft: `router.php` für den eingebauten PHP-Server,
`testdaten.php` für Testgruppe, -konten, Dokumentarten, Veranstaltungen und Seite, und
`schema-anlegen.php`, das nur die Tabellen und Felder dieses Bundles anlegt. Es prüft alle
Befehle vorher und bricht ab, bevor es fremde Spalten ändert oder etwas löscht (`--nur-zeigen`
listet nur).
