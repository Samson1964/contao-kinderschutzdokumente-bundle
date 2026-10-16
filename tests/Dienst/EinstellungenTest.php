<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Contao\Config;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;

/**
 * Prüft die Gruppe der freigeschalteten Mitglieder samt Übergang vom alten
 * Schlüssel und das Lesen der Mailvorlagen.
 */
class EinstellungenTest extends Testhilfen
{
	/**
	 * Der neue Schlüssel liefert die eine Gruppe.
	 */
	public function testEineGruppe(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '5';

		$this->assertSame(5, $this->einstellungen()->gruppe());
		$this->assertSame(array(5), $this->einstellungen()->gruppen());
	}

	/**
	 * Ohne neuen Schlüssel gilt die erste Gruppe aus Fassung 1.0/1.1.
	 */
	public function testUebergangVomAltenSchluessel(): void
	{
		unset($GLOBALS['TL_CONFIG']['kinderschutzGruppe']);
		$GLOBALS['TL_CONFIG']['kinderschutzGruppen'] = serialize(array('7', '9'));

		$this->assertSame(7, $this->einstellungen()->gruppe());
		$this->assertSame(array(7), $this->einstellungen()->gruppen());
	}

	/**
	 * Der neue Schlüssel geht dem alten vor.
	 */
	public function testNeuerSchluesselGehtVor(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '3';
		$GLOBALS['TL_CONFIG']['kinderschutzGruppen'] = serialize(array('7'));

		$this->assertSame(3, $this->einstellungen()->gruppe());
	}

	/**
	 * Ohne beide Schlüssel ist keine Gruppe gewählt; leer heißt nie „alle“.
	 */
	public function testOhneGruppe(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';
		unset($GLOBALS['TL_CONFIG']['kinderschutzGruppen']);

		$this->assertSame(0, $this->einstellungen()->gruppe());
		$this->assertSame(array(), $this->einstellungen()->gruppen());
	}

	/**
	 * Ohne eigene Vorlage kommen zwei leere Zeichenketten zurück.
	 */
	public function testMailvorlageLeer(): void
	{
		$this->assertSame(array('', ''), $this->einstellungen()->mailvorlage('antrag'));
	}

	/**
	 * Betreff wird entkodiert, der Text zusätzlich entpackt.
	 */
	public function testMailvorlageGespeichert(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzMailAntragAbgelehntBetreff'] = 'Antrag &amp; Rückfrage';
		$GLOBALS['TL_CONFIG']['kinderschutzMailAntragAbgelehntText'] = Einstellungen::verpacke("Hallo {name},\n\n„Grund“: {grund}");

		$this->assertSame(array('Antrag & Rückfrage', "Hallo {name},\n\n„Grund“: {grund}"), $this->einstellungen()->mailvorlage('antragAbgelehnt'));
	}

	/**
	 * Verpacken übersteht die Zeilenumbrüche, die Config::persist() sonst
	 * zu Leerzeichen zusammenfasst, und das Entpacken stellt sie wieder her –
	 * auch wenn Contao das „<“ im Text als Entität gespeichert hat.
	 */
	public function testVerpackenUndEntpacken(): void
	{
		$gespeichert = Einstellungen::verpacke("Zeile 1\r\n\r\nZeile 2 mit &#60;b&#62; und \"Zitat\"");

		$this->assertStringNotContainsString("\n", $gespeichert);
		$this->assertSame("Zeile 1\n\nZeile 2 mit <b> und \"Zitat\"", Einstellungen::entpacke($gespeichert));
		$this->assertSame('', Einstellungen::verpacke("  \n "));
		$this->assertSame('', Einstellungen::entpacke(null));
		$this->assertSame('alter Text ohne JSON', Einstellungen::entpacke('alter Text ohne JSON'));
	}

