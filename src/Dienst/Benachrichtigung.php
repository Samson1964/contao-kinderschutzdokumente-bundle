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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

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
 * Ein gescheiterter Versand bricht keinen Ablauf ab. Er wird protokolliert
 * und mit false gemeldet, damit der Cronjob den Versandmerker nicht setzt
 * und es am nächsten Tag erneut versucht.
 */
class Benachrichtigung
{
	/**
	 * @param MailerInterface $mailer        Der von Contao konfigurierte Mailer
	 * @param Einstellungen   $einstellungen Liefert den Absender
	 * @param Verweise        $verweise      Baut die Links
	 * @param ContaoFramework $framework     Lädt die Sprachdatei
	 * @param LoggerInterface $protokoll     Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly MailerInterface $mailer,
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
	 * @param list<string>          $empfaenger Adressen; leere werden übergangen
	 * @param string                $vorlage    Schlüssel unter kinderschutz_mail
	 * @param array<string, string> $werte      Platzhalter und ihre Werte
	 *
	 * @return bool true, wenn die Mail an mindestens eine Adresse ging
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

		$mail = (new Email())
			->from($absender)
			->to(...$empfaenger)
			->subject(strtr((string) $text[0], $werte))
			->text(strtr((string) $text[1], $werte));

		try {
			$this->mailer->send($mail);
		} catch (TransportExceptionInterface $fehler) {
			$this->protokoll->error(sprintf('Kinderschutz: Mail „%s“ an %s gescheitert: %s', $vorlage, implode(', ', $empfaenger), $fehler->getMessage()));

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
