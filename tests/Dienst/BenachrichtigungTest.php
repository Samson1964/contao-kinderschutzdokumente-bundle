<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Benachrichtigung;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerInvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Prüft Einstellungen und Mails: Absender, Empfänger, Platzhalter, Fehlerfälle.
 *
 * Zum Vertrag von Benachrichtigung gehört, dass ein gescheiterter Versand nie
 * wirft, und dass das Systemprotokoll weder Mailadressen noch Fehlertexte des
 * Mailservers enthält. Beides wird hier ausdrücklich geprüft.
 */
class BenachrichtigungTest extends Testhilfen
{
	private const ANNA = array('id' => 5, 'firstname' => 'Anna', 'lastname' => 'Adler', 'email' => 'anna@example.org');

	/** @var list<string> Meldungen, die ein Protokoll-Mock über error() erhalten hat */
	private array $meldungen = array();

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
		$this->assertSame('Ihre Unterlage „Führungszeugnis“ wurde nicht anerkannt', $mail->getSubject());
		$this->assertStringContainsString('Unterlage „Führungszeugnis“ konnte leider nicht anerkannt', $mail->getTextBody());
		$this->assertStringContainsString('Guten Tag Anna Adler', $mail->getTextBody());
		$this->assertStringContainsString('Seite 2 fehlt', $mail->getTextBody());
		$this->assertStringContainsString('https://example.org/unterlagen.html', $mail->getTextBody());
	}

	/**
	 * Ablaufmails nennen Person, Dokumentart, Datum und Link.
	 *
	 * Beide Mails gehen an dieselbe Person und führen dieselben Platzhalter
	 * {name}, {dokumentart} und {link}; {datum} unterscheidet sich im Wortlaut.
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

		$this->assertCount(2, $this->mails);
		foreach ($this->mails as $mail) {
			$this->assertSame('anna@example.org', $mail->getTo()[0]->getAddress());
			$this->assertStringContainsString('Guten Tag Anna Adler', $mail->getTextBody());
			$this->assertStringContainsString('Ihre Unterlage „Schulungsnachweis“', $mail->getTextBody());
			$this->assertStringContainsString('https://example.org/unterlagen.html', $mail->getTextBody());
		}
	}

	/**
	 * Die Veranstaltungsmail listet, was fehlt, und nennt den Zeitraum.
	 */
	public function testVeranstaltung(): void
	{
		$this->dienst()->veranstaltung(self::ANNA, array('name' => 'JWM Kreta 11/2026', 'startDate' => strtotime('2026-11-02'), 'endDate' => strtotime('2026-11-12')), array('Führungszeugnis', 'Schulungsnachweis'));

		$text = $this->mails[0]->getTextBody();
		$this->assertSame('anna@example.org', $this->mails[0]->getTo()[0]->getAddress());
		$this->assertSame('Fehlende Unterlagen für „JWM Kreta 11/2026“', $this->mails[0]->getSubject());
		$this->assertStringContainsString('Guten Tag Anna Adler', $text);
		$this->assertStringContainsString('Veranstaltung „JWM Kreta 11/2026“ (02.11.2026 – 12.11.2026)', $text);
		$this->assertStringContainsString("- Führungszeugnis\n- Schulungsnachweis", $text);
		$this->assertStringContainsString('https://example.org/unterlagen.html', $text);
	}

	/**
	 * Eine eintägige Veranstaltung nennt nur ein Datum, keine Spanne.
	 *
	 * Eintägig heißt: endDate ist gleich startDate, 0 oder gar nicht gesetzt.
	 * Contao lässt das Enddatum bei Terminen leer, wenn sie an einem Tag
	 * stattfinden.
	 *
	 * @dataProvider eintaegigeVeranstaltungen
	 *
	 * @param array<string, mixed> $veranstaltung Zeile mit name, startDate und optional endDate
	 */
	public function testEintaegigeVeranstaltungNenntNurEinDatum(array $veranstaltung): void
	{
		$this->dienst()->veranstaltung(self::ANNA, $veranstaltung, array('Ehrenkodex'));

		$text = $this->mails[0]->getTextBody();
		$this->assertStringContainsString('Veranstaltung „Simultan“ (02.11.2026)', $text);
		$this->assertStringNotContainsString('–', $text);
	}

	/**
	 * Liefert die drei Schreibweisen einer eintägigen Veranstaltung.
	 *
	 * @return array<string, array{array<string, mixed>}> Je Fall eine Veranstaltungszeile
	 */
	public static function eintaegigeVeranstaltungen(): array
	{
		$tag = strtotime('2026-11-02');

		return array(
			'Ende gleich Beginn' => array(array('name' => 'Simultan', 'startDate' => $tag, 'endDate' => $tag)),
			'Ende null'          => array(array('name' => 'Simultan', 'startDate' => $tag, 'endDate' => 0)),
			'Ende fehlt'         => array(array('name' => 'Simultan', 'startDate' => $tag)),
		);
	}

	/**
	 * In keiner der fünf Mails bleibt ein Platzhalter stehen.
	 *
	 * Ein vergessener Eintrag in den Ersetzungswerten ließe „{link}“ oder
	 * „{name}“ wörtlich in der Mail stehen. Geprüft werden Betreff und Text
	 * aller fünf Mailarten.
	 */
	public function testKeinPlatzhalterBleibtStehen(): void
	{
		$dienst = $this->dienst();
		$dienst->neueEinreichung(self::ANNA, 'Ehrenkodex', array('astrid@example.org'));
		$dienst->abgelehnt(self::ANNA, 'Führungszeugnis', 'Seite 2 fehlt');
		$dienst->vorAblauf(self::ANNA, 'Schulungsnachweis', strtotime('2026-11-30'));
		$dienst->abgelaufen(self::ANNA, 'Schulungsnachweis', strtotime('2026-10-14'));
		$dienst->veranstaltung(self::ANNA, array('name' => 'JWM Kreta 11/2026', 'startDate' => strtotime('2026-11-02'), 'endDate' => strtotime('2026-11-12')), array('Führungszeugnis'));

		$this->assertCount(5, $this->mails);
		foreach ($this->mails as $mail) {
			$this->assertDoesNotMatchRegularExpression('/\{[a-z]+\}/i', $mail->getSubject(), 'Platzhalter im Betreff');
			$this->assertDoesNotMatchRegularExpression('/\{[a-z]+\}/i', $mail->getTextBody(), 'Platzhalter im Text von „'.$mail->getSubject().'“');
		}
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
	 * Leere und nur aus Leerzeichen bestehende Empfänger werden übergangen.
	 *
	 * Die Mail geht an die übrigen Adressen; sind alle leer, geht keine Mail
	 * hinaus und genau ein Protokolleintrag nennt die fehlende Adresse.
	 */
	public function testLeereAdressenWerdenUebergangen(): void
	{
		$this->assertTrue($this->dienst()->neueEinreichung(self::ANNA, 'Ehrenkodex', array('', ' astrid@example.org ', '   ')));

		$this->assertCount(1, $this->mails);
		$this->assertSame(array('astrid@example.org'), array_map(static fn ($adresse) => $adresse->getAddress(), $this->mails[0]->getTo()));

		$this->mails = array();
		$this->assertFalse($this->dienst($this->protokoll(1))->neueEinreichung(self::ANNA, 'Ehrenkodex', array('', '   ')));
		$this->assertSame(array(), $this->mails);
		$this->assertSame(array('Kinderschutz: Mail „neu“ nicht verschickt, es fehlt eine Empfängeradresse'), $this->meldungen);
	}

	/**
	 * Ohne Absender protokolliert der Dienst genau einmal und verschickt nichts.
	 *
	 * Der Eintrag nennt weder die Empfängeradresse noch sonst eine Adresse.
	 */
	public function testFehlenderAbsenderWirdProtokolliert(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = '';

		$this->assertFalse($this->dienst($this->protokoll(1))->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));

		$this->assertSame(array(), $this->mails);
		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ nicht verschickt, es fehlt die Absenderadresse'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Ein Transportfehler wird gemeldet, nicht geworfen, und genau einmal protokolliert.
	 *
	 * Der Eintrag nennt Mailart, Empfängerzahl und Fehlerklasse. Weder die
	 * Adresse noch der Fehlertext des Mailservers gehören hinein, weil das
	 * Systemprotokoll allen Administratoren offensteht und der Text des
	 * Servers Adressen enthalten kann.
	 */
	public function testTransportfehler(): void
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('send')->willThrowException(new TransportException('SMTP weg: 550 anna@example.org abgelehnt'));

		$dienst = $this->dienst($this->protokoll(1), $transport);

		$this->assertFalse($dienst->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));

		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ an 1 Empfänger nicht verschickt ('.TransportException::class.')'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
		$this->assertStringNotContainsString('SMTP weg', $this->meldungen[0]);
	}

	/**
	 * Eine RFC-widrige Adresse wirft nicht, sondern ergibt false.
	 *
	 * Der Aufbau der Mail steht im try: Address wirft schon beim Setzen des
	 * Empfängers eine RfcComplianceException. Geschähe das außerhalb, bräche
	 * es bis in Upload und Cronjob durch. Es geht keine Mail hinaus, und das
	 * Protokoll nennt die Adresse nicht.
	 */
	public function testUngueltigeAdresseWirftNicht(): void
	{
		$anna = array_merge(self::ANNA, array('email' => 'anna@@example.org'));

		$this->assertFalse($this->dienst($this->protokoll(1))->abgelehnt($anna, 'Ehrenkodex', 'x'));

		$this->assertSame(array(), $this->mails);
		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ an 1 Empfänger nicht verschickt ('.RfcComplianceException::class.')'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Eine ungültige Adresse unter mehreren hält die ganze Mail zurück.
	 *
	 * Die Zahl im Protokoll zählt alle Empfänger der Mail, nicht nur die
	 * gültigen. Nach dem Fehlschlag meldet der Dienst false, damit der
	 * Cronjob es am nächsten Tag erneut versucht.
	 */
	public function testUngueltigeAdresseUnterMehrerenEmpfaengern(): void
	{
		$this->assertFalse($this->dienst($this->protokoll(1))->neueEinreichung(self::ANNA, 'Ehrenkodex', array('astrid@example.org', 'kaputt@@example.org')));

		$this->assertSame(array(), $this->mails);
		$this->assertSame(array('Kinderschutz: Mail „neu“ an 2 Empfänger nicht verschickt ('.RfcComplianceException::class.')'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Ein ungültiger Absender wirft ebenfalls nicht.
	 *
	 * Auch from() steht im try; die falsch eingestellte Absenderadresse ist
	 * ein Konfigurationsfehler, der keinen Upload abbrechen darf.
	 */
	public function testUngueltigerAbsenderWirftNicht(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = 'kinderschutz@@example.org';

		$this->assertFalse($this->dienst($this->protokoll(1))->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));

		$this->assertSame(array(), $this->mails);
		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ an 1 Empfänger nicht verschickt ('.RfcComplianceException::class.')'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Der Envelope eines echten Transports nennt Absender und Empfänger der Mail.
	 *
	 * Die Benachrichtigung gibt dem Transport keinen Envelope mit;
	 * AbstractTransport::send() baut ihn mit Envelope::create() aus From und
	 * To. Ein Transport auf Basis von AbstractTransport (wie SMTP, Sendmail
	 * und der Null-Transport) bekommt so den eingestellten Absender als
	 * Envelope-Absender und alle Prüfer als Empfänger.
	 */
	public function testEnvelopeNenntAbsenderUndEmpfaenger(): void
	{
		$transport = $this->echterTransport();

		$this->assertTrue($this->dienst(null, $transport)->neueEinreichung(self::ANNA, 'Ehrenkodex', array('astrid@example.org', 'haupt@example.org')));

		$this->assertCount(1, $transport->gesendet);
		$envelope = $transport->gesendet[0]->getEnvelope();
		$this->assertSame('kinderschutz@example.org', $envelope->getSender()->getAddress());
		$this->assertSame(array('astrid@example.org', 'haupt@example.org'), array_map(static fn ($adresse) => $adresse->getAddress(), $envelope->getRecipients()));
	}

	/**
	 * Ein Fehler beim Bau des Envelopes wirft nicht, sondern ergibt false.
	 *
	 * Einen Umlaut vor dem @ der Absenderadresse nimmt Email noch an; erst
	 * der Envelope im Transport lehnt ihn mit einer InvalidArgumentException
	 * des Mailer-Bausteins ab, die keine Transportausnahme ist. Seit dem
	 * synchronen Versand entstünde sie mitten in Upload oder Cronjob.
	 */
	public function testFehlerImEnvelopeWirftNicht(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzAbsender'] = 'kinderschütz@example.org';
		$transport = $this->echterTransport();

		$this->assertFalse($this->dienst($this->protokoll(1), $transport)->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));

		$this->assertSame(array(), $transport->gesendet);
		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ an 1 Empfänger nicht verschickt ('.MailerInvalidArgumentException::class.')'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Eine verworfene Mail gilt nicht als verschickt.
	 *
	 * TransportInterface::send() liefert null, wenn ein Listener die Mail
	 * vor dem Versand verworfen hat; geworfen wird dabei nichts. Bisher
	 * meldete die Benachrichtigung trotzdem Erfolg, der Prüfer erfuhr nichts
	 * und der Cronjob setzte seinen Versandmerker. Jetzt: false und genau
	 * ein Protokolleintrag ohne Adresse.
	 */
	public function testVerworfeneMailGiltNichtAlsVerschickt(): void
	{
		$transport = $this->createMock(TransportInterface::class);
		$transport->expects($this->once())->method('send')->willReturn(null);

		$this->assertFalse($this->dienst($this->protokoll(1), $transport)->abgelehnt(self::ANNA, 'Ehrenkodex', 'x'));

		$this->assertSame(array('Kinderschutz: Mail „abgelehnt“ an 1 Empfänger nicht verschickt (vor dem Versand verworfen)'), $this->meldungen);
		$this->assertProtokollOhneAdresse($this->meldungen[0]);
	}

	/**
	 * Ein echter Transport verwirft die Mail, wenn ein Listener es verlangt.
	 *
	 * Nachgestellt, wie es in Symfony 7.4 geschieht: Ein Listener auf
	 * MessageEvent ruft reject() auf, AbstractTransport::send() liefert null
	 * und ruft doSend() gar nicht erst auf. Symfony 5.4 (Contao 4.13) kennt
	 * reject() nicht; dort entfällt der Test.
	 */
	public function testVerwerfenderListenerImEchtenTransport(): void
	{
		if (!method_exists(MessageEvent::class, 'reject')) {
			$this->markTestSkipped('MessageEvent::reject() gibt es erst ab Symfony 6.2.');
		}

		$verteiler = new EventDispatcher();
		$verteiler->addListener(MessageEvent::class, static function (MessageEvent $event): void {
			$event->reject();
		});
		$transport = $this->echterTransport($verteiler);

		$this->assertFalse($this->dienst($this->protokoll(1), $transport)->neueEinreichung(self::ANNA, 'Ehrenkodex', array('astrid@example.org', 'haupt@example.org')));

		$this->assertSame(array(), $transport->gesendet);
		$this->assertSame(array('Kinderschutz: Mail „neu“ an 2 Empfänger nicht verschickt (vor dem Versand verworfen)'), $this->meldungen);
	}

	/**
	 * Der Dienst mit sammelndem Transport.
	 *
	 * @param LoggerInterface|null    $protokoll Das Protokoll; ohne Angabe ein NullLogger
	 * @param TransportInterface|null $transport Der Mailtransport; ohne Angabe der sammelnde
	 *                                           Transport, dessen Mails in $this->mails landen
	 *
	 * @return Benachrichtigung Der Dienst
	 */
	private function dienst(?LoggerInterface $protokoll = null, ?TransportInterface $transport = null): Benachrichtigung
	{
		return new Benachrichtigung($transport ?? $this->transport(), $this->einstellungen(), $this->verweise(), $this->framework(), $protokoll ?? new NullLogger());
	}

	/**
	 * Ein echter Transport auf Basis von AbstractTransport, der nichts verschickt.
	 *
	 * Anders als das Mock aus den Testhilfen läuft der Versand hier durch
	 * AbstractTransport::send() wie bei SMTP: Der Envelope entsteht dort, und
	 * doSend() liest Absender und Empfänger daraus, wie ein echter Transport
	 * es vor dem Gespräch mit dem Mailserver tut. Angenommene Mails stehen in
	 * der öffentlichen Eigenschaft $gesendet.
	 *
	 * @param EventDispatcherInterface|null $verteiler Ereignisverteiler für MessageEvent;
	 *                                                 ohne Angabe läuft der Versand ohne Ereignisse
	 *
	 * @return AbstractTransport Der Transport mit der Eigenschaft $gesendet (list<SentMessage>)
	 */
	private function echterTransport(?EventDispatcherInterface $verteiler = null): AbstractTransport
	{
		return new class($verteiler) extends AbstractTransport {
			/** @var list<SentMessage> */
			public array $gesendet = array();

			/**
			 * Liest Absender und Empfänger aus dem Envelope und legt die Mail ab.
			 *
			 * Das Lesen löst die Prüfungen des Envelopes aus; scheitern sie,
			 * wirft schon dieser Aufruf, und die Mail wird nicht abgelegt.
			 *
			 * @param SentMessage $message Die Mail samt Envelope
			 */
			protected function doSend(SentMessage $message): void
			{
				$message->getEnvelope()->getSender();
				$message->getEnvelope()->getRecipients();
				$this->gesendet[] = $message;
			}

			/**
			 * Name des Transports, wie ihn Symfony in Protokollen nennt.
			 *
			 * @return string Eine Pseudo-DSN
			 */
			public function __toString(): string
			{
				return 'sammeln://test';
			}
		};
	}

	/**
	 * Ein Protokoll-Mock, das error()-Meldungen in $this->meldungen sammelt.
	 *
	 * Die Erwartung der Anzahl gilt, sobald der Test endet: Wird error() öfter
	 * oder seltener aufgerufen, scheitert der Test.
	 *
	 * @param int $erwartet Wie oft error() genau aufgerufen werden muss
	 *
	 * @return LoggerInterface Das Mock
	 */
	private function protokoll(int $erwartet): LoggerInterface
	{
		$this->meldungen = array();

		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->exactly($erwartet))->method('error')->willReturnCallback(function ($meldung): void {
			$this->meldungen[] = (string) $meldung;
		});

		return $protokoll;
	}

	/**
	 * Prüft, dass ein Protokolleintrag keine Mailadresse enthält.
	 *
	 * Kein @-Zeichen und kein Teil der Adressen aus den Tests (Anna, Domain
	 * example.org), denn das Systemprotokoll steht allen Administratoren offen.
	 *
	 * @param string $meldung Der Protokolleintrag
	 */
	private function assertProtokollOhneAdresse(string $meldung): void
	{
		$this->assertStringNotContainsString('@', $meldung);
		$this->assertStringNotContainsString('example.org', $meldung);
		$this->assertStringNotContainsString('anna', $meldung);
	}
}