	/**
	 * Die echte Kette von der Eingabe bis zum Lesen übersteht Anführungszeichen,
	 * Backslash, „<“, Umlaute und Zeilenumbrüche.
	 *
	 * Contao kodiert Eingaben je nach Feld vollständig (das Anführungszeichen
	 * als „&#34;“, der Backslash als „&#92;“, „<“ als „&#60;“). Der kodierte
	 * Text wird verpackt, von Config::escape() zum PHP-Literal der localconfig
	 * gemacht, von PHP eingelesen und schließlich entpackt. Würde entpacke()
	 * schon vor dem Einlesen des JSON die Entitäten entkodieren, stünde ein
	 * Anführungszeichen mitten in der JSON-Zeichenkette, und der Text käme
	 * kaputt zurück.
	 */
	public function testKetteBisInDieLocalconfig(): void
	{
		$eingabe = "Hallo {name},\r\n\r\n&#34;Zitat&#34; mit &#92; und &#60;b&#62;fett&#60;/b&#62;\r\nÄÖÜ äöü ß „Grund“: {grund}";

		$literal = $this->ueberConfigEscape(Einstellungen::verpacke($eingabe));

		$this->assertSame(
			"Hallo {name},\n\n\"Zitat\" mit \\ und <b>fett</b>\nÄÖÜ äöü ß „Grund“: {grund}",
			Einstellungen::entpacke(eval('return '.$literal.';'))
		);
	}

	/**
	 * Contao 4.13 maskiert den gespeicherten Wert vor dem load_callback, 5.7 nicht.
	 *
	 * DC_File::edit() wendet in 4.13 htmlspecialchars() auf Text- und
	 * Textarea-Felder an, bevor der load_callback läuft; die Anführungszeichen
	 * des verpackten JSON kommen dort als „&quot;“ an. In 5.7 kommt der Rohwert.
	 * Beide Wege müssen denselben Klartext ergeben, und eine Rundreise
	 * (laden, unverändert speichern, laden) darf nichts doppelt verpacken.
	 */
	public function testEntpackenMitUndOhneMaskierungDurchContao(): void
	{
		$eingabe = "Hallo {name},\r\n\r\n&#34;Zitat&#34; mit &#92; und &#60;b&#62;fett&#60;/b&#62; & \"roh\"\r\nÄÖÜ äöü ß „Grund“: {grund}";
		$erwartet = "Hallo {name},\n\n\"Zitat\" mit \\ und <b>fett</b> & \"roh\"\nÄÖÜ äöü ß „Grund“: {grund}";

		$gespeichert = eval('return '.$this->ueberConfigEscape(Einstellungen::verpacke($eingabe)).';');
		$maskiert = htmlspecialchars($gespeichert, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);

		$this->assertStringContainsString('&quot;', $maskiert);
		$this->assertSame($erwartet, Einstellungen::entpacke($gespeichert), 'Contao 5.7: Rohwert');
		$this->assertSame($erwartet, Einstellungen::entpacke($maskiert), 'Contao 4.13: mit htmlspecialchars()');

		// Rundreise: die Maske zeigt den Klartext, Speichern verpackt ihn genau einmal
		$angezeigt = Einstellungen::entpacke($maskiert);
		$neuGespeichert = eval('return '.$this->ueberConfigEscape(Einstellungen::verpacke($angezeigt)).';');
		$this->assertSame($erwartet, $angezeigt);
		$this->assertSame($erwartet, Einstellungen::entpacke(htmlspecialchars($neuGespeichert, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5)));
		$this->assertStringStartsWith('"Hallo', $neuGespeichert);
	}

	/**
	 * Ungültiges UTF-8 ergibt keinen stillschweigend leeren Text.
	 *
	 * json_encode() ohne Ersatzzeichen lieferte false und damit eine leere
	 * Zeichenkette; die Vorlage wäre unbemerkt verloren. Das ungültige Byte
	 * wird durch U+FFFD ersetzt.
	 */
	public function testVerpackenErsetztUngueltigesUtf8(): void
	{
		$gespeichert = Einstellungen::verpacke("Ab\xFFc");

		$this->assertNotSame('', $gespeichert);
		$this->assertSame("Ab\u{FFFD}c", Einstellungen::entpacke($gespeichert));
	}

	/**
	 * Lässt einen Wert durch Contaos Config::escape() laufen.
	 *
	 * escape() ist geschützt und gehört zu einer Singleton-Klasse, deren
	 * Konstruktor den Container verlangt. Beides wird umgangen: Die Instanz
	 * entsteht ohne Konstruktor, der Aufruf geht über Reflection (ab PHP 8.1
	 * ohne setAccessible()). So prüft der Test genau den Weg, den
	 * Config::persist() beim Schreiben der localconfig nimmt.
	 *
	 * @param string $wert Der zu speichernde Wert
	 *
	 * @return string Das PHP-Literal, wie es in der localconfig steht
	 */
	private function ueberConfigEscape(string $wert): string
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		return (string) (new \ReflectionMethod(Config::class, 'escape'))->invoke($config, $wert);
	}
}
