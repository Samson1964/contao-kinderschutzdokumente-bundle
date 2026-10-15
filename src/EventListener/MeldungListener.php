<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener;

use Contao\Message;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Bringt Fehlerhinweise aus dem Speichern der Prüfmaske bis auf die
 * Folgeseite.
 *
 * Contaos DC_Table ruft bei „Speichern und schließen“, „… und neu“,
 * „… und bearbeiten“ und „… und duplizieren“ nach den onsubmit-Callbacks
 * Message::reset() auf und leitet erst danach weiter (5.7 DC_Table.php
 * 2202-2252, 4.13 DC_Table.php 2200-2264). reset() leert jeden
 * Flash-Schlüssel, der mit „contao.“ beginnt (5.7 Message.php 171-189,
 * 4.13 Message.php 170-188). Ein Hinweis, den ein Callback direkt per
 * Message::addError() ablegt, erreicht den Prüfer im üblichen Ablauf also
 * nie.
 *
 * Deshalb merkt der Callback den Text hier nur vor, und erst beiAntwort()
 * übergibt ihn an Contao – auf kernel.response derselben Anfrage. Dann ist
 * DC_Table fertig, das reset() ist vorbei, und die Sitzung wird erst danach
 * gespeichert (Symfonys SessionListener::onKernelResponse mit Priorität
 * -1000). Die Folgeseite gibt den Hinweis mit Contaos eigenen Mitteln aus
 * und verbraucht ihn dabei, er erscheint also genau einmal. kernel.response
 * läuft auch für die Weiterleitung, die DC_Table als
 * RedirectResponseException wirft (HttpKernel::handleThrowable() →
 * filterResponse(), 4.13 wie 5.7).
 *
 * Warum nicht erst in der Folgeanfrage auf kernel.request: Message braucht
 * den statischen Container (System::getContainer()), den erst
 * ContaoFramework::initialize() setzt – bei angemeldeten Benutzern als
 * Nebenwirkung der Firewall (Priorität 8, ContaoUserProvider), sonst erst im
 * Controller. Dazu kämen ein eigener Sitzungsschlüssel, ein Blick in die
 * Sitzung bei jeder Backend-Anfrage und die Abhängigkeit vom Scope, den der
 * RouterListener (Priorität 32) setzt. In der Anfrage, in der DC_Table lief,
 * ist das Framework dagegen zwangsläufig initialisiert.
 *
 * Der Text liegt bis dahin nur im Arbeitsspeicher dieses Dienstes. Über
 * ResetInterface (Tag kernel.reset per Autoconfiguration) leert Symfony ihn
 * in langlebigen Prozessen zwischen zwei Anfragen, damit ein liegen
 * gebliebener Hinweis nicht in eine fremde Anfrage wandert.
 */
class MeldungListener implements ResetInterface
{
	/**
	 * @var list<string> Vorgemerkte Fehlerhinweise dieser Anfrage
	 */
	private array $offen = array();

	/**
	 * Merkt einen Fehlerhinweis für die Folgeseite vor.
	 *
	 * Der Hinweis landet nicht sofort bei Contao, sondern erst am Ende der
	 * Anfrage (beiAntwort()), damit ein Message::reset() von DC_Table ihn
	 * nicht mehr löschen kann.
	 *
	 * @param string $text Der fertige Text; Contao gibt ihn unverändert aus,
	 *                     er muss also schon maskiert sein
	 */
	public function vormerken(string $text): void
	{
		$this->offen[] = $text;
	}

	/**
	 * Übergibt die vorgemerkten Hinweise am Ende der Anfrage an Contao.
	 *
	 * Nur für die Hauptanfrage: Unteranfragen antworten vor ihr, also auch
	 * vor einem reset() in DC_Table. Gleiche Texte werden nur einmal
	 * übergeben, etwa wenn in „Mehrere bearbeiten“ mehrere Ablehnungsmails
	 * scheitern.
	 *
	 * Der Bereich „BE“ wird ausdrücklich genannt: In 4.13 ist der
	 * Standardwert von Message::addError() die Konstante TL_MODE, in 5.7
	 * fragt Message sonst den Scope-Matcher. Die Hinweise stammen aus
	 * Callbacks des Backends, gehören also immer dorthin.
	 *
	 * Seiteneffekte: schreibt in den Flash-Speicher der Sitzung (Schlüssel
	 * contao.BE.error) und leert die Vormerkung.
	 *
	 * @param ResponseEvent $event Das Ereignis kernel.response
	 */
	#[AsEventListener(event: KernelEvents::RESPONSE)]
	public function beiAntwort(ResponseEvent $event): void
	{
		if (!$event->isMainRequest() || array() === $this->offen) {
			return;
		}

		foreach (array_unique($this->offen) as $text) {
			Message::addError($text, 'BE');
		}

		$this->offen = array();
	}

	/**
	 * Verwirft alle Vormerkungen.
	 *
	 * Symfony ruft das über das Tag kernel.reset zwischen zwei Anfragen
	 * desselben Prozesses auf.
	 */
	public function reset(): void
	{
		$this->offen = array();
	}
}
