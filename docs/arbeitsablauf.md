# Arbeitsablauf Schritt für Schritt

Diese Anleitung beschreibt den Weg einer Person von der ersten Anmeldung bis zu gültigen
Unterlagen – einmal aus Sicht des **Mitglieds im Frontend**, einmal aus Sicht der **Prüfer im
Backend**. Die Einrichtung durch den Administrator steht in der [README](../README.md), die
vollständige Beschreibung aller Masken in [bedienung.md](bedienung.md).

Kurz zusammengefasst:

1. Mitglied meldet sich an und **beantragt die Freischaltung**.
2. Prüfer **schaltet frei** (oder lehnt ab).
3. Mitglied **lädt seine Unterlagen hoch**.
4. Prüfer **prüft** jede Unterlage – beim Führungszeugnis wird danach nur der Prüfvermerk
   aufbewahrt, die Datei kommt in den Papierkorb.
5. Vor Ablauf und vor Veranstaltungen **erinnert** das System; das Mitglied reicht neue
   Unterlagen ein, und es geht bei Schritt 4 weiter.

---

## Teil A: Mitglied im Frontend

### Schritt 1: Anmelden

1. Die Seite mit „Meine Unterlagen“ aufrufen (den Link gibt es von der Kinderschutzbeauftragten
   oder später in jeder Mail).
2. Mit dem eigenen Mitgliedskonto der Website anmelden. Ohne Konto geht es nicht; wie man eines
   bekommt, regelt die Website.

### Schritt 2: Freischaltung beantragen

Beim ersten Besuch steht „Freischaltung für die Kinderschutz-Unterlagen“ mit einem Formular.

1. Unter **„Wofür brauchen Sie die Freischaltung?“** kurz eintragen, in welcher Rolle man
   Unterlagen einreicht – etwa „Training U12 im Verein X“ oder „Betreuung bei der JWM“
   (höchstens 500 Zeichen).
2. Falls angeboten: die Veranstaltungen ankreuzen, an denen man teilnehmen wird (freiwillig).
3. **„Antrag stellen“** klicken.

Danach steht dort „Ihr Antrag vom … wird geprüft.“ Einen zweiten Antrag braucht es nicht.

### Schritt 3: Auf die Freischaltung warten

* **Freigeschaltet:** Es kommt die Mail „Sie können Ihre Kinderschutz-Unterlagen jetzt
  einreichen“ mit dem Link. Weiter mit Schritt 4.
* **Nicht angenommen:** Es kommt eine Mail mit der Begründung. Auf der Seite steht die
  Begründung ebenfalls, darunter wieder das Formular – nach Klärung kann man einen neuen Antrag
  stellen.

### Schritt 4: Unterlagen hochladen

Nach der Freischaltung zeigt „Meine Unterlagen“ eine Liste aller verlangten Unterlagen (etwa
erweitertes Führungszeugnis, Ehrenkodex, Schulungsnachweis). „Pflicht“ kennzeichnet, was
zwingend nötig ist.

Für jede Unterlage:

1. Das Dokument als **PDF, JPG oder PNG** bereithalten (Foto oder Scan; die Höchstgröße steht am
   Formular).
2. Bei der Unterlage die **Datei auswählen**.
3. Das **Ausstellungsdatum** eintragen – das Datum, das auf dem Dokument steht. Es darf nicht in
   der Zukunft liegen; ab diesem Tag läuft die Gültigkeit.
4. **„Hochladen“** klicken.

Es erscheint „Vielen Dank, die Unterlage „…“ ist eingegangen und wird geprüft.“ Der Stand
wechselt auf „eingereicht, wird geprüft“. Hochgeladene Dateien lassen sich aus Datenschutzgründen
nicht wieder herunterladen, auch nicht die eigenen.

### Schritt 5: Stand verfolgen

Je Unterlage steht der Stand:

| Stand | Bedeutung | Was tun |
| --- | --- | --- |
| noch nicht eingereicht | fehlt | hochladen (Schritt 4) |
| eingereicht, wird geprüft | liegt bei den Prüfern | abwarten |
| gültig (gültig bis …) | in Ordnung | nichts |
| läuft bald ab | gilt noch, aber nicht mehr lange | rechtzeitig neue Fassung hochladen |
| abgelaufen | gilt nicht mehr | neue Fassung hochladen |
| nicht anerkannt | abgelehnt, die Begründung steht darunter | Grund beheben, neu hochladen |
| bitte Rücksprache halten | Klärung nötig | bei der Kinderschutzbeauftragten melden |

