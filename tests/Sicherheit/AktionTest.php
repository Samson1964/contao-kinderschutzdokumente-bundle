<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Sicherheit;

use Contao\CoreBundle\Exception\AccessDeniedException;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Aktion;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Kopiersperre;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Verschiebesperre;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft die gemeinsame Ermittlung von „act“ und „mode“.
 *
 * Contao 4.13 normalisiert „act“ in Input::get() (Entitäten, Steuerzeichen),
 * bevor es $dc->$act() aufruft. Werte, die erst durch diese Normalisierung zu
 * einer gesperrten Aktion würden, müssen schon an der Form scheitern.
 */
class AktionTest extends TestCase
{
	/**
	 * Gültige Werte kommen kleingeschrieben zurück, fehlende als leere Zeichenkette.
	 */
	public function testGueltigeWerteKleingeschrieben(): void
	{
		$this->assertSame('editall', Aktion::act(new Request(array('act' => 'editAll'))));
		$this->assertSame('edit', Aktion::act(new Request(array('act' => 'edit'))));
		$this->assertSame('', Aktion::act(new Request()));
		$this->assertSame('', Aktion::act(new Request(array('act' => ''))));
		$this->assertSame('', Aktion::act(null));
		$this->assertSame('cut', Aktion::modus(new Request(array('mode' => 'Cut'))));
		$this->assertSame('2', Aktion::modus(new Request(array('mode' => '2'))));
	}

	/**
	 * Werte aus anderen Zeichen ergeben 403, auch ein Feld.
	 *
	 * @dataProvider ungueltigeWerte
	 *
	 * @param array<string, mixed> $abfrage Die Adressparameter
	 */
	public function testUngueltigeWerteVerweigert(array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		Aktion::act(new Request($abfrage));
		Aktion::modus(new Request($abfrage));
	}

	/**
	 * Werte, die Contao 4.13 zu einer gültigen Aktion normalisieren würde.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function ungueltigeWerte(): array
	{
		return array(
			'Nullbyte'              => array(array('act' => "cu\0t")),
			'Wagenrücklauf'         => array(array('act' => "cu\rt")),
			'Zeilenumbruch am Ende' => array(array('act' => "cut\n")),
			'Entität cut'           => array(array('act' => 'cu&#116;')),
			'Entität editAll'       => array(array('act' => 'ed&#105;tAll')),
			'Entität copy'          => array(array('act' => 'co&#112;y')),
			'Feld'                  => array(array('act' => array('cut'))),
			'Modus mit Entität'     => array(array('mode' => 'cu&#116;')),
		);
	}

	/**
	 * Verschiebe- und Kopiersperre weisen die Umgehungen mit 403 ab.
	 *
	 * @dataProvider umgehungen
	 *
	 * @param string               $sperre  Klasse der Sperre
	 * @param array<string, mixed> $abfrage Die Adressparameter
	 */
	public function testSperrenWeisenUmgehungenAb(string $sperre, array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		$sperre::pruefe(new Request($abfrage));
	}

	/**
	 * Umgehungsversuche je Sperre.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function umgehungen(): array
	{
		return array(
			'Verschieben Nullbyte'   => array(Verschiebesperre::class, array('act' => "cu\0t")),
			'Verschieben Entität'    => array(Verschiebesperre::class, array('act' => 'cu&#116;')),
			'Einfügen Modus Entität' => array(Verschiebesperre::class, array('act' => 'paste', 'mode' => 'cu&#116;')),
			'Kopieren Entität'       => array(Kopiersperre::class, array('act' => 'co&#112;y')),
		);
	}

	/**
	 * Gegenprobe: Erlaubte Aktionen gehen durch beide Sperren.
	 *
	 * @dataProvider erlaubteAktionen
	 *
	 * @param array<string, string> $abfrage Die Adressparameter
	 */
	public function testErlaubteAktionenGehenDurch(array $abfrage): void
	{
		Verschiebesperre::pruefe(new Request($abfrage));
		Kopiersperre::pruefe(new Request($abfrage));

		$this->addToAssertionCount(1);
	}

	/**
	 * Aktionen, die keine der beiden Sperren betreffen.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function erlaubteAktionen(): array
	{
		return array(
			'Liste'                  => array(array()),
			'Bearbeiten'             => array(array('act' => 'edit', 'id' => '1')),
			'Einfügen nach Kopieren' => array(array('act' => 'paste', 'mode' => 'copy')),
			'Neu mit Modus 2'        => array(array('act' => 'create', 'mode' => '2', 'pid' => '0')),
		);
	}
}
