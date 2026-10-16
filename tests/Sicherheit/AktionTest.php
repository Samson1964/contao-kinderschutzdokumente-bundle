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
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Aktion;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Kopiersperre;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\Verschiebesperre;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EingabeAttrappe;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft die gemeinsame Ermittlung von „act“ und „mode“.
 *
 * Maßgeblich ist, was Contao über Input::get() liest; Contao 4.13
 * normalisiert dabei Werte und Schlüssel („ac&#116;=cu&#116;“ wird zu
 * „act=cut“). Die Attrappe liefert deshalb den Wert, den Contao sieht.
 */
class AktionTest extends TestCase
{
	use EingabeAttrappe;

	/**
	 * Räumt den Container weg, den einzelne Tests setzen.
	 */
	protected function tearDown(): void
	{
		System::setContainer(new Container());
	}

	/**
	 * Gültige Werte kommen kleingeschrieben zurück, fehlende als leere Zeichenkette.
	 */
	public function testGueltigeWerteKleingeschrieben(): void
	{
		$this->assertSame('editall', Aktion::act($this->eingabe(array('act' => 'editAll'))));
		$this->assertSame('edit', Aktion::act($this->eingabe(array('act' => 'edit'))));
		$this->assertSame('', Aktion::act($this->eingabe(array())));
		$this->assertSame('', Aktion::act($this->eingabe(array('act' => ''))));
		$this->assertSame('cut', Aktion::modus($this->eingabe(array('mode' => 'Cut'))));
		$this->assertSame('2', Aktion::modus($this->eingabe(array('mode' => '2'))));
	}

	/**
	 * Der Wert kommt aus dem Input-Adapter, nicht aus dem Symfony-Request.
	 *
	 * Steht „act=cut“ nur im Request, aber nicht in dem, was Contao liest,
	 * zählt es nicht; liest Contao „cut“ (in 4.13 etwa aus „ac%0Dt=cut“),
	 * zählt es, auch ohne passenden Request-Parameter.
	 */
	public function testWertKommtAusDemAdapter(): void
	{
		$stapel = new RequestStack();
		$stapel->push(new Request(array('act' => 'cut')));
		$container = new Container();
		$container->set('request_stack', $stapel);
		System::setContainer($container);

		$this->assertSame('', Aktion::act($this->eingabe(array())));
		$this->assertSame('cut', Aktion::act($this->eingabe(array('act' => 'cut'))));

		$this->expectException(AccessDeniedException::class);
		Verschiebesperre::pruefe($this->eingabe(array('act' => 'cut')));
	}

	/**
	 * Mit der echten Input-Klasse (hier Contao 5.7) liest der Helfer die Anfrage.
	 *
	 * 5.7 normalisiert nicht, sondern kodiert: „cu&#116;“ kommt als
	 * „cu&amp;#116;“ an und scheitert an der Zeichenprüfung (403).
	 */
	public function testEchterInputAdapter(): void
	{
		$framework = $this->createMock(ContaoFramework::class);
		$framework->method('getAdapter')->willReturnCallback(static fn (string $klasse) => new Adapter($klasse));

		$this->setzeAnfrage(array('act' => 'editAll', 'mode' => '2'));
		$this->assertSame('editall', Aktion::act($framework));
		$this->assertSame('2', Aktion::modus($framework));

		$this->setzeAnfrage(array('act' => 'cu&#116;'));
		$this->expectException(AccessDeniedException::class);
		Aktion::act($framework);
	}

	/**
	 * Werte außerhalb der erlaubten Zeichen ergeben 403, auch ein Feld.
	 *
	 * @dataProvider ungueltigeWerte
	 *
	 * @param array<string, mixed> $abfrage Was Contao liest
	 */
	public function testUngueltigeWerteVerweigert(array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		Aktion::act($this->eingabe($abfrage));
		Aktion::modus($this->eingabe($abfrage));
	}

	/**
	 * Werte, die keine Methode von DC_Table treffen können.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function ungueltigeWerte(): array
	{
		return array(
			'Nullbyte'                => array(array('act' => "cu\0t")),
			'Wagenrücklauf'           => array(array('act' => "cu\rt")),
			'Zeilenumbruch am Ende'   => array(array('act' => "cut\n")),
			'Entität kodiert (5.7)'   => array(array('act' => 'cu&amp;#116;')),
			'Entität editAll'         => array(array('act' => 'ed&#105;tAll')),
			'Entität copy'            => array(array('act' => 'co&#112;y')),
			'Feld'                    => array(array('act' => array('cut'))),
			'Modus mit Steuerzeichen' => array(array('mode' => "cu\rt")),
		);
	}

	/**
	 * Was Contao 4.13 aus verschleierten Adressen liest, sperren beide Sperren.
	 *
	 * @dataProvider normalisierteUmgehungen
	 *
	 * @param string               $sperre  Klasse der Sperre
	 * @param array<string, mixed> $abfrage Was Contao nach der Normalisierung liest
	 */
	public function testSperrenSehenDenNormalisiertenWert(string $sperre, array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		$sperre::pruefe($this->eingabe($abfrage));
	}

	/**
	 * Verschleierte Adressen und das, was Contao 4.13 daraus liest.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function normalisierteUmgehungen(): array
	{
		return array(
			'ac&#116;=cut'             => array(Verschiebesperre::class, array('act' => 'cut')),
			'ac%0Dt=cut'               => array(Verschiebesperre::class, array('act' => 'cut')),
			'act=list&ac&#116;=cutAll' => array(Verschiebesperre::class, array('act' => 'cutAll')),
			'act=paste&mo%0Dde=cut'    => array(Verschiebesperre::class, array('act' => 'paste', 'mode' => 'cut')),
			'act=co&#112;y'            => array(Kopiersperre::class, array('act' => 'copy')),
			'unnormalisiert (5.7)'     => array(Kopiersperre::class, array('act' => 'co&amp;#112;y')),
		);
	}

	/**
	 * Gegenprobe: Erlaubte Aktionen gehen durch beide Sperren.
	 *
	 * @dataProvider erlaubteAktionen
	 *
	 * @param array<string, string> $abfrage Was Contao liest
	 */
	public function testErlaubteAktionenGehenDurch(array $abfrage): void
	{
		Verschiebesperre::pruefe($this->eingabe($abfrage));
		Kopiersperre::pruefe($this->eingabe($abfrage));

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

	/**
	 * Legt eine Anfrage in einen frischen Container, wie Input in 5.7 sie liest.
	 *
	 * @param array<string, string> $abfrage Die Adressparameter
	 */
	private function setzeAnfrage(array $abfrage): void
	{
		$stapel = new RequestStack();
		$stapel->push(new Request($abfrage));
		$container = new Container();
		$container->set('request_stack', $stapel);
		System::setContainer($container);
	}
}
