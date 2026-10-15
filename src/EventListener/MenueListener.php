<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Event\MenuEvent;
use Contao\StringUtil;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Zeigt die Menügruppe „Kinderschutz“ genau den Berechtigten.
 *
 * Contao baut eine Menügruppe nur, wenn der Benutzer über seine
 * Benutzergruppen Rechte an mindestens einem ihrer Module hat
 * (BackendUser::navigation(), 4.13 wie 5.7). „disablePermissionChecks“ an
 * den Modulen wirkt erst innerhalb einer schon gebauten Gruppe. Den Zugang
 * regelt hier aber allein die Prüferfreigabe, deshalb wirkt der Listener
 * in beide Richtungen:
 *
 * - gruppeErgaenzen() (Hook getUserNavigation) baut die Gruppe für
 *   Berechtigte, denen Contao sie mangels Modulrechten nicht gebaut hat.
 * - __invoke() (Menü-Ereignis, nach Contaos eigenem Listener mit
 *   Priorität 10) nimmt sie Unberechtigten wieder weg, etwa Administratoren
 *   ohne Freigabe, denen Contao jede Gruppe baut.
 */
#[AsEventListener(priority: -10)]
class MenueListener
{
	/**
	 * Schlüssel der Menügruppe in $GLOBALS['BE_MOD'].
	 */
	private const GRUPPE = 'kinderschutz';

	/**
	 * @param Zugang                $zugang   Die Zugangsprüfung
	 * @param UrlGeneratorInterface $router   Erzeugt die Adressen der Menüpunkte
	 * @param RequestStack          $anfragen Liefert „do“ und (nur in 4.13) die
	 *                                        Referer-ID der aktuellen Anfrage
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly UrlGeneratorInterface $router,
		private readonly RequestStack $anfragen,
	) {
	}

	/**
	 * Entfernt die Gruppe für Unberechtigte aus dem Hauptmenü.
	 *
	 * @param MenuEvent $event Das Menü-Ereignis; auch für das Kopfzeilenmenü,
	 *                         das hier nicht interessiert
	 */
	public function __invoke(MenuEvent $event): void
	{
		$baum = $event->getTree();

		if ('mainMenu' !== $baum->getName() || $this->zugang->darfPruefen()) {
			return;
		}

		$baum->removeChild(self::GRUPPE);
	}

	/**
	 * Ergänzt die Gruppe für Berechtigte, denen Contao sie nicht gebaut hat.
	 *
	 * Der Eintrag wird so aufgebaut wie in BackendUser::navigation(), damit
	 * Contao ihn danach wie jede andere Gruppe behandelt: Es markiert das
	 * aktive Modul, klappt die Gruppe nach dem gespeicherten Zustand zu und
	 * macht daraus die Menüknoten. Module mit „hideInNavigation“ fehlen wie
	 * bei Contao. Die Gruppe landet an der Stelle, die die Reihenfolge in
	 * $GLOBALS['BE_MOD'] vorgibt.
	 *
	 * Contao 4.13 hängt an jede Menüadresse die Referer-ID („ref“) an, 5.7
	 * kennt sie nicht mehr. Ein Parameter mit dem Wert null fällt beim
	 * Erzeugen der Adresse weg, deshalb genügt hier ein Weg für beide.
	 *
	 * @param array<string, array<string, mixed>> $module   Die Gruppen, wie
	 *                                                      Contao sie gebaut hat
	 * @param bool                                $alleZeigen Von Contao
	 *                                                      übergeben, hier ohne
	 *                                                      Bedeutung
	 *
	 * @return array<string, array<string, mixed>> Die Gruppen, für Berechtigte
	 *                                             mit „Kinderschutz“; sonst
	 *                                             unverändert
	 */
	#[AsHook('getUserNavigation')]
	public function gruppeErgaenzen(array $module, bool $alleZeigen = false): array
	{
		$konfiguration = $GLOBALS['BE_MOD'][self::GRUPPE] ?? array();

		if (isset($module[self::GRUPPE]) || array() === $konfiguration || !$this->zugang->darfPruefen()) {
			return $module;
		}

		$anfrage = $this->anfragen->getCurrentRequest();
		$do = $anfrage?->query->get('do');
		$ref = $anfrage?->attributes->get('_contao_referer_id');

		$gruppe = array(
			'class'   => 'group-'.self::GRUPPE.' node-expanded',
			'title'   => StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['collapseNode'] ?? ''),
			'label'   => $this->beschriftung(self::GRUPPE),
			'href'    => $this->router->generate('contao_backend', array('do' => $do, 'mtg' => self::GRUPPE, 'ref' => $ref)),
			'ajaxUrl' => $this->router->generate('contao_backend'),
		);

		foreach ($konfiguration as $name => $modul) {
			if (true === ($modul['hideInNavigation'] ?? false)) {
				continue;
			}

			$gruppe['modules'][$name] = array_merge($modul, array(
				'title'    => StringUtil::specialchars($this->beschreibung($name)),
				'label'    => $this->beschriftung($name),
				'class'    => 'navigation '.$name,
				'href'     => $this->router->generate('contao_backend', array('do' => $name, 'ref' => $ref)),
				'isActive' => false,
			));
		}

		$module[self::GRUPPE] = $gruppe;

		// In die Reihenfolge von $GLOBALS['BE_MOD'] bringen; Gruppen, die
		// andere Hooks ohne Eintrag dort angelegt haben, bleiben hinten.
		$sortiert = array();

		foreach (array_keys($GLOBALS['BE_MOD']) as $name) {
			if (isset($module[$name])) {
				$sortiert[$name] = $module[$name];
				unset($module[$name]);
			}
		}

		return $sortiert + $module;
	}

	/**
	 * Liefert die Beschriftung einer Gruppe oder eines Moduls.
	 *
	 * Wie bei Contao darf der Sprachschlüssel ein Text oder ein Paar aus
	 * Beschriftung und Beschreibung sein; fehlt er, steht der Schlüssel da.
	 *
	 * @param string $name Schlüssel in $GLOBALS['TL_LANG']['MOD']
	 *
	 * @return string Die Beschriftung
	 */
	private function beschriftung(string $name): string
	{
		$text = $GLOBALS['TL_LANG']['MOD'][$name] ?? null;
		$text = \is_array($text) ? ($text[0] ?? null) : $text;

		return \is_string($text) && '' !== $text ? $text : $name;
	}

	/**
	 * Liefert die Beschreibung eines Moduls für den Tooltip.
	 *
	 * @param string $name Schlüssel in $GLOBALS['TL_LANG']['MOD']
	 *
	 * @return string Die Beschreibung, leer wenn keine hinterlegt ist
	 */
	private function beschreibung(string $name): string
	{
		$text = $GLOBALS['TL_LANG']['MOD'][$name] ?? null;

		return \is_array($text) && \is_string($text[1] ?? null) ? $text[1] : '';
	}
}
