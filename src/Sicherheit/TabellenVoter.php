<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\AbstractAction;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Sperrt die Kinderschutz-Tabellen in den Rechteprüfungen von Contao 5.
 *
 * Die Module schalten Contaos eigene Modul- und Tabellenrechte ab
 * (disablePermissionChecks, permissions = []). Damit würden Contao-5-Dienste,
 * die über „contao_dc.“-Rechte fragen – allen voran die Backend-Suche –,
 * jedem Backend-Benutzer Zugriff gewähren. Dieser Voter läuft mit Priorität
 * 200 vor Contaos TableAccessVoter (100) und verweigert jedem ohne
 * Prüferfreigabe. Für Berechtigte enthält er sich, dann entscheiden die
 * übrigen Voter wie gewohnt.
 *
 * Unter Contao 4.13 gibt es weder die Action-Klassen noch „contao_dc.“-Rechte;
 * der instanceof-Vergleich mit einer unbekannten Klasse ist in PHP schlicht
 * false, der Voter enthält sich also immer.
 */
class TabellenVoter implements VoterInterface
{
	/** Die geschützten Tabellen */
	public const TABELLEN = array(
		'tl_kinderschutz',
		'tl_kinderschutz_dokumentart',
		'tl_kinderschutz_veranstaltung',
		'tl_kinderschutz_teilnahme',
		'tl_kinderschutz_antrag',
		'tl_kinderschutz_einstellungen',
	);

	/**
	 * @param Berechtigung $berechtigung Die Zugangsregel
	 */
	public function __construct(private readonly Berechtigung $berechtigung)
	{
	}

	/**
	 * Entscheidet über Zugriffe auf die Kinderschutz-Tabellen.
	 *
	 * Die Signatur ist mit Symfony 5.4 (drei Parameter) und 7.4 (optionaler
	 * vierter Parameter $vote) verträglich.
	 *
	 * @param TokenInterface $token      Die Anmeldung
	 * @param mixed          $subject    In Contao 5 eine Create-, Read-, Update-
	 *                                   oder DeleteAction
	 * @param array<mixed>   $attributes Die abgefragten Rechte
	 * @param mixed          $vote       Symfony 7.3+: Begründungsobjekt, unbenutzt
	 *
	 * @return int ACCESS_DENIED für Unberechtigte an den eigenen Tabellen,
	 *             sonst ACCESS_ABSTAIN
	 */
	public function vote(TokenInterface $token, mixed $subject, array $attributes, mixed $vote = null): int
	{
		if (!$subject instanceof AbstractAction || !in_array($subject->getDataSource(), self::TABELLEN, true)) {
			return self::ACCESS_ABSTAIN;
		}

		$fragtDatencontainer = array_filter($attributes, static fn ($attribut): bool => is_string($attribut) && str_starts_with($attribut, 'contao_dc.'));

		if (array() === $fragtDatencontainer) {
			return self::ACCESS_ABSTAIN;
		}

		$benutzer = $token->getUser();

		if ($benutzer instanceof BackendUser && $this->berechtigung->darfPruefen((int) $benutzer->id, '1' === (string) $benutzer->kinderschutzPruefer)) {
			return self::ACCESS_ABSTAIN;
		}

		return self::ACCESS_DENIED;
	}
}
