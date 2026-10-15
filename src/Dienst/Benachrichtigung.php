<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeExceptionInterface;

/**
 * Verschickt die fünf Mails des Bundles.
 *
 * Die Texte stehen in languages/de/default.php unter
 * $GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'] und lassen sich im Projekt
 * überschreiben. Platzhalter in geschweiften Klammern ({name}, {dokumentart}
 * …) werden ersetzt. Geladen wird immer Deutsch, auch wenn der Cronjob von
 * der Konsole mit anderer Sprache läuft.
 *
 * Grundsatz: Mails nennen Person, Dokumentart, Datum und Status, nie ein
 * Prüfergebnis wie „einschlägige Einträge“.
 *
 * Ein gescheiterter Versand bricht keinen Ablauf ab, auch nicht bei einer
 * RFC-widrigen Mailadresse. Er wird protokolliert und mit false gemeldet,
 * damit der Cronjob den Versandmerker nicht setzt und es am nächsten Tag
 * erneut versucht. Als gescheitert gilt auch eine Mail, die ein Listener
 * vor dem Versand verworfen hat. Das Systemprotokoll bekommt dabei nie eine
 * Mailadresse und keinen Fehlertext des Mailservers zu sehen, nur Mailart,
 * Zahl der Empfänger und Fehlerklasse bzw. „vor dem Versand verworfen“.
 *
 * Versandweg: Die Mails gehen synchron über den Standardtransport des
 * Mailers (TransportInterface, im FrameworkBundle der Dienst
 * mailer.default_transport), nicht über MailerInterface. Contao 5.7 leitet
 * MailerInterface::send() über den Messenger in eine Warteschlange; send()
 * kehrt sofort zurück, und ein Fehler entsteht erst im Worker, wenn die
 * Anfrage längst beantwortet ist. Dann erführe der Prüfer nie, dass die
 * Ablehnungsmail nicht hinausging, der Cronjob setzte seinen Versandmerker
 * trotzdem, und den einzigen Protokolleintrag schriebe Contao – mit der
 * Empfängeradresse. Über den Transport wirft send() den Fehler sofort, und
 * die Rückmeldung an den Prüfer sowie der eigene Eintrag ohne Adresse
 * greifen in 4.13 wie in 5.7.
 *
 * Der Preis: Contaos ContaoMailer wird umgangen. Die Transportwahl je
 * Startpunkt (Feld „mailerTransport“, Kopfzeile X-Transport) samt der dort
 * hinterlegten Absenderadresse und in 5.7 die Einstellung
 * contao.mailer.override_from gelten für diese Mails nicht; sie gehen immer
 * über den ersten Transport aus framework.mailer. Die Ereignisse des
 * Transports laufen dagegen weiter (Envelope- und Kopfzeilen-Vorgaben aus
 * framework.mailer). Deshalb schreibt Contao 5.7 über seinen
 * LogEmailMessageListener zusätzlich „Failed to send e-mail to …“ bzw. „An
 * e-mail has been sent to …“ mit Adresse ins Protokoll; das ist Verhalten
 * des Kerns für jede Mail der Installation und lässt sich vom Bundle aus
 * nicht abstellen.
 */
