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
use Symfony\Component\Mailer\MailerInterface;
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
	 * @return ContaoFramework Das Mock
	 */
	protected function framework(): ContaoFramework
	{
		$system = $this->getMockBuilder(Adapter::class)
			->setConstructorArgs(array(System::class))
			->addMethods(array('loadLanguageFile'))
			->getMock();
		$system->method('loadLanguageFile')->willReturnCallback(static function (): void {
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
	 * Ein Mailer, der Mails in $this->mails sammelt.
	 *
	 * @return MailerInterface Der Mailer
	 */
	protected function mailer(): MailerInterface
	{
		$mailer = $this->createMock(MailerInterface::class);
		$mailer->method('send')->willReturnCallback(function (RawMessage $mail): void {
			$this->mails[] = $mail;
		});

		return $mailer;
	}
}
