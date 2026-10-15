<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Verweise;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Gemeinsame Bausteine der Diensttests.
 *
 * Statt Contao hochzufahren, bekommt jeder Dienst ein ContaoFramework-Mock:
 * Config::get liest aus $GLOBALS['TL_CONFIG'], loadLanguageFile lädt die
 * deutsche Sprachdatei des Bundles. Verschickte Mails landen in
 * $this->mails.
 */
abstract class Testhilfen extends TestCase
{
	/** @var list<Email> */
	protected array $mails = array();

	/**
	 * Setzt Konfiguration und Sprache zurück.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_CONFIG'] = array(
			'kinderschutzAbsender' => 'kinderschutz@example.org',
			'kinderschutzGruppen'  => serialize(array('1')),
		);
		$GLOBALS['TL_LANG'] = array();
		$this->mails = array();
	}

	/**
	 * Räumt die globalen Variablen weg.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_CONFIG'], $GLOBALS['TL_LANG']);
	}

	/**
	 * Ein Framework-Mock mit echtem Config-Adapter und Sprachlader.
	 *
	 * Der Sprachlader nimmt nur den Aufruf loadLanguageFile('default', 'de')
	 * an. Jeder andere Aufruf (falsche Datei, falsche Sprache) verletzt die
	 * Erwartung und lässt den Test scheitern, statt unbemerkt die deutsche
	 * Datei zu laden. Die Datei wird bei jedem Aufruf neu eingelesen und
	 * füllt $GLOBALS['TL_LANG'] wie Contao selbst.
	 *
	 * @return ContaoFramework Das Mock
	 */
	protected function framework(): ContaoFramework
	{
		$system = $this->getMockBuilder(Adapter::class)
			->setConstructorArgs(array(System::class))
			->addMethods(array('loadLanguageFile'))
			->getMock();
		$system->method('loadLanguageFile')->with('default', 'de')->willReturnCallback(static function (): void {
			include __DIR__.'/../../src/Resources/contao/languages/de/default.php';
		});

		$framework = $this->createMock(ContaoFramework::class);
		$framework->method('getAdapter')->willReturnCallback(static fn (string $klasse) => System::class === $klasse ? $system : new Adapter($klasse));

		return $framework;
	}

	/**
	 * Echte Einstellungen über dem Framework-Mock.
	 *
	 * @return Einstellungen Die Einstellungen
	 */
	protected function einstellungen(): Einstellungen
	{
		return new Einstellungen($this->framework());
	}

	/**
	 * Verweise mit festen Adressen.
	 *
	 * @return Verweise Das Mock
	 */
	protected function verweise(): Verweise
	{
		$verweise = $this->createMock(Verweise::class);
		$verweise->method('unterlagenseite')->willReturn('https://example.org/unterlagen.html');
		$verweise->method('uebersicht')->willReturn('https://example.org/contao?do=kinderschutz');

		return $verweise;
	}

	/**
	 * Ein Mailtransport, der Mails in $this->mails sammelt.
	 *
	 * Die Benachrichtigung verschickt synchron über TransportInterface. Das
	 * Mock nimmt jede Mail an und gibt wie ein echter Transport eine
	 * SentMessage zurück. null hieße nach dem Vertrag von
	 * TransportInterface::send(), dass ein Listener die Mail verworfen hat,
	 * und gälte der Benachrichtigung als Misserfolg.
	 *
	 * @return TransportInterface Der Transport
	 */
	protected function transport(): TransportInterface
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('send')->willReturnCallback(function (RawMessage $mail): ?SentMessage {
			$this->mails[] = $mail;

			return new SentMessage($mail, Envelope::create($mail));
		});

		return $transport;
	}
}