Wird eine Unterlage abgelehnt, kommt zusätzlich eine Mail mit der Begründung.

### Schritt 6: Veranstaltungen

* Unter **„Meine Veranstaltungen“** steht je Veranstaltung „Unterlagen vollständig“ oder „Es
  fehlt: …“. Maßgeblich ist der **letzte Tag** der Veranstaltung: Bis dahin müssen alle
  Pflichtunterlagen gültig sein.
* Unter **„Weitere Veranstaltungen“** kann man sich zu freigegebenen Veranstaltungen selbst
  **anmelden** und sich später wieder **abmelden**.
* Steht bei einer Veranstaltung „🔒 Von der Kinderschutzbeauftragten zugeordnet“, hat das Backend
  die Teilnahme eingetragen; abmelden geht dann nur über die Kinderschutzbeauftragte.

### Schritt 7: Erneuern

Das System schickt Mails, wenn eine Unterlage bald abläuft (Vorgabe: 8 Wochen vorher), wenn sie
abgelaufen ist und wenn vor einer Veranstaltung etwas fehlt (Vorgabe: 6 Wochen vorher). Dann
einfach bei der Unterlage **„Neue Fassung hochladen“** – weiter wie in Schritt 4. Die alte
Fassung gilt bis zu ihrem Ablauf weiter.

---

## Teil B: Prüfer im Backend

Alles liegt im Backend in der Menügruppe **Kinderschutz**. Wer sie nicht sieht, ist noch nicht
als Prüfer freigegeben – das erledigt der Hauptadmin am Backend-Benutzer.

### Vorbereitung (einmalig bzw. je Saison)

1. **Kinderschutz → Einstellungen** prüfen: Gruppe der freigeschalteten Mitglieder, Seite mit
   „Meine Unterlagen“, Absenderadresse, Fristen. Mailtexte bei Bedarf anpassen (leer =
   Standardtext).
2. **Kinderschutz → Dokumentarten**: Für jede verlangte Unterlage eine aktive Art mit Gültigkeit
   (Führungszeugnis z. B. 24 Monate, mit „Datei nach der Prüfung in den Papierkorb“ und
   „Einschlägige Einträge abfragen“).
3. **Kinderschutz → Veranstaltungen**: Kommende Veranstaltungen mit erstem und letztem Tag
   anlegen. „Im Frontend wählbar“ setzen, wenn sich Mitglieder selbst anmelden sollen; sonst
   Teilnehmer selbst ankreuzen.

### Schritt 1: Eingänge sichten

Zwei Mails melden Arbeit:

* **„Neuer Antrag auf Freischaltung: …“** → weiter mit Schritt 2.
* **„Neue Kinderschutz-Unterlage: … von …“** → weiter mit Schritt 3.

Ohne Mail findet man dasselbe in **Kinderschutz → Übersicht**: Oben steht „n offene Anträge auf
Freischaltung“ (mit Link), und der Filter **„nur mit wartender Einreichung“** zeigt alle
Personen, bei denen eine Unterlage wartet (✉).

### Schritt 2: Anträge entscheiden

1. **Kinderschutz → Anträge** öffnen; offene Anträge stehen oben.
2. Antrag anklicken. Oben stehen Mitglied mit Mailadresse, Datum, „Wofür“ und die angekreuzten
   Veranstaltungen.
3. Unter **Entscheidung** wählen:
   * **freigeschaltet** – das Mitglied kommt in die Freischaltgruppe, wird zu den angekreuzten
     Veranstaltungen angemeldet und bekommt eine Mail mit dem Link;
   * **abgelehnt** – unter **Begründung** den Grund eintragen (Pflicht); er geht wörtlich per
     Mail an das Mitglied.
4. Speichern. Danach ist der Antrag nur noch lesbar.

**Jemanden ohne Antrag freischalten** (etwa nach einem persönlichen Gespräch): In der Liste der
Anträge **„Mitglied freischalten“** klicken, das Mitglied wählen, bei Bedarf eine Notiz unter
„Wofür“ eintragen, speichern. Das Mitglied bekommt dieselbe Mail.

### Schritt 3: Unterlagen prüfen

1. In der **Übersicht** auf ein Feld mit ✉ klicken – es öffnet sich die Prüfmaske dieser
   Einreichung.