class Benachrichtigung
{
	/**
	 * @param TransportInterface $mailer        Der Standardtransport des Mailers
	 *                                          (mailer.default_transport); verschickt
	 *                                          synchron und wirft bei Fehlern sofort
	 * @param Einstellungen      $einstellungen Liefert den Absender
	 * @param Verweise           $verweise      Baut die Links
	 * @param ContaoFramework    $framework     Lädt die Sprachdatei
	 * @param LoggerInterface    $protokoll     Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly TransportInterface $mailer,
		private readonly Einstellungen $einstellungen,
		private readonly Verweise $verweise,
		private readonly ContaoFramework $framework,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Meldet den Prüfern eine neue Einreichung.
	 *
	 * @param array{firstname: string, lastname: string, email: string} $mitglied    Wer hochgeladen hat
	 * @param string                                                     $dokumentart Name der Art
	 * @param list<string>                                               $empfaenger  Adressen der Prüfer
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	public function neueEinreichung(array $mitglied, string $dokumentart, array $empfaenger): bool
	{
		return $this->sende($empfaenger, 'neu', array(
			'{mitglied}'    => PersonenRepository::name($mitglied),
			'{dokumentart}' => $dokumentart,
			'{link}'        => $this->verweise->uebersicht(array('filter' => 'wartend')),
		));
	}

	/**
	 * Teilt einer Person die Ablehnung ihrer Einreichung mit.
	 *
	 * @param array{firstname: string, lastname: string, email: string} $mitglied    Die Person
	 * @param string                                                     $dokumentart Name der Art
	 * @param string                                                     $grund       Begründung des Prüfers
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	public function abgelehnt(array $mitglied, string $dokumentart, string $grund): bool
	{
		return $this->sende(array($mitglied['email']), 'abgelehnt', array(
			'{name}'        => trim($mitglied['firstname'].' '.$mitglied['lastname']),
			'{dokumentart}' => $dokumentart,
			'{grund}'       => $grund,
			'{link}'        => $this->verweise->unterlagenseite(),
		));
	}

	/**
	 * Erinnert eine Person an einen bald ablaufenden Nachweis.
	 *
	 * @param array{firstname: string, lastname: string, email: string} $mitglied    Die Person
	 * @param string                                                     $dokumentart Name der Art
	 * @param int                                                        $gueltigBis  Letzter gültiger Tag
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	public function vorAblauf(array $mitglied, string $dokumentart, int $gueltigBis): bool
	{
		return $this->ablaufmail('vorAblauf', $mitglied, $dokumentart, $gueltigBis);
	}

	/**
	 * Teilt einer Person mit, dass ein Nachweis abgelaufen ist.
	 *
	 * @param array{firstname: string, lastname: string, email: string} $mitglied    Die Person
	 * @param string                                                     $dokumentart Name der Art
	 * @param int                                                        $gueltigBis  Letzter gültiger Tag
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	public function abgelaufen(array $mitglied, string $dokumentart, int $gueltigBis): bool
	{
		return $this->ablaufmail('ablauf', $mitglied, $dokumentart, $gueltigBis);
	}

	/**
	 * Teilt einer Person mit, welche Unterlagen für eine Veranstaltung fehlen.
	 *
	 * @param array{firstname: string, lastname: string, email: string} $mitglied      Die Person
	 * @param array<string, mixed>                                       $veranstaltung Zeile mit name,
	 *                                                                                  startDate, endDate
	 * @param list<string>                                               $fehlend       Namen der fehlenden Arten
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	public function veranstaltung(array $mitglied, array $veranstaltung, array $fehlend): bool
	{
		$beginn = (int) $veranstaltung['startDate'];
		$ende = (int) ($veranstaltung['endDate'] ?? 0);
		$zeitraum = $ende > 0 && $ende !== $beginn ? $this->datum($beginn).' – '.$this->datum($ende) : $this->datum($beginn);

		return $this->sende(array($mitglied['email']), 'veranstaltung', array(
			'{name}'          => trim($mitglied['firstname'].' '.$mitglied['lastname']),
			'{veranstaltung}' => (string) $veranstaltung['name'],
			'{zeitraum}'      => $zeitraum,
			'{liste}'         => '- '.implode("\n- ", $fehlend),
			'{link}'          => $this->verweise->unterlagenseite(),
		));
	}

	/**
	 * Gemeinsamer Teil der beiden Ablaufmails.
	 *
	 * @param string                                                     $vorlage     „vorAblauf“ oder „ablauf“
	 * @param array{firstname: string, lastname: string, email: string} $mitglied    Die Person
	 * @param string                                                     $dokumentart Name der Art
	 * @param int                                                        $gueltigBis  Letzter gültiger Tag
	 *
	 * @return bool true, wenn die Mail verschickt wurde
	 */
	private function ablaufmail(string $vorlage, array $mitglied, string $dokumentart, int $gueltigBis): bool
	{
		return $this->sende(array($mitglied['email']), $vorlage, array(
			'{name}'        => trim($mitglied['firstname'].' '.$mitglied['lastname']),
			'{dokumentart}' => $dokumentart,
			'{datum}'       => $this->datum($gueltigBis),
			'{link}'        => $this->verweise->unterlagenseite(),
		));
	}

