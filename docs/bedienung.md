# Bedienung für Prüfer

Alles liegt im Backend in der Menügruppe **Kinderschutz**: Übersicht, Nachweise, Papierkorb,
Veranstaltungen und Dokumentarten. Wer die Gruppe nicht sieht, ist nicht freigegeben – die
Freigabe setzt der Hauptadmin am Backend-Benutzer. Weitere Rechte braucht ein Prüfer nicht.
Hauptadmin und Prüfer haben in diesen Modulen dieselben Möglichkeiten.

## Übersicht

Oben wählt man unter **Ansicht** entweder **Alle Personen (Stichtag heute)** – das sind die
aktiven Mitglieder der kinderschutzpflichtigen Gruppen – oder eine **Veranstaltung** mit ihren
Teilnehmern. Bei einer Veranstaltung zählt ihr letzter Tag (ohne letzten Tag der erste): Die
Unterlagen müssen die ganze Veranstaltung über gelten. Der zweite Schalter filtert auf **nur
unvollständige** oder **nur mit wartender Einreichung**; „Anzeigen“ übernimmt beides.

Darunter stehen der Stichtag und die Zähler „vollständig · unvollständig · warten auf Prüfung“.
Die Tabelle zeigt je Person (Zeile) und aktiver Dokumentart (Spalte) den Stand:

| Zeichen | Stand | Farbe | Bedeutung |
| --- | --- | --- | --- |
| ✔ | gültig | grün | geprüft und am Stichtag gültig |
| ⏳ | läuft bald ab | gelb | gültig, läuft aber innerhalb des Erinnerungsvorlaufs (Vorgabe 8 Wochen) nach dem Stichtag ab |
| ✉ | eingereicht | blau | nichts Gültiges vorhanden, eine Einreichung wartet auf Prüfung |
| ✖ | abgelehnt | rot | nichts Gültiges vorhanden, die jüngste Einreichung wurde abgelehnt |
| ✖ | abgelaufen | rot | der geprüfte Nachweis gilt am Stichtag nicht mehr |
| – | fehlt | rot, bei freiwilligen Arten grau | noch nichts eingereicht |
| ⚠ | beanstandet | dunkelrot, fett | der maßgebliche Nachweis hat „einschlägige Einträge: ja“ |

* Maßgeblich ist immer der geprüfte Nachweis mit dem **jüngsten Ausstellungsdatum**. Ein älterer,
  länger gültiger Nachweis verdrängt nie einen jüngeren – wichtig, wenn das jüngere EFZ
  einschlägige Einträge hat.
* Unter dem Feld steht „Gültig bis …“, sobald es einen geprüften Nachweis mit Ablaufdatum gibt.
* „✉ neue Einreichung wartet“ unter einem gültigen (oder beanstandeten) Feld heißt: Die
  Erneuerung liegt schon vor und wartet auf Prüfung.
* Die letzte Spalte sagt **vollständig** (jede Pflichtart gültig oder „läuft bald ab“) oder
  **unvollständig**. Ein beanstandetes Feld zählt nie als vollständig.
