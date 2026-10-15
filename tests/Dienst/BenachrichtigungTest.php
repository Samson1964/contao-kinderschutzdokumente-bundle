<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Psr\Log\NullLogger;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Benachrichtigung;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Prüft Einstellungen und Mails: Absender, Empfänger, Platzhalter, Fehlerfälle.
 */
class BenachrichtigungTest extends Testhilfen
{
	private const ANNA = array('id' => 5, 'firstname' => 'Anna', 'lastname' => 'Adler', 'email' => 'anna@example.org');

	/**
	 * Vorgaben greifen, wenn nichts eingestellt ist; Wochen werden zu Tagen.
	 */
	public function testEinstellungenMitVorgaben(): void
	{
		$einstellungen = $this->einstellungen();

		$this->assertSame(array(1), $einstellungen->gruppen());
		$this->assertSame(30, $einstellungen->papierkorbTage());
		$this->assertSame(56, $einstellungen->vorlaufAblaufTage());
		$this->assertSame(42, $einstellungen->vorlaufVeranstaltungTage());
		$this->assertSame(0, $einstellungen->seite());

		$GLOBALS['TL_CONFIG']['kinderschutzPapierkorbTage'] = '7';
		$GLOBALS['TL_CONFIG']['kinderschutzVorlaufAblauf'] = '4';
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = '';
		$GLOBALS['TL_CONFIG']['adminEmail'] = 'admin@example.org';

		$this->assertSame(7, $einstellungen->papierkorbTage());
		$this->assertSame(28, $einstellungen->vorlaufAblaufTage());
		$this->assertSame('admin@example.org', $einstellungen->absender());
	}

	/**
	 * Die Mail an die Prüfer nennt Person und Art und verlinkt die Übersicht.
	 */
	public function testNeueEinreichung(): void
	{
		$this->assertTrue($this->dienst()->neueEinreichung(self::ANNA, 'Ehrenkodex', array('astrid@example.org', 'haupt@example.org')));

		$mail = $this->mails[0];
		$this->assertSame('kinderschutz@example.org', $mail->getFrom()[0]->getAddress());
		$this->assertSame(array('astrid@example.org', 'haupt@example.org'), array_map(static fn ($adresse) => $adresse->getAddress(), $mail->getTo()));
		$this->assertSame('Neue Kinderschutz-Unterlage: Ehrenkodex von Adler, Anna', $mail->getSubject());
		$this->assertStringContainsString('https://example.org/contao?do=kinderschutz', $mail->getTextBody());
	}

	/**
	 * Die Ablehnung geht an die Person, mit Begründung und Link.
	 */
	public function testAbgelehnt(): void
	{
		$this->assertTrue($this->dienst()->abgelehnt(self::ANNA, 'Führungszeugnis', 'Seite 2 fehlt'));

		$mail = $this->mails[0];
		$this->assertSame('anna@example.org', $mail->getTo()[0]->getAddress());
		$this->assertStringContainsString('Guten Tag Anna Adler', $mail->getTextBody());
		$this->assertStringContainsString('Seite 2 fehlt', $mail->getTextBody());
		$this->assertStringContainsString('https://example.org/unterlagen.html', $mail->getTextBody());
	}

	/**
	 * Ablaufmails nennen das Datum.
	 */
	public function testAblaufmails(): void
	{
		$dienst = $this->dienst();
		$dienst->vorAblauf(self::ANNA, 'Schulungsnachweis', strtotime('2026-11-30'));
		$dienst->abgelaufen(self::ANNA, 'Schulungsnachweis', strtotime('2026-10-14'));

		$this->assertSame('Ihre Unterlage „Schulungsnachweis“ läuft bald ab', $this->mails[0]->getSubject());
		$this->assertStringContainsString('gilt noch bis zum 30.11.2026', $this->mails[0]->getTextBody());
		$this->assertSame('Ihre Unterlage „Schulungsnachweis“ ist abgelaufen', $this->mails[1]->getSubject());
		$this->assertStringContainsString('galt bis zum 14.10.2026', $this->mails[1]->getTextBody());
	}

	/**
	 * Die Veranstaltungsmail listet, was fehlt, und nennt den Zeitraum.
	 */
	public function testVeranstaltung(): void
	{
		$this->dienst()->veranstaltung(self::ANNA, array('name' => 'JWM Kreta 11/2026', 'startDate' => strtotime('2026-11-02'), 'endDate' => strtotime('2026-11-12')), array('Führungszeugnis', 'Schulungsnachweis'));

		$text = $this->mails[0]->getTextBody();
		$this->assertSame('Fehlende Unterlagen für „JWM Kreta 11/2026“', $this->mails[0]->getSubject());
		$this->assertStringContainsString('(02.11.2026 – 12.11.2026)', $text);
		$this->assertStringContainsString("- Führungszeugnis\n- Schulungsnachweis", $text);
	}

	/**
	 * Ohne Absender oder Empfänger geht nichts hinaus.
	 */
	public function testOhneAbsenderOderEmpfaenger(): void
	{
		$this->assertFalse($this->dienst()->neueEinreichung(self::ANNA, 'Ehrenkodex', array()));

		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = '';
		$this->assertFalse($this->dienst()->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));
		$this->assertSame(array(), $this->mails);
	}

	/**
	 * Ein Transportfehler wird gemeldet, nicht geworfen.
	 */
	public function testTransportfehler(): void
	{
		$mailer = $this->createMock(MailerInterface::class);
		$mailer->method('send')->willThrowException(new TransportException('SMTP weg'));

		$dienst = new Benachrichtigung($mailer, $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger());

		$this->assertFalse($dienst->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));
	}

	/**
	 * Der Dienst mit sammelndem Mailer.
	 *
	 * @return Benachrichtigung Der Dienst
	 */
	private function dienst(): Benachrichtigung
	{
		return new Benachrichtigung($this->mailer(), $this->einstellungen(), $this->verweise(), $this->framework(), new NullLogger());
	}
}
