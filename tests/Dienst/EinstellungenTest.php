<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

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
}
