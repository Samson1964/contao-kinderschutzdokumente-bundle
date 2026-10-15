<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Sicherheit;

use Contao\BackendUser;
use Contao\CoreBundle\Security\DataContainer\AbstractAction;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\FrontendUser;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Sicherheit\TabellenVoter;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Prüft, dass Unberechtigte die Kinderschutz-Tabellen über die
 * Contao-5-Rechte nicht lesen können (Backend-Suche, Listen).
 *
 * Die Action-Klassen gibt es nur in Contao 5; unter 4.13 wird die ganze
 * Klasse übersprungen.
 */
class TabellenVoterTest extends TestCase
{
	/**
	 * Die Tabellen, die der Voter mindestens schützen muss.
	 *
	 * Bewusst eine eigene Liste und nicht nur TabellenVoter::TABELLEN: Verlöre
	 * die Konstante des Voters einen Eintrag, liefe ein Datenlieferant, der
	 * nur sie durchzählt, am Fehler vorbei.
	 */
	private const TABELLEN = array(
		'tl_kinderschutz',
		'tl_kinderschutz_dokumentart',
		'tl_kinderschutz_veranstaltung',
		'tl_kinderschutz_teilnahme',
	);

	/**
	 * Überspringt die Tests, wenn die Contao-5-Action-Klassen fehlen.
	 *
	 * Contao 4.13 kennt weder die Klassen noch die „contao_dc.“-Rechte. Der
	 * Voter selbst ist dort harmlos (er enthält sich immer), aber die Tests
	 * könnten ihre Eingaben nicht aufbauen.
	 */
	protected function setUp(): void
	{
		if (!class_exists(ReadAction::class)) {
			$this->markTestSkipped('Die Action-Klassen aus Contao\\CoreBundle\\Security\\DataContainer gibt es erst ab Contao 5.');
		}
	}

