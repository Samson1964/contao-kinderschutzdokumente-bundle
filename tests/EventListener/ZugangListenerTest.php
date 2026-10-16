<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\CoreBundle\Exception\AccessDeniedException;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\ZugangListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft die Sperren für Dokumentarten, Veranstaltungen und Teilnahmen.
 */
class ZugangListenerTest extends TestCase
{
	/**
	 * Ohne Freigabe bricht schon das Laden ab.
	 */
	public function testOhneFreigabeZugriffVerweigert(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('verlange')->willThrowException(new AccessDeniedException('nein'));

		$this->expectException(AccessDeniedException::class);
		$this->listener(array(), $zugang)();
	}

	/**
	 * Verschieben ist auch für Berechtigte gesperrt.
	 *
	 * „notSortable“ allein ergäbe in Contao 4.13 HTTP 500 statt 403.
	 *
	 * @dataProvider verschiebeaktionen
	 *
	 * @param array<string, string> $abfrage act und ggf. mode
	 */
	public function testVerschiebenGesperrt(array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener($abfrage)();
	}

	/**
	 * Die gesperrten Verschiebeaktionen.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function verschiebeaktionen(): array
	{
		return array(
			'Ausschneiden'                 => array(array('act' => 'cut', 'id' => '1', 'pid' => '0', 'mode' => '2')),
			'Mehrere ausschneiden'         => array(array('act' => 'cutAll')),
			'Einfügen nach Ausschneiden' => array(array('act' => 'paste', 'mode' => 'cut')),
		);
	}

	/**
	 * Liste, Bearbeiten und Einfügen einer Kopie bleiben erlaubt.
	 *
	 * @dataProvider erlaubteAktionen
	 *
	 * @param array<string, string> $abfrage act und ggf. mode
	 */
	public function testUebrigeAktionenErlaubt(array $abfrage): void
	{
		$this->listener($abfrage)();

		$this->addToAssertionCount(1);
	}

	/**
	 * Aktionen, die die Sperre nicht treffen darf.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function erlaubteAktionen(): array
	{
		return array(
			'Liste'                    => array(array()),
			'Bearbeiten'               => array(array('act' => 'edit', 'id' => '1')),
			'Einfügen nach Kopieren'   => array(array('act' => 'paste', 'mode' => 'copy')),
			'Einfügen ohne Modus'      => array(array('act' => 'paste')),
		);
	}

	/**
	 * Die Sperre bleibt ohne Anfrage (Kommandozeile) still.
	 */
	public function testOhneAnfrageKeineSperre(): void
	{
		(new ZugangListener($this->createMock(Zugang::class), new RequestStack()))();

		$this->addToAssertionCount(1);
	}

	/**
	 * Baut den Listener mit einer Anfrage.
	 *
	 * @param array<string, string> $abfrage Werte der Adresse
	 * @param Zugang|null           $zugang  Eigene Zugangsprüfung; null erlaubt alles
	 *
	 * @return ZugangListener Der Listener
	 */
	private function listener(array $abfrage, ?Zugang $zugang = null): ZugangListener
	{
		$stapel = new RequestStack();
		$stapel->push(new Request(array('do' => 'kinderschutz_veranstaltungen') + $abfrage));

		return new ZugangListener($zugang ?? $this->createMock(Zugang::class), $stapel);
	}
}