	/**
	 * Setzt eine Mail aus der Sprachdatei zusammen und verschickt sie.
	 *
	 * Wirft nie wegen des Versands. Scheitert der Aufbau der Mail (eine
	 * RFC-widrige Empfänger- oder Absenderadresse wie „anna@@example.org“
	 * lässt schon from() oder to() eine Ausnahme des Mime-Bausteins werfen)
	 * oder der Transport, bleibt es bei einem Protokolleintrag und false.
	 * Deshalb steht der Aufbau der Mail im try und nicht davor: Sonst bräche
	 * die Ausnahme bis in Upload und Cronjob durch.
	 *
	 * Der Transport bekommt keinen Envelope mit. AbstractTransport::send()
	 * baut ihn wie der Mailer selbst mit Envelope::create() aus der Mail:
	 * Absender aus From, Empfänger aus To. Abgefangen werden alle Ausnahmen
	 * des Mailer-Bausteins, nicht nur Transportfehler, weil der Envelope erst
	 * im Transport entsteht und eigene wirft – etwa bei einem Umlaut vor dem
	 * @ der Absenderadresse, den Email noch annimmt.
	 *
	 * Liefert der Transport statt einer SentMessage null, hat ein Listener
	 * die Mail vor dem Versand verworfen (TransportInterface erlaubt das; in
	 * Symfony 7.4 nach MessageEvent::reject(), 5.4 kennt das nicht). Das
	 * zählt wie jeder andere Fehlschlag: Protokolleintrag ohne Adresse und
	 * false, damit der Prüfer bzw. der Cronjob es erfährt.
	 *
	 * Seiteneffekte: initialisiert das Contao-Framework, lädt die deutsche
	 * Sprachdatei in $GLOBALS['TL_LANG'], verbindet sich synchron mit dem
	 * Mailserver (die Anfrage wartet also auf ihn) und schreibt bei
	 * Misserfolg einen Eintrag ins Systemprotokoll. Der Eintrag nennt nur die
	 * Mailart, die Zahl der Empfänger und die Klasse der Ausnahme, weder
	 * Adressen noch den Fehlertext des Mailservers: Das Systemprotokoll steht
	 * allen Administratoren offen, und der Text des Servers enthält oft die
	 * Adresse.
	 *
	 * @param list<string>          $empfaenger Adressen; leere werden übergangen
	 * @param string                $vorlage    Schlüssel unter kinderschutz_mail
	 * @param array<string, string> $werte      Platzhalter und ihre Werte
	 *
	 * @return bool true, wenn der Transport die Mail angenommen und
	 *              verschickt hat; false, wenn Absender oder Empfänger
	 *              fehlen, Aufbau oder Versand scheiterten oder die Mail vor
	 *              dem Versand verworfen wurde
	 */
	private function sende(array $empfaenger, string $vorlage, array $werte): bool
	{
		$empfaenger = array_values(array_filter(array_map('trim', $empfaenger)));
		$absender = $this->einstellungen->absender();

		if (array() === $empfaenger || '' === $absender) {
			$this->protokoll->error(sprintf('Kinderschutz: Mail „%s“ nicht verschickt, es fehlt %s', $vorlage, '' === $absender ? 'die Absenderadresse' : 'eine Empfängeradresse'));

			return false;
		}

		$this->framework->initialize();
		$this->framework->getAdapter(System::class)->loadLanguageFile('default', 'de');
		$text = $GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'][$vorlage] ?? array($vorlage, '');

		try {
			$mail = (new Email())
				->from($absender)
				->to(...$empfaenger)
				->subject(strtr((string) $text[0], $werte))
				->text(strtr((string) $text[1], $werte));

			$gesendet = $this->mailer->send($mail);
		} catch (MailerExceptionInterface|MimeExceptionInterface $fehler) {
			$this->protokoll->error(sprintf('Kinderschutz: Mail „%s“ an %d Empfänger nicht verschickt (%s)', $vorlage, count($empfaenger), $fehler::class));

			return false;
		}

		// null heißt: Ein Listener auf MessageEvent hat die Mail verworfen
		// (Symfony 7.4 AbstractTransport::send() nach $event->reject()).
		// Hinausgegangen ist dann nichts, auch wenn nichts geworfen wurde.
		if (null === $gesendet) {
			$this->protokoll->error(sprintf('Kinderschutz: Mail „%s“ an %d Empfänger nicht verschickt (vor dem Versand verworfen)', $vorlage, count($empfaenger)));

			return false;
		}

		return true;
	}

	/**
	 * Formatiert ein Datum für die Mail.
	 *
	 * @param int $zeitstempel Zeitstempel eines Tages
	 *
	 * @return string Etwa „14.03.2028“
	 */
	private function datum(int $zeitstempel): string
	{
		return date('d.m.Y', $zeitstempel);
	}
}
