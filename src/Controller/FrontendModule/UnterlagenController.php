<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\Template;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einreichung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Statusdienst;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\UploadPruefer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Frontend-Modul „Kinderschutz: Meine Unterlagen“.
 *
 * Zeigt dem angemeldeten Mitglied je Dokumentart Stand und Upload-Formular
 * sowie seine Veranstaltungen mit An- und Abmeldung. Nach jedem erfolgreichen
 * Absenden leitet es auf dieselbe Seite um (Post/Redirect/Get), die Meldung
 * kommt über die Sitzung. Umgeleitet wird mit RedirectResponseException, weil
 * eine RedirectResponse aus einem Fragment nicht bis zum Browser durchdringt.
 *
 * Den Anfragetoken prüft Contao für alle POST-Anfragen im Frontend selbst.
 */
#[AsFrontendModule(type: 'kinderschutz_unterlagen', category: 'kinderschutz', template: 'mod_kinderschutz_unterlagen')]
class UnterlagenController extends AbstractFrontendModuleController
{
	/** Schlüssel der Meldungen in der Sitzung */
	private const MELDUNG = 'kinderschutz_meldung';

	/**
	 * @param Statusdienst            $status          Stand je Dokumentart und Veranstaltung
	 * @param Einreichung             $einreichung     Nimmt Uploads entgegen
	 * @param VeranstaltungRepository $veranstaltungen An- und Abmeldung
	 * @param PersonenRepository      $personen        Gruppenzugehörigkeit
	 * @param Einstellungen           $einstellungen   Kinderschutzpflichtige Gruppen
	 * @param UploadPruefer           $pruefer         Nennt die Höchstgröße
	 * @param TokenStorageInterface   $tokenStorage    Liefert das angemeldete Mitglied
	 * @param RequestStack            $requestStack    Liefert die Seitenadresse
	 * @param ContaoCsrfTokenManager  $tokenManager    Anfragetoken für die Formulare
	 */
	public function __construct(
		private readonly Statusdienst $status,
		private readonly Einreichung $einreichung,
		private readonly VeranstaltungRepository $veranstaltungen,
		private readonly PersonenRepository $personen,
		private readonly Einstellungen $einstellungen,
		private readonly UploadPruefer $pruefer,
		private readonly TokenStorageInterface $tokenStorage,
		private readonly RequestStack $requestStack,
		private readonly ContaoCsrfTokenManager $tokenManager,
	) {
	}

	/**
	 * Stellt das Modul dar und verarbeitet die Formulare.
	 *
	 * Der Parameter ist mit Contao\Template typisiert: 5.7 übergibt ein
	 * FragmentTemplate, das davon erbt, 4.13 ein FrontendTemplate. PHP erlaubt
	 * beim Überschreiben den breiteren Typ (wie im Mailinglisten-Bundle).
	 *
	 * @param Template    $template Das Modul-Template
	 * @param ModuleModel $model    Die Moduleinstellungen
	 * @param Request     $request  Die Anfrage
	 *
	 * @return Response Die gerenderte Ausgabe
	 *
	 * @throws RedirectResponseException Nach erfolgreichem Absenden
	 */
	protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'];
		$benutzer = $this->tokenStorage->getToken()?->getUser();
		$jetzt = time();
		$template->texte = $texte;
		$template->hinweis = '';

		if (!$benutzer instanceof FrontendUser) {
			$template->hinweis = $texte['nichtAngemeldet'];

			return $template->getResponse();
		}

		$mitglied = (int) $benutzer->id;

		if (!$this->personen->istInGruppen($mitglied, $this->einstellungen->gruppen(), $jetzt)) {
			$template->hinweis = $texte['keinZugang'];

			return $template->getResponse();
		}

		$GLOBALS['TL_CSS'][] = 'bundles/contaokinderschutzdokumente/frontend.css|static';
		$formular = 'kinderschutz_'.$model->id;
		$fehler = array();

		if ($request->isMethod('POST') && $formular.'_upload' === $request->request->get('FORM_SUBMIT')) {
			$fehler = $this->hochladen($request, $mitglied, $jetzt);
		}

		if ($request->isMethod('POST') && $formular.'_veranstaltung' === $request->request->get('FORM_SUBMIT')) {
			$fehler = $this->veranstaltung($request, $mitglied, $jetzt);
		}

		$heute = Kalender::tag($jetzt);
		$eigene = $this->status->veranstaltungenFuerMitglied($mitglied, $heute);
		$eigeneIds = array_map(static fn (array $eintrag): int => (int) $eintrag['veranstaltung']['id'], $eigene);