* In der Veranstaltungsansicht erscheinen gelöschte Mitglieder als „Mitglied gelöscht (#ID)“.

Klicks in der Tabelle:

* Klick auf ein Feld mit wartender Einreichung öffnet die **Prüfmaske** dieser Einreichung.
* Klick auf jedes andere Feld öffnet einen neuen **Prüfvermerk ohne Datei** für diese Person und
  Dokumentart (zum Beispiel, wenn jemand das EFZ auf Papier vorgezeigt hat).
* Klick auf den Namen zeigt **alle Nachweise** dieser Person.

## Nachweise

Die Liste zeigt alle Nachweise, nach Tagen gruppiert (Tag des Hochladens bzw. der letzten
Bearbeitung, neueste zuerst): Name, Dokumentart, Entscheidung und dahinter „Gültig bis …“ oder
„im Papierkorb seit …, wird am … gelöscht“. Gefiltert wird nach Person, Dokumentart,
Entscheidung und einschlägigen Einträgen.

Die Entscheidung „ersetzt“ setzt nur das Bundle: Lädt jemand eine neue Datei hoch, während eine
ältere derselben Art noch auf Prüfung wartet, wird die ältere ersetzt und ihre Datei kommt in den
Papierkorb. Ersetzte Nachweise zählen nirgends mit.

## Prüfen

Der Kopf der Prüfmaske nennt Person und Dokumentart, wann welche Datei hochgeladen wurde, wer
wann entschieden hat und bis wann der Nachweis gilt. Darunter steht die Datei: PDFs eingebettet,
Bilder direkt. Liegt die Datei im Papierkorb, nennt die Maske stattdessen den Löschtag und bietet
nur den Link zum Öffnen an.

1. Datei in der Vorschau ansehen (oder „Datei in neuem Fenster öffnen“).
2. **Ausstellungsdatum** mit dem Dokument vergleichen und bei Bedarf korrigieren – die
   Gültigkeit läuft ab diesem Tag.
3. Bei Dokumentarten mit „Einschlägige Einträge abfragen“ (EFZ) **Einschlägige Einträge** auf
   „nein“ oder „ja“ setzen; für „geprüft“ ist das Pflicht.
4. **Entscheidung** wählen und speichern:
   * **geprüft** – „Gültig bis“ wird berechnet: bis einschließlich des Tages vor demselben
     Kalendertag so viele Monate nach dem Ausstellungsdatum, wie die Dokumentart gilt (ausgestellt
     am 10.10.2026, 24 Monate: gültig bis 09.10.2028). Ist an der Dokumentart „Datei nach der
     Prüfung in den Papierkorb“ gesetzt (EFZ), wandert die Datei in den Papierkorb; der
     Prüfvermerk bleibt.
   * **abgelehnt** – eine **Begründung** ist Pflicht; sie geht wörtlich per Mail an die Person.
     Die Datei wandert bei jeder Dokumentart in den Papierkorb.
   * **eingereicht** – stuft einen Nachweis zurück; eine Datei im Papierkorb kommt zurück, damit
     die Frist sie nicht löscht.

Wer eine Entscheidung später ändert, löst ihre Folgen erneut aus (bei „abgelehnt“ also eine neue
Mail). Erneutes Speichern ohne Änderung der Entscheidung verschickt nichts; „Gültig bis“ wird
aber bei jedem Speichern aus dem Ausstellungsdatum neu berechnet. Die Monatszahl übernimmt der
Nachweis bei der Entscheidung „geprüft“ aus der Dokumentart; spätere Änderungen an der
Dokumentart verschieben bestehende Fristen nicht.

Person und Dokumentart sind bei hochgeladenen Nachweisen fest und stehen nur im Kopf der Maske.
Bei Prüfvermerken ohne Datei lassen sie sich in der Maske ändern.

**Gescheiterte Ablehnungsmail:** Konnte die Mail nicht verschickt werden, erscheint auf der
nächsten Seite „Die Ablehnung ist gespeichert, aber die Mail an die Person konnte nicht verschickt
werden. Bitte die Person direkt informieren.“ Die Entscheidung bleibt gespeichert, das Bundle
versucht es nicht noch einmal. Der Hinweis steht je nach Knopf in der Prüfmaske, in der
Nachweisliste oder in der Übersicht. Unter Contao 4.13 erscheint er nach „Mehrere bearbeiten“
oder „Überschreiben“ mit „Speichern“ erst auf der nächsten Listen- oder Maskenseite.

„Speichern und schließen“ führt nach dem Prüfen aus der Übersicht unter Contao 4.13 zurück zur
Übersicht, unter Contao 5.7 zur Nachweisliste.

Jeder Abruf einer Datei steht im Systemprotokoll, ebenso Prüfen, Ablehnen, Papierkorb,
Wiederherstellen und endgültiges Löschen – jeweils mit IDs (und der Dokumentart), ohne
Personennamen.

## Prüfvermerk ohne Datei

Ein Klick auf ein Feld ohne wartende Einreichung legt einen Nachweis an, bei dem Person und
Dokumentart vorbelegt sind und die Entscheidung auf „geprüft“ steht. „Neu“ in der Nachweisliste
tut dasselbe ohne Vorbelegung; zur Wahl stehen dann die Mitglieder der kinderschutzpflichtigen
Gruppen. Das Ausstellungsdatum ist Pflicht, beim EFZ für „geprüft“ auch die einschlägigen
Einträge. Der Kopf der Maske sagt „Keine Datei (Prüfvermerk ohne Upload).“

## Mehrere bearbeiten

Die Listen der Nachweise, Veranstaltungen und Dokumentarten bieten **Mehrere bearbeiten** an
(unter Contao 4.13 ergänzt das Bundle den Knopf selbst; der Papierkorb hat ihn in keiner
Fassung). Nachweise lassen sich dort bearbeiten, überschreiben und löschen, aber nicht kopieren.

* **Person und Dokumentart** sind in „Mehrere bearbeiten“ und „Überschreiben“ gesperrt, auch bei
  Prüfvermerken ohne Datei. Wer sie ändern will, öffnet den einzelnen Prüfvermerk.
* Ablehnen geht auch hier; die Begründung ist für jeden Nachweis Pflicht, und jede Ablehnung
  verschickt ihre eigene Mail.
* **Löschen mehrerer Nachweise:** Contao löscht sie nacheinander. Hat einer davon noch eine
  Datei, bricht das Löschen dort mit der Löschsperre ab; die vor ihm gelöschten ohne Datei sind
  dann schon weg.
* Bei Veranstaltungen lassen sich über „Überschreiben → Teilnehmer“ Personen bei mehreren
  Veranstaltungen auf einmal hinzufügen oder entfernen.

## Papierkorb

Unter **Papierkorb** stehen alle Dateien, die dort liegen – nach dem Tag des Einwurfs gruppiert,
älteste zuerst, jeweils mit dem Tag der endgültigen Löschung. Im Papierkorb liegen geprüfte EFZ,
abgelehnte und ersetzte Dateien, die Dateien im Backend gelöschter Mitglieder und alles, was ein
Prüfer in der Nachweisliste über „In den Papierkorb“ hineingelegt hat. Der Prüfvermerk bleibt in
allen Fällen erhalten.

* **Wiederherstellen** holt die Datei zurück; Entscheidung und Prüfvermerk bleiben, wie sie
  sind. Danach löscht die Frist sie nicht mehr – soll sie wieder weg, legt man sie in der
  Nachweisliste erneut in den Papierkorb.
* **Endgültig löschen** entfernt die Datei sofort vom Server. Das lässt sich nicht rückgängig
  machen. Die Prüfmaske zeigt danach „Die Datei wurde am … endgültig gelöscht.“
* **Papierkorb leeren** löscht alle Dateien darin und nennt, wie viele es waren.
* Nach Ablauf der Papierkorbfrist (Vorgabe 30 Tage, in Kalendertagen ab dem Einwurftag) löscht
  der tägliche Cronjob die Datei.

Die Meldungen sagen, was wirklich geschehen ist: Ein veralteter Link (die Datei wurde schon
wiederhergestellt oder gelöscht) ergibt „Die Datei liegt nicht (mehr) im Papierkorb; es wurde
nichts geändert.“ Lässt sich eine Datei auf dem Server nicht löschen, erscheint „Die Datei konnte
nicht gelöscht werden. Bitte die Dateirechte auf dem Server prüfen.“, und der Eintrag bleibt im
Papierkorb. Beim Leeren werden solche Dateien übersprungen und protokolliert; gezählt werden nur
die gelöschten.

Ein Nachweis mit Datei lässt sich unter **Nachweise** nicht direkt löschen – erst die Datei in
den Papierkorb legen und dort endgültig löschen. Das gilt auch, solange die Datei im Papierkorb
liegt.

## Veranstaltungen

Name, Ort, erster und letzter Tag (leer: der erste Tag). Die Liste ist nach dem ersten Tag
gruppiert, neueste zuerst, und nennt die Zahl der Teilnehmer.

* Ist **Im Frontend wählbar** gesetzt (auch über das Augen-Symbol der Liste), können sich
  Mitglieder bis zum letzten Tag selbst an- und abmelden.
* **Teilnehmer**: Angeboten werden die Mitglieder der kinderschutzpflichtigen Gruppen und alle
  bereits eingetragenen. Wer hier neu angehakt wird, ist für das Mitglied gesperrt (Schloss im
  Frontend). Selbstanmeldungen erscheinen ebenfalls als Haken und bleiben für das Mitglied
  lösbar. Ein entfernter Haken löscht die Teilnahme, auch eine Selbstanmeldung.
* Wird eine Veranstaltung gelöscht, verschwinden ihre Teilnahmen mit. Holt man die Veranstaltung
  über Contaos „Wiederherstellen“ zurück, kommen die Teilnehmer nicht mit.
* Verlässt man eine über „Neu“ angelegte Veranstaltung, ohne zu speichern, kann sie ohne Datum
  in der Liste stehen bleiben; in der Übersicht erscheint sie nicht. Sie lässt sich löschen.

## Dokumentarten

Name, Hinweis für Mitglieder, Pflichtdokument, Gültigkeit in Monaten (0 = unbegrenzt),
Reihenfolge (kleinere Zahlen weiter vorn), beim EFZ zusätzlich „Datei nach der Prüfung in den
Papierkorb“ und „Einschlägige Einträge abfragen“, dazu **Aktiv**. Neue Arten sind zunächst
inaktiv. Inaktive Arten erscheinen weder im Frontend noch in der Übersicht und lösen keine
Erinnerungen aus; ihre Nachweise bleiben erhalten.

Eine Art mit Nachweisen lässt sich nicht löschen, nur deaktivieren.

## Was die Person sieht

Im Frontend stehen je Dokumentart der Stand („noch nicht eingereicht“, „eingereicht, wird
geprüft“, „gültig“, „läuft bald ab“, „abgelaufen“, „nicht anerkannt“ samt Begründung) und das
Formular für eine neue Fassung. Ein beanstandetes EFZ erscheint nur als **„bitte Rücksprache
halten“** – das Prüfergebnis steht weder auf der Seite noch in einer Mail.

## Mails

| Anlass | Empfänger | Wann |
| --- | --- | --- |
| Neue Einreichung | Hauptadmin und alle freigegebenen, nicht gesperrten Prüfer mit Mailadresse, gemeinsam in einer Mail | sofort beim Hochladen; der Link führt zur Übersicht mit „nur mit wartender Einreichung“ |
| Ablehnung | die Person, mit Begründung und Link zur Unterlagenseite | sofort beim Speichern |
| Läuft bald ab | die Person | einmal, sobald „Gültig bis“ innerhalb des Erinnerungsvorlaufs liegt (Vorgabe 8 Wochen) |
| Abgelaufen | die Person | einmal, ab dem Tag nach „Gültig bis“, solange der Ablauf höchstens 14 Tage zurückliegt |
| Fehlende Unterlagen | jeder Teilnehmer, dem am letzten Veranstaltungstag eine Pflichtunterlage fehlt | einmal je Teilnahme, ab dem Vorlauf (Vorgabe 6 Wochen) bis zum Tag vor Beginn |

* Die Erinnerungen verschickt der tägliche Cronjob. Sie gehen nur an aktive Mitglieder der
  kinderschutzpflichtigen Gruppen und nur zu aktiven Dokumentarten.
* Wer schon eine Erneuerung eingereicht hat, bekommt keine Ablaufmails. Für die Mail vor einer
  Veranstaltung zählt eine noch ungeprüfte Einreichung dagegen als fehlend.
* Zu beanstandeten und unbegrenzt gültigen Nachweisen gehen keine Ablaufmails.
* Scheitert eine Erinnerung, versucht es der nächste tägliche Lauf erneut.
