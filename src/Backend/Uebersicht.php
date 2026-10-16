<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Statusdienst;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Bereitet die Matrix der Übersicht für das Backend-Template auf.
 *
 * Getrennt vom klassischen Backend-Modul (UebersichtModul), weil Contao das
 * Modul mit „new“ erzeugt und ihm keine Dienste übergeben kann. Diese Klasse
 * ist ein öffentlicher Dienst mit allem, was sie braucht.
 */
class Uebersicht
{
	/** Erlaubte Werte des Filters */
	public const FILTER = array('', 'unvollstaendig', 'wartend');

	/** Symbol je Stand, damit die Farbe nicht das einzige Merkmal ist */
	public const SYMBOLE = array(
		Zellstatus::FEHLT       => '–',
		Zellstatus::EINGEREICHT => '✉',
		Zellstatus::ABGELEHNT   => '✖',
		Zellstatus::GUELTIG     => '✔',
		Zellstatus::LAEUFT_AB   => '⏳',
		Zellstatus::ABGELAUFEN  => '✖',
		Zellstatus::BEANSTANDET => '⚠',
	);

	/**
	 * @param Statusdienst            $status          Berechnet die Matrix
	 * @param VeranstaltungRepository $veranstaltungen Für die Auswahl
	 * @param Zugang                  $zugang          Prüft die Berechtigung
	 * @param UrlGeneratorInterface   $router          Baut die Links
	 * @param ContaoCsrfTokenManager  $tokenManager    Anfragetoken für die Links
	 * @param AntragRepository        $antraege        Zählt offene Anträge
	 */
	public function __construct(
		private readonly Statusdienst $status,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly Zugang $zugang,
		private readonly UrlGeneratorInterface $router,
		private readonly ContaoCsrfTokenManager $tokenManager,
		private readonly AntragRepository $antraege,
	) {
	}

	/**
	 * Liefert die Variablen für das Template be_kinderschutz_uebersicht.
	 *
	 * Neben der Matrix gehören dazu die Zahl der offenen Anträge auf
	 * Freischaltung (offeneAntraege) und der Link auf den Menüpunkt „Anträge“
	 * (antraege); das Template zeigt den Hinweis nur bei mindestens einem.
	 *
	 * @param Request $request Die Anfrage mit „veranstaltung“ und „filter“
	 * @param int     $jetzt   Aktueller Zeitpunkt
	 *
	 * @return array<string, mixed> Template-Variablen
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 */
	public function daten(Request $request, int $jetzt): array
	{
		$this->zugang->verlange();

		$gewaehlt = (int) $request->query->get('veranstaltung', 0);
		$filter = (string) $request->query->get('filter', '');
		$filter = in_array($filter, self::FILTER, true) ? $filter : '';

		$matrix = $this->status->matrix($gewaehlt > 0 ? $gewaehlt : null, $jetzt);
		$zeilen = array();

		foreach ($matrix['zeilen'] as $zeile) {
			if (('unvollstaendig' === $filter && $zeile['vollstaendig']) || ('wartend' === $filter && !$zeile['wartet'])) {
				continue;
			}

			$zellen = array();

			foreach ($matrix['arten'] as $art) {
				$zellen[] = $this->zelle($zeile['person']['id'], $art->id, $art->pflicht, $zeile['zellen'][$art->id]);
			}

			$zeilen[] = array(
				'name'         => $zeile['geloescht'] ? sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['mitgliedGeloescht'] ?? '#%d', $zeile['person']['id']) : PersonenRepository::name($zeile['person']),
				'nachweise'    => $this->backend(array('do' => 'kinderschutz_nachweise', 'member' => $zeile['person']['id'])),
				'vollstaendig' => $zeile['vollstaendig'],
				'zellen'       => $zellen,
			);
		}

		return array(
			'aktion'          => $this->router->generate('contao_backend'),
			'veranstaltungen' => $this->veranstaltungen->alle(),
			'gewaehlt'        => null === $matrix['veranstaltung'] ? 0 : (int) $matrix['veranstaltung']['id'],
			'filter'          => $filter,
			'stichtag'        => $matrix['stichtag'],
			'arten'           => $matrix['arten'],
			'zeilen'          => $zeilen,
			'zaehler'         => $matrix['zaehler'],
			'offeneAntraege'  => $this->antraege->offeneAnzahl(),
			'antraege'        => $this->backend(array('do' => 'kinderschutz_antraege')),
		);
	}

	/**
	 * Bereitet eine Zelle der Matrix auf.
	 *
	 * @param int        $mitglied ID des Mitglieds
	 * @param int        $art      ID der Dokumentart
	 * @param bool       $pflicht  Pflichtart (beeinflusst die Farbe von „fehlt“)
	 * @param Zellstatus $zelle    Der berechnete Stand
	 *
	 * @return array{status: string, klasse: string, symbol: string, text: string, datum: int|null, wartet: bool, link: string, titel: string}
	 */
	private function zelle(int $mitglied, int $art, bool $pflicht, Zellstatus $zelle): array
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be'] ?? array();

		if (null !== $zelle->wartend) {
			$link = $this->backend(array('do' => 'kinderschutz_nachweise', 'act' => 'edit', 'id' => $zelle->wartend->id));
			$titel = $texte['pruefen'] ?? '';
		} else {
			$link = $this->backend(array('do' => 'kinderschutz_nachweise', 'act' => 'create', 'member' => $mitglied, 'dokumentart' => $art));
			$titel = $texte['neu'] ?? '';
		}

		return array(
			'status' => $zelle->status,
			'klasse' => 'ks-'.$zelle->status.(Zellstatus::FEHLT === $zelle->status && !$pflicht ? ' ks-freiwillig' : ''),
			'symbol' => self::SYMBOLE[$zelle->status],
			'text'   => $GLOBALS['TL_LANG']['MSC']['kinderschutz_status'][$zelle->status] ?? $zelle->status,
			'datum'  => $zelle->massgeblich?->gueltigBis,
			'wartet' => $zelle->wartet(),
			'link'   => $link,
			'titel'  => $titel,
		);
	}

	/**
	 * Baut einen Link ins Backend samt Anfragetoken.
	 *
	 * @param array<string, int|string> $parameter Parameter wie do, act, id
	 *
	 * @return string Die relative Adresse
	 */
	private function backend(array $parameter): string
	{
		return $this->router->generate('contao_backend', $parameter + array('rt' => $this->tokenManager->getDefaultTokenValue()));
	}
}