		$template->formular = $formular;
		$template->requestToken = $this->tokenManager->getDefaultTokenValue();
		$template->meldungen = $this->meldungen($request);
		$template->fehler = $fehler;
		$template->hoechstgroesse = $this->groesse($this->pruefer->hoechstgroesse());
		$template->heute = date('Y-m-d', $jetzt);
		$template->arten = $this->status->arten();
		$template->zellen = $this->status->fuerMitglied($mitglied, $heute);
		$template->eigene = $eigene;
		$template->waehlbare = array_values(array_filter(
			$this->veranstaltungen->waehlbare($heute),
			static fn (array $veranstaltung): bool => !in_array((int) $veranstaltung['id'], $eigeneIds, true)
		));

		return $template->getResponse();
	}

	/**
	 * Verarbeitet das Upload-Formular einer Dokumentart.
	 *
	 * @param Request $request  Die Anfrage
	 * @param int     $mitglied ID des angemeldeten Mitglieds
	 * @param int     $jetzt    Aktueller Zeitpunkt
	 *
	 * @return array<int, string> Fehlermeldung je Dokumentart-ID
	 *
	 * @throws RedirectResponseException Bei Erfolg
	 */
	private function hochladen(Request $request, int $mitglied, int $jetzt): array
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'];
		$artId = (int) $request->request->get('dokumentart');
		$datei = $request->files->get('datei');
		$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('ausstellungsdatum'));
		$ausstellung = false === $datum ? 0 : $datum->getTimestamp();

		$schluessel = $datei instanceof UploadedFile
			? $this->einreichung->einreichen($mitglied, $artId, $datei, $ausstellung, $jetzt)
			: 'upload';

		if (null !== $schluessel) {
			return array($artId => sprintf($texte['fehler'][$schluessel] ?? $schluessel, $this->groesse($this->pruefer->hoechstgroesse())));
		}

		$name = '';

		foreach ($this->status->arten() as $art) {
			$name = $art->id === $artId ? $art->name : $name;
		}

		$this->weiter($request, sprintf($texte['hochgeladen'], $name));
	}

	/**
	 * Verarbeitet An- und Abmeldung zu einer Veranstaltung.
	 *
	 * @param Request $request  Die Anfrage
	 * @param int     $mitglied ID des angemeldeten Mitglieds
	 * @param int     $jetzt    Aktueller Zeitpunkt
	 *
	 * @return array<int, string> Fehlermeldung unter dem Schlüssel 0
	 *
	 * @throws RedirectResponseException Bei Erfolg
	 */
	private function veranstaltung(Request $request, int $mitglied, int $jetzt): array
	{
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'];
		$id = (int) $request->request->get('veranstaltung');
		$zeile = $this->veranstaltungen->eine($id);
		$name = null === $zeile ? '' : (string) $zeile['name'];

		if ('abmelden' === $request->request->get('aktion')) {
			if ($this->veranstaltungen->abmelden($id, $mitglied)) {
				$this->weiter($request, sprintf($texte['abgemeldet'], $name));
			}

			return array(0 => $texte['fehler']['anmeldung']);
		}

		if ($this->veranstaltungen->anmelden($id, $mitglied, Kalender::tag($jetzt), $jetzt)) {
			$this->weiter($request, sprintf($texte['angemeldet'], $name));
		}

		return array(0 => $texte['fehler']['anmeldung']);
	}

	/**
	 * Merkt eine Meldung in der Sitzung und lädt die Seite neu.
	 *
	 * @param Request $request Die Anfrage (für die Sitzung)
	 * @param string  $meldung Die Meldung
	 *
	 * @throws RedirectResponseException Immer
	 */
	private function weiter(Request $request, string $meldung): never
	{
		$request->getSession()->getFlashBag()->add(self::MELDUNG, $meldung);
		$seite = $this->requestStack->getMainRequest() ?? $request;

		throw new RedirectResponseException($seite->getUri());
	}

	/**
	 * Holt die Meldungen aus der Sitzung.
	 *
	 * @param Request $request Die Anfrage
	 *
	 * @return list<string> Die Meldungen seit der letzten Anzeige
	 */
	private function meldungen(Request $request): array
	{
		return $request->hasSession() ? array_values($request->getSession()->getFlashBag()->get(self::MELDUNG)) : array();
	}

	/**
	 * Formatiert eine Größe für Meldungen.
	 *
	 * @param int $bytes Größe in Bytes
	 *
	 * @return string Etwa „10 MB“
	 */
	private function groesse(int $bytes): string
	{
		return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
	}
}