	/**
	 * Admin ohne Freigabe: verweigert; Prüfer und Hauptadmin: Enthaltung.
	 */
	public function testEigeneTabellen(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));
		$lesen = new ReadAction('tl_kinderschutz', array('id' => 3));
		$aendern = new UpdateAction('tl_kinderschutz_veranstaltung', array('id' => 2));

		$this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(3, ''), $lesen, array('contao_dc.tl_kinderschutz')));
		$this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(3, ''), $aendern, array('contao_dc.tl_kinderschutz_veranstaltung')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(7, '1'), $lesen, array('contao_dc.tl_kinderschutz')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(1, ''), $lesen, array('contao_dc.tl_kinderschutz')));
	}

	/**
	 * Fremde Tabellen und fremde Rechte bleiben unberührt.
	 */
	public function testFremdes(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));

		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), new ReadAction('tl_news', array('id' => 1)), array('contao_dc.tl_news')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), new ReadAction('tl_kinderschutz', array('id' => 1)), array('contao_user.modules')));
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(3, ''), 'tl_kinderschutz', array('contao_dc.tl_kinderschutz')));
	}

	/**
	 * Der Voter führt genau die Tabellen, die das Bundle als DCA mitbringt.
	 *
	 * Die Tabellen ergeben sich aus den Dateien tl_kinderschutz*.php im
	 * DCA-Verzeichnis. Kommt dort eine Tabelle hinzu, ohne dass sie in
	 * TabellenVoter::TABELLEN landet, wäre sie für die Backend-Suche und die
	 * übrigen Contao-5-Dienste offen; fehlt sie dort, ist der Test rot.
	 */
	public function testVoterFuehrtAlleTabellenDesBundles(): void
	{
		$dateien = glob(__DIR__.'/../../src/Resources/contao/dca/tl_kinderschutz*.php');
		$this->assertNotEmpty($dateien);

		$this->assertEqualsCanonicalizing(
			array_map(static fn (string $datei): string => basename($datei, '.php'), $dateien),
			TabellenVoter::TABELLEN
		);
	}

	/**
	 * Ein Administrator ohne Freigabe wird an jeder geschützten Tabelle bei
	 * jeder Aktionsart verweigert.
	 *
	 * Der Datenlieferant läuft über die Tabellen dieser Testklasse (ergänzt um
	 * alle Einträge von TabellenVoter::TABELLEN) und die vier Aktionsklassen.
	 * Ein Voter, der eine Tabelle oder eine Aktion vergisst, fällt damit auf,
	 * auch wenn die beiden Stichproben aus testEigeneTabellen() grün blieben.
	 * Das Admin-Kennzeichen des Tokens zählt bewusst nicht.
	 *
	 * @dataProvider tabellenUndAktionen
	 *
	 * @param string $tabelle Name der geschützten Tabelle
	 * @param string $klasse  Klasse der Aktion
	 */
	public function testUnberechtigterWirdUeberallVerweigert(string $tabelle, string $klasse): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));

		$this->assertSame(
			VoterInterface::ACCESS_DENIED,
			$voter->vote($this->token(3, ''), $this->aktion($klasse, $tabelle), array('contao_dc.'.$tabelle))
		);
	}

	/**
	 * Prüfer und Hauptadmin enthalten sich an jeder geschützten Tabelle bei
	 * jeder Aktionsart, damit die übrigen Voter entscheiden.
	 *
	 * @dataProvider tabellenUndAktionen
	 *
	 * @param string $tabelle Name der geschützten Tabelle
	 * @param string $klasse  Klasse der Aktion
	 */
	public function testBerechtigteEnthaltenSichUeberall(string $tabelle, string $klasse): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));
		$aktion = $this->aktion($klasse, $tabelle);
		$attribute = array('contao_dc.'.$tabelle);

		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(7, '1'), $aktion, $attribute), 'Prüfer');
		$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(1, ''), $aktion, $attribute), 'Hauptadmin');
	}

	/**
	 * Ein Frontend-Mitglied ist nie berechtigt, auch nicht mit der ID des
	 * Hauptadmins und gesetztem Freigabefeld.
	 *
	 * Mitglieder und Backend-Benutzer haben getrennte ID-Räume. Prüfte der
	 * Voter nur die ID oder das Feld, käme das Mitglied mit der ID 1 durch.
	 * Deshalb muss der Voter die Benutzerklasse prüfen.
	 */
	public function testFrontendMitgliedWirdVerweigert(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));

		foreach (self::TABELLEN as $tabelle) {
			$this->assertSame(
				VoterInterface::ACCESS_DENIED,
				$voter->vote($this->frontendToken(1, '1'), new ReadAction($tabelle, array('id' => 1)), array('contao_dc.'.$tabelle)),
				$tabelle
			);
		}
	}

	/**
	 * Mischt eine Anfrage ein Datencontainer-Recht mit einem fremden, wird
	 * Unberechtigten trotzdem verweigert.
	 *
	 * Der Voter darf sich nicht schon deshalb enthalten, weil das erste oder
	 * das einzige Attribut nicht „contao_dc.“ lautet. Berechtigte enthalten
	 * sich weiterhin. Beide Reihenfolgen werden geprüft.
	 */
	public function testGemischteAttributeBeiUnberechtigtem(): void
	{
		$voter = new TabellenVoter(new Berechtigung(1));
		$aktion = new ReadAction('tl_kinderschutz', array('id' => 1));

		foreach (array(
			'fremd zuerst' => array('contao_user.modules', 'contao_dc.tl_kinderschutz'),
			'fremd zuletzt' => array('contao_dc.tl_kinderschutz', 'contao_user.modules'),
		) as $name => $attribute) {
			$this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(3, ''), $aktion, $attribute), $name);
			$this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token(7, '1'), $aktion, $attribute), $name.' (Prüfer)');
		}
	}

	/**
	 * Liefert jede geschützte Tabelle in Verbindung mit jeder Aktionsklasse.
	 *
	 * Die Tabellen sind die feste Liste dieser Testklasse zusammen mit denen der
	 * Voter-Konstante, ohne Doppelte. So bleibt eine Tabelle auch dann im Test,
	 * wenn der Voter sie verliert, und eine neue im Voter kommt von selbst dazu.
	 *
	 * Der Lieferant läuft vor setUp() und darf deshalb die Action-Klassen nur
	 * als Namen verwenden (::class lädt sie nicht). Die Aktionen selbst baut
	 * erst aktion() im Test, wo setUp() sie unter Contao 4.13 schon
	 * übersprungen hat.
	 *
	 * @return iterable<string, array{string, string}> Je Kombination Tabelle und Klassenname
	 */
	public static function tabellenUndAktionen(): iterable
	{
		$aktionen = array(
			'CreateAction' => CreateAction::class,
			'ReadAction'   => ReadAction::class,
			'UpdateAction' => UpdateAction::class,
			'DeleteAction' => DeleteAction::class,
		);

		foreach (array_unique(array_merge(self::TABELLEN, TabellenVoter::TABELLEN)) as $tabelle) {
			foreach ($aktionen as $kurzname => $klasse) {
				yield $tabelle.' / '.$kurzname => array($tabelle, $klasse);
			}
		}
	}

	/**
	 * Baut eine Action der gewünschten Klasse für eine Tabelle.
	 *
	 * Die Konstruktoren unterscheiden sich: CreateAction nimmt nur optional die
	 * neuen Werte, Read- und DeleteAction den aktuellen Datensatz, UpdateAction
	 * den aktuellen und die neuen Werte. Der Voter wertet nur die Tabelle aus
	 * (getDataSource()); die Datensatzinhalte sind hier beliebig.
	 *
	 * @param string $klasse  Eine der vier Action-Klassen
	 * @param string $tabelle Name der Tabelle
	 *
	 * @return AbstractAction Die Action
	 */
	private function aktion(string $klasse, string $tabelle): AbstractAction
	{
		return match ($klasse) {
			CreateAction::class => new CreateAction($tabelle, array('pid' => 1)),
			UpdateAction::class => new UpdateAction($tabelle, array('id' => 5), array('id' => 5)),
			default => new $klasse($tabelle, array('id' => 5)),
		};
	}

	/**
	 * Baut ein Token mit einem Backend-Benutzer.
	 *
	 * @param int    $id      ID in tl_user
	 * @param string $pruefer Wert von kinderschutzPruefer
	 *
	 * @return UsernamePasswordToken Das Token
	 */
	private function token(int $id, string $pruefer): UsernamePasswordToken
	{
		$benutzer = $this->getMockBuilder(BackendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$benutzer->method('getRoles')->willReturn(array('ROLE_USER'));
		$benutzer->id = $id;
		$benutzer->admin = '1';
		$benutzer->kinderschutzPruefer = $pruefer;

		return new UsernamePasswordToken($benutzer, 'contao_backend', array('ROLE_USER'));
	}

	/**
	 * Baut ein Token mit einem Frontend-Mitglied.
	 *
	 * Das Mitglied trägt dieselben Eigenschaften wie der Backend-Benutzer in
	 * token(), damit nur die Benutzerklasse den Unterschied macht.
	 *
	 * @param int    $id      ID in tl_member
	 * @param string $pruefer Wert, der in kinderschutzPruefer stehen würde
	 *
	 * @return UsernamePasswordToken Das Token
	 */
	private function frontendToken(int $id, string $pruefer): UsernamePasswordToken
	{
		$mitglied = $this->getMockBuilder(FrontendUser::class)->disableOriginalConstructor()->onlyMethods(array('getRoles'))->getMock();
		$mitglied->method('getRoles')->willReturn(array('ROLE_MEMBER'));
		$mitglied->id = $id;
		$mitglied->kinderschutzPruefer = $pruefer;

		return new UsernamePasswordToken($mitglied, 'contao_frontend', array('ROLE_MEMBER'));
	}
}