2. Die **Datei** in der Vorschau ansehen (oder „Datei in neuem Fenster öffnen“).
3. Das **Ausstellungsdatum** mit dem Dokument vergleichen und bei Bedarf korrigieren – davon
   hängt „Gültig bis“ ab.
4. Beim Führungszeugnis **„Einschlägige Einträge“** auf „nein“ oder „ja“ setzen.
5. **Entscheidung** wählen und speichern:
   * **geprüft** – „Gültig bis“ wird berechnet. Beim Führungszeugnis wandert die Datei danach in
     den Papierkorb; aufbewahrt wird nur der Prüfvermerk (Ausstellungsdatum, Prüfdatum, Prüfer,
     einschlägige Einträge, gültig bis).
   * **abgelehnt** – **Begründung** eintragen (Pflicht, geht wörtlich per Mail an die Person).
     Die Datei kommt in den Papierkorb; die Person lädt eine neue hoch.

Erscheint danach „… aber die Mail an die Person konnte nicht verschickt werden“, die Person bitte
direkt informieren – die Entscheidung ist trotzdem gespeichert.

**Hat jemand ein Dokument nur vorgezeigt** (etwa das Führungszeugnis auf Papier): In der
Übersicht auf das Feld dieser Person und Unterlage klicken. Es öffnet sich ein **Prüfvermerk
ohne Datei** mit vorbelegter Person und Unterlage – Ausstellungsdatum (und beim
Führungszeugnis die einschlägigen Einträge) eintragen, „geprüft“ lassen, speichern.

### Schritt 4: Eine Veranstaltung vorbereiten

1. In der **Übersicht** unter **Ansicht** die Veranstaltung wählen. Stichtag ist ihr letzter Tag.
2. Den Filter **„nur unvollständige“** setzen und „Anzeigen“ klicken.
3. Bei jeder roten (✖, –) oder gelben (⏳) Zelle nachfassen: Einreichung abwarten und prüfen
   (Schritt 3) oder die Person ansprechen. Die automatische Mail „Fehlende Unterlagen für …“
   geht vorher schon von selbst hinaus.
4. Unmittelbar vor der Veranstaltung noch einmal prüfen: Steht in der letzten Spalte überall
   **vollständig**, ist alles in Ordnung. ⚠ („beanstandet“) heißt: einschlägige Einträge – diese
   Person darf nicht eingesetzt werden, auch wenn alles andere gültig ist.

### Schritt 5: Papierkorb im Blick behalten

* Geprüfte Führungszeugnisse, abgelehnte und ersetzte Dateien liegen im **Papierkorb** und
  werden nach der Frist (Vorgabe 30 Tage) vom System endgültig gelöscht.
* Versehentlich gelöscht? Unter **Kinderschutz → Papierkorb** **„Wiederherstellen“** – der
  Prüfvermerk bleibt, wie er ist.
* Soll eine Datei sofort weg, **„Endgültig löschen“**; das lässt sich nicht rückgängig machen.

### Schritt 6: Wenn jemand ausscheidet

Eine Freischaltung nimmt ein Administrator in der Mitgliederverwaltung zurück (Mitglied aus der
Gruppe der freigeschalteten Mitglieder nehmen). Die Person verschwindet dann aus der Übersicht
und bekommt keine Erinnerungen mehr; ihre Prüfvermerke bleiben erhalten, bis ein Prüfer sie
unter **Nachweise** löscht. Ein Nachweis mit Datei lässt sich erst löschen, wenn die Datei über
den Papierkorb endgültig gelöscht ist.

---

## Wer bekommt welche Mail?

| Mail | An | Auslöser |
| --- | --- | --- |
| Neuer Antrag | Prüfer | Mitglied stellt einen Antrag |
| Freigeschaltet | Mitglied | Prüfer schaltet frei |
| Antrag abgelehnt | Mitglied | Prüfer lehnt den Antrag ab |
| Neue Einreichung | Prüfer | Mitglied lädt eine Unterlage hoch |
| Unterlage abgelehnt | Mitglied | Prüfer lehnt eine Unterlage ab |
| Läuft bald ab | Mitglied | täglicher Lauf, Vorgabe 8 Wochen vor Ablauf |
| Abgelaufen | Mitglied | täglicher Lauf, nach Ablauf |
| Fehlende Unterlagen | Mitglied | täglicher Lauf, Vorgabe 6 Wochen vor einer Veranstaltung |

Mails nennen nie ein Prüfergebnis wie „einschlägige Einträge“.
