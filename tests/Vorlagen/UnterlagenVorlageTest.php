<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Vorlagen;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\StatusRechner;

/**
 * Prüft das Template mod_kinderschutz_unterlagen.html5: Es verrät kein
 * Prüfergebnis, maskiert jede Eingabe und liefert ein brauchbares Formular.
 *
 * Das Template wird ohne Contao-Laufzeit gerendert. Eine kleine Bühne
 * übernimmt die Aufgabe von Contaos Template-Objekt: extend(), block() und
 * endblock() tun nichts, und der Zugriff auf Variablen verhält sich wie bei
 * FragmentTemplate in Contao 5 – ein unbekannter Schlüssel wirft eine
 * Ausnahme, statt stillschweigend leer zu sein. StringUtil und Date sind die
 * echten Contao-Klassen. Die Variablen sind so gesetzt, wie der Controller
 * sie setzt (der Controllertest prüft, dass er alle setzt, die das Template
 * liest). Jedes Rendern läuft mit fangendem Fehlerbehandler; Hinweise,
 * Warnungen und Veraltet-Meldungen lassen den Test scheitern.
 */
class UnterlagenVorlageTest extends TestCase
{
	/** Pfad des Templates */
	private const VORLAGE = __DIR__.'/../../src/Resources/contao/templates/mod_kinderschutz_unterlagen.html5';

	/** Anfragetoken mit Anführungszeichen, das im Attribut maskiert werden muss */
	private const ANFRAGETOKEN = 'tok"en';

	/**
	 * Lädt die deutschen Texte des Bundes.
	 *
	 * Das Template liest $GLOBALS['TL_LANG']['MSC']['kinderschutz_status_frontend']
	 * selbst und bekommt die übrigen Texte als Variable „texte“.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_LANG'] = array();

		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';
	}

	/**
	 * Räumt die globalen Texte weg.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_LANG']);
	}

	/**
	 * Zustände einer Unterlage, ihre sichtbare Beschriftung und die CSS-Klasse.
	 *
	 * Jeder Fall nennt die Nachweise der Dokumentart (wie sie aus der
	 * Datenbank kämen); der Stand wird vom StatusRechner bestimmt. Der Stand
	 * „beanstandet“ (einschlägiger Eintrag im Führungszeugnis) erscheint
	 * außen nur als Rücksprache-Bitte.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> Nachweise-Schlüssel (siehe nachweiseFuer()), erwartete Klasse, erwartete Beschriftung
	 */
	public static function zustaende(): array
	{
		return array(
			'noch nichts eingereicht'  => array('fehlt', 'ks-fehlt', 'noch nicht eingereicht'),
			'eingereicht, ungeprüft'   => array('eingereicht', 'ks-eingereicht', 'eingereicht, wird geprüft'),
			'abgelehnt'                => array('abgelehnt', 'ks-abgelehnt', 'nicht anerkannt'),
			'gültig'                   => array('gueltig', 'ks-gueltig', 'gültig'),
			'läuft bald ab'            => array('laeuftAb', 'ks-laeuftAb', 'läuft bald ab'),
			'abgelaufen'               => array('abgelaufen', 'ks-abgelaufen', 'abgelaufen'),
			'einschlägiger Eintrag'    => array('beanstandet', 'ks-ruecksprache', 'bitte Rücksprache halten'),
		);
	}

	/**
	 * Ein beanstandetes Führungszeugnis verrät im Quelltext nichts vom Prüfergebnis.
	 *
	 * Das Mitglied soll nur „bitte Rücksprache halten“ lesen. Im HTML dürfen
	 * deshalb weder der Stand „beanstandet“ noch das Feld „einschlaegig“
	 * vorkommen, auch nicht als CSS-Klasse: Die Klasse heißt
	 * „ks-ruecksprache“. Sichtbar ist nur der neutrale Text; es gibt keinen
	 * Hinweis auf Gültigkeit oder Ablaufdatum der beanstandeten Unterlage.
	 */
	public function testBeanstandeterNachweisVerraetSichNichtImQuelltext(): void
	{
		$daten = $this->basis();
		$daten['zellen'] = $this->zellen($daten['arten'], array(
			1 => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('einschlaegig' => 'ja', 'gueltigBis' => $this->tage(300)))),
		));
		$this->assertSame('beanstandet', $daten['zellen'][1]->status, 'Die Ausgangslage des Tests stimmt nicht.');

		$html = $this->rendere($daten);

		$this->assertStringNotContainsStringIgnoringCase('beanstandet', $html);
		$this->assertStringNotContainsStringIgnoringCase('einschlaegig', $html);
		$this->assertStringContainsString('bitte Rücksprache halten', $html);
		$this->assertCount(1, $this->abfrage($html, '//li[contains(concat(" ", normalize-space(@class), " "), " ks-ruecksprache ")]'));
		$this->assertSame('ks-art ks-ruecksprache', $this->abfrage($html, '//li[contains(@class, "ks-ruecksprache")]')->item(0)->getAttribute('class'));
		$this->assertStringNotContainsString('gültig bis', $html, 'Zur beanstandeten Unterlage darf keine Gültigkeit angezeigt werden.');
	}

	/**
	 * Jeder Stand bekommt Klasse und Beschriftung aus der Sprachdatei.
	 *
	 * Die Beschriftung steht im Kopf der Unterlage, die Klasse am
	 * Listenelement. Neu eingereichtes bei gültiger Unterlage („wartet“),
	 * der Knopf „Hochladen“ bzw. „Neue Fassung hochladen“ und die Angabe
	 * der Gültigkeit hängen vom Stand ab und werden mitgeprüft.
	 *
	 * @dataProvider zustaende
	 *
	 * @param string $fall      Nachweise-Schlüssel (siehe nachweiseFuer())
	 * @param string $klasse    Erwartete Klasse des Listenelements
	 * @param string $erwartet  Erwartete Beschriftung
	 */
	public function testStandBekommtKlasseUndBeschriftung(string $fall, string $klasse, string $erwartet): void
	{
		$daten = $this->basis();
		$daten['zellen'] = $this->zellen($daten['arten'], array(1 => $this->nachweiseFuer($fall)));

		$html = $this->rendere($daten);

		$element = $this->abfrage($html, '//li[contains(@class, "ks-art")]')->item(0);
		$this->assertSame('ks-art '.$klasse, $element->getAttribute('class'));
		$this->assertSame($erwartet, trim($this->abfrage($html, '//li[contains(@class, "ks-art")][1]//span[@class="ks-status"]')->item(0)->textContent));

		$gueltig = in_array($fall, array('gueltig', 'laeuftAb'), true);
		$knopf = trim($this->abfrage($html, '//li[contains(@class, "ks-art")][1]//button')->item(0)->textContent);
		$this->assertSame($gueltig ? 'Neue Fassung hochladen' : 'Hochladen', $knopf);
		$this->assertSame($gueltig, str_contains($html, 'gültig bis'));
	}

	/**
	 * Eine abgelehnte Einreichung zeigt die Begründung, eine wartende den Hinweis auf die Prüfung.
	 *
	 * Die Begründung steht nur bei Stand „abgelehnt“ und nur, wenn eine
	 * eingetragen ist. Zu einer gültigen Unterlage mit neuer, noch
	 * ungeprüfter Einreichung erscheint „Eine neue Einreichung wird geprüft.“,
	 * zu einer bloß eingereichten nicht (die Beschriftung sagt es schon).
	 */
	public function testBegruendungUndWartehinweis(): void
	{
		$daten = $this->basis();
		$daten['zellen'] = $this->zellen($daten['arten'], array(
			1 => array(
				$this->nachweis(10, 1, Nachweis::GEPRUEFT, array('gueltigBis' => $this->tage(300))),
				$this->nachweis(11, 1, Nachweis::EINGEREICHT),
			),
			2 => array($this->nachweis(12, 2, Nachweis::ABGELEHNT, array('ablehnungsgrund' => 'Das Foto ist unleserlich.'))),
		));

		$html = $this->rendere($daten);

		$this->assertStringContainsString('Eine neue Einreichung wird geprüft.', $html);
		$this->assertStringContainsString('Begründung: Das Foto ist unleserlich.', $html);
		$this->assertSame(1, substr_count($html, 'Eine neue Einreichung wird geprüft.'), 'Nur die Unterlage mit wartender Einreichung bekommt den Hinweis.');

		$daten['zellen'] = $this->zellen($daten['arten'], array(
			1 => array($this->nachweis(11, 1, Nachweis::EINGEREICHT)),
			2 => array($this->nachweis(12, 2, Nachweis::ABGELEHNT)),
		));
		$html = $this->rendere($daten);

		$this->assertStringNotContainsString('Eine neue Einreichung wird geprüft.', $html);
		$this->assertStringNotContainsString('Begründung', $html, 'Ohne eingetragenen Grund gibt es keine Begründungszeile.');
	}

	/**
	 * Feindliche Eingaben erscheinen nur maskiert, nie als Markup oder Attribut.
	 *
	 * Eingeschleust werden: ein Dokumentart-Name mit &lt;b&gt;, ein Hinweis
	 * mit &lt;script&gt;, ein Ablehnungsgrund mit &lt;img onerror=…&gt;, ein
	 * Veranstaltungsname mit Anführungszeichen, spitzen Klammern und &amp;
	 * (in der eigenen und der wählbaren Liste), eine Meldung und zwei
	 * Fehlermeldungen mit HTML sowie ein Anfragetoken mit Anführungszeichen.
	 * Geprüft wird auf zwei Ebenen: im geparsten Dokument gibt es keine
	 * fremden Elemente und keine Ereignis-Attribute, die Texte stehen
	 * wörtlich im Textinhalt, und im Quelltext steht die maskierte Form.
	 * Der Anfragetoken bleibt in jedem Formular ein einziger,
	 * unveränderter Attributwert.
	 */
	public function testFeindlicheEingabenErscheinenNurMaskiert(): void
	{
		$efz = new Dokumentart(1, 'Erweitertes <b>Führungszeugnis</b>', "Hinweis <script>alert(1)</script>\nZeile 2", true, 60, true, true);
		$kodex = new Dokumentart(2, 'Ehrenkodex', '', true, 0, false, false);
		$arten = array($efz, $kodex);
		$daten = $this->basis();
		$daten['arten'] = $arten;
		$daten['zellen'] = $this->zellen($arten, array(
			1 => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('gueltigBis' => $this->tage(300)))),
			2 => array($this->nachweis(11, 2, Nachweis::ABGELEHNT, array('ablehnungsgrund' => "Grund <img src=x onerror=alert(1)> & mehr\nzweite Zeile"))),
		));
		$daten['meldungen'] = array('Vielen Dank <i>sehr</i> „Foo & Bar“');
		$daten['fehler'] = array(2 => 'Fehler <b>fett</b>', 0 => 'Sammelfehler <u>unterstrichen</u>');
		$daten['eigene'] = array(array(
			'veranstaltung' => array('id' => 3, 'name' => 'JWM "Kreta" <Finale> & Co', 'startDate' => $this->tage(20), 'endDate' => $this->tage(27), 'quelle' => 'frontend'),
			'fehlend'       => array($kodex, new Dokumentart(5, 'Schulung <u>Pflicht</u>', '', true, 0, false, false)),
		));
		$daten['waehlbare'] = array(array('id' => 9, 'name' => 'Cup "9" <x onclick=1>', 'startDate' => $this->tage(40)));

		$html = $this->rendere($daten);

		foreach (array('script', 'img', 'b', 'i', 'u', 'x', 'finale') as $element) {
			$this->assertCount(0, $this->abfrage($html, '//'.$element), 'Kein Element <'.$element.'> darf aus Eingaben entstehen.');
		}

		$this->assertCount(0, $this->abfrage($html, '//@*[starts-with(name(), "on")]'), 'Kein Ereignis-Attribut darf entstehen.');
		$this->assertCount(0, $this->abfrage($html, '//a'), 'Aus Eingaben darf kein Verweis entstehen.');

		$text = $this->dokument($html)->textContent;

		foreach (array(
			'Erweitertes <b>Führungszeugnis</b>',
			'Hinweis <script>alert(1)</script>',
			'Grund <img src=x onerror=alert(1)> & mehr',
			'Vielen Dank <i>sehr</i> „Foo & Bar“',
			'Fehler <b>fett</b>',
			'Sammelfehler <u>unterstrichen</u>',
			'JWM "Kreta" <Finale> & Co',
			'Cup "9" <x onclick=1>',
			'Schulung <u>Pflicht</u>',
		) as $wortlaut) {
			$this->assertStringContainsString($wortlaut, $text, 'Der Text muss wörtlich sichtbar sein.');
		}

		$this->assertStringContainsString('Erweitertes &lt;b&gt;Führungszeugnis&lt;/b&gt;', $html);
		$this->assertStringContainsString('Hinweis &lt;script&gt;alert(1)&lt;/script&gt;<br />', $html);
		$this->assertStringContainsString('Grund &lt;img src=x onerror=alert(1)&gt; &amp; mehr<br />', $html);
		$this->assertStringContainsString('JWM &quot;Kreta&quot; &lt;Finale&gt; &amp; Co', $html);
		$this->assertStringContainsString('Cup &quot;9&quot; &lt;x onclick=1&gt;', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('<img', $html);

		$felder = $this->abfrage($html, '//input[@name="REQUEST_TOKEN"]');
		$this->assertGreaterThanOrEqual(4, $felder->length);

		foreach ($felder as $feld) {
			$this->assertSame(self::ANFRAGETOKEN, $feld->getAttribute('value'));
			$this->assertSame(3, $feld->attributes->length, 'Das Anführungszeichen im Token darf kein weiteres Attribut öffnen.');
		}
	}

	/**
	 * Das Upload-Formular schickt Datei und Anfragetoken richtig ab.
	 *
	 * Jede Dokumentart hat genau ein Formular mit POST, enctype
	 * „multipart/form-data“ (sonst käme keine Datei an), dem versteckten
	 * Feld REQUEST_TOKEN, der Formularkennung des Moduls, der ID der
	 * Dokumentart und je einem Datei- und Datumsfeld. Das Datum ist auf heute
	 * begrenzt. Beschriftungen zeigen auf vorhandene Felder, und Ids sind
	 * eindeutig. Alle anderen Formulare (An-/Abmeldung) tragen ebenfalls den
	 * Anfragetoken, haben aber keine Dateiübertragung.
	 */
	public function testUploadFormularIstVollstaendig(): void
	{
		$daten = $this->basis();
		$daten['eigene'] = array(array(
			'veranstaltung' => array('id' => 3, 'name' => 'JWM Kreta', 'startDate' => $this->tage(20), 'endDate' => 0, 'quelle' => 'frontend'),
			'fehlend'       => array(),
		));
		$daten['waehlbare'] = array(array('id' => 9, 'name' => 'Offener Cup', 'startDate' => $this->tage(40)));

		$html = $this->rendere($daten);

		$upload = $this->abfrage($html, '//form[.//input[@type="file"]]');
		$this->assertSame(count($daten['arten']), $upload->length, 'Pro Dokumentart ein Upload-Formular.');

		foreach ($upload as $nummer => $formular) {
			$this->assertSame('post', $formular->getAttribute('method'));
			$this->assertSame('multipart/form-data', $formular->getAttribute('enctype'));
			$this->assertSame('kinderschutz_7_upload', $this->wert($formular, './/input[@name="FORM_SUBMIT"]'));
			$this->assertSame(self::ANFRAGETOKEN, $this->wert($formular, './/input[@type="hidden"][@name="REQUEST_TOKEN"]'));
			$this->assertSame((string) $daten['arten'][$nummer]->id, $this->wert($formular, './/input[@name="dokumentart"]'));
			$this->assertSame('datei', $this->wert($formular, './/input[@type="file"]', 'name'));
			$this->assertSame('ausstellungsdatum', $this->wert($formular, './/input[@type="date"]', 'name'));
			$this->assertSame('2026-10-15', $this->wert($formular, './/input[@type="date"]', 'max'));
			$this->assertSame(1, (new \DOMXPath($formular->ownerDocument))->query('.//input[@type="file"][@required]', $formular)->length, 'Die Datei ist Pflicht.');
		}

		$alle = $this->abfrage($html, '//form');
		$this->assertSame(count($daten['arten']) + 2, $alle->length);

		foreach ($alle as $formular) {
			$this->assertSame('post', $formular->getAttribute('method'));
			$this->assertSame(self::ANFRAGETOKEN, $this->wert($formular, './/input[@name="REQUEST_TOKEN"]'));
			$this->assertNotSame('', $this->wert($formular, './/input[@name="FORM_SUBMIT"]'));
		}

		$ohneDatei = $this->abfrage($html, '//form[not(.//input[@type="file"])]');
		$this->assertSame(2, $ohneDatei->length);

		foreach ($ohneDatei as $formular) {
			$this->assertSame('', $formular->getAttribute('enctype'));
			$this->assertSame('kinderschutz_7_veranstaltung', $this->wert($formular, './/input[@name="FORM_SUBMIT"]'));
		}

		$ids = array();

		foreach ($this->abfrage($html, '//@id') as $attribut) {
			$ids[] = $attribut->value;
		}

		$this->assertSame($ids, array_values(array_unique($ids)), 'Ids müssen eindeutig sein.');

		foreach ($this->abfrage($html, '//label/@for') as $verweis) {
			$this->assertContains($verweis->value, $ids, 'Jede Beschriftung gehört zu einem vorhandenen Feld.');
		}
	}

	/**
	 * Ohne Anmeldung oder Zugang erscheint nur der Hinweis, ohne Formulare und ohne Unterlagen.
	 *
	 * In diesem Zweig setzt der Controller nur „texte“ und „hinweis“; das
	 * Template darf nichts anderes anfassen (die Bühne wirft sonst eine
	 * Ausnahme) und gibt weder ein Formular noch einen Anfragetoken aus.
	 */
	public function testHinweisZeigtKeineFormulare(): void
	{
		$daten = array(
			'texte'   => $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'],
			'hinweis' => 'Bitte melden Sie sich an, um Ihre Unterlagen einzureichen.',
		);

		$html = $this->rendere($daten);

		$this->assertSame(
			'Bitte melden Sie sich an, um Ihre Unterlagen einzureichen.',
			trim($this->abfrage($html, '//p[@class="ks-hinweis"]')->item(0)->textContent)
		);
		$this->assertCount(0, $this->abfrage($html, '//form'));
		$this->assertStringNotContainsString('REQUEST_TOKEN', $html);
		$this->assertStringNotContainsString('ks-art', $html);
	}

	/**
	 * Leere Listen erzeugen keine Hinweise und keine kaputten Überschriften.
	 *
	 * Ohne eigene Veranstaltungen, ohne wählbare, ohne Meldungen und Fehler
	 * rendert das Template trotzdem fehlerfrei (der Fehlerbehandler in
	 * rendere() lässt jede Warnung scheitern) und sagt, dass derzeit nichts
	 * zur Auswahl steht. Ohne Dokumentarten gibt es nur die Überschriften.
	 */
	public function testLeereListenRendernOhneHinweise(): void
	{
		$daten = $this->basis();
		$daten['eigene'] = array();
		$daten['waehlbare'] = array();
		$daten['meldungen'] = array();
		$daten['fehler'] = array();

		$html = $this->rendere($daten);

		$this->assertStringContainsString($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['keineWaehlbar'], $html);
		$this->assertCount(0, $this->abfrage($html, '//*[@class="ks-meldung"]'));
		$this->assertCount(0, $this->abfrage($html, '//*[@class="ks-fehler"]'));

		$daten['arten'] = array();
		$daten['zellen'] = array();

		$html = $this->rendere($daten);

		$this->assertCount(0, $this->abfrage($html, '//li[contains(@class, "ks-art")]'));
		$this->assertStringContainsString($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['unterlagen'], $html);
	}

	/**
	 * Veranstaltungen: vom Backend zugeordnete sind gesperrt, selbst gewählte abmeldbar.
	 *
	 * Eine Zuordnung durch die Kinderschutzbeauftragte (Herkunft „backend“)
	 * hat kein Abmeldeformular, nur den Hinweis mit Schloss. Eine selbst
	 * gewählte hat das Formular mit Aktion „abmelden“. Die Liste sagt,
	 * ob die Unterlagen vollständig sind oder welche fehlen.
	 */
	public function testZugeordneteVeranstaltungenSindGesperrt(): void
	{
		$daten = $this->basis();
		$kodex = $daten['arten'][1];
		$daten['eigene'] = array(
			array(
				'veranstaltung' => array('id' => 3, 'name' => 'Selbst gewählt', 'startDate' => $this->tage(20), 'endDate' => 0, 'quelle' => 'frontend'),
				'fehlend'       => array(),
			),
			array(
				'veranstaltung' => array('id' => 4, 'name' => 'Zugeordnet', 'startDate' => $this->tage(30), 'endDate' => $this->tage(35), 'quelle' => 'backend'),
				'fehlend'       => array($kodex),
			),
		);

		$html = $this->rendere($daten);

		$eintraege = $this->abfrage($html, '//li[contains(@class, "ks-veranstaltung")][.//strong]');
		$this->assertSame(2, $eintraege->length, 'Beide eigenen Veranstaltungen stehen in der Liste.');

		$selbst = $eintraege->item(0);
		$this->assertStringContainsString('ks-gueltig', $selbst->getAttribute('class'));
		$this->assertStringContainsString($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['vollstaendig'], $selbst->textContent);
		$this->assertSame('abmelden', $this->wert($selbst, './/input[@name="aktion"]'));
		$this->assertSame('3', $this->wert($selbst, './/input[@name="veranstaltung"]'));

		$zugeordnet = $eintraege->item(1);
		$this->assertStringContainsString('ks-fehlt', $zugeordnet->getAttribute('class'));
		$this->assertStringContainsString(sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['esFehlt'], 'Ehrenkodex'), $zugeordnet->textContent);
		$this->assertCount(0, $this->abfrage($html, '//li[contains(@class, "ks-veranstaltung")][.//strong[text()="Zugeordnet"]]//form'), 'Zugeordnete Veranstaltungen lassen sich nicht abmelden.');
		$this->assertStringContainsString($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['gesperrt'], $zugeordnet->textContent);
	}

	/**
	 * Baut gutartige Beispieldaten, so wie der Controller sie setzt.
	 *
	 * Zwei Pflicht-Dokumentarten ohne Nachweise, eine eigene und keine
	 * wählbare Veranstaltung ohne Besonderheiten. Einzelne Tests ersetzen,
	 * was sie brauchen.
	 *
	 * @return array<string, mixed> Variablen des Templates
	 */
	private function basis(): array
	{
		$arten = array(
			new Dokumentart(1, 'Erweitertes Führungszeugnis', 'Bitte aktuell, nicht älter als drei Monate.', true, 60, true, true),
			new Dokumentart(2, 'Ehrenkodex', '', true, 0, false, false),
		);

		return array(
			'texte'          => $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'],
			'hinweis'        => '',
			'meldungen'      => array(),
			'formular'       => 'kinderschutz_7',
			'requestToken'   => self::ANFRAGETOKEN,
			'hoechstgroesse' => '10 MB',
			'heute'          => '2026-10-15',
			'arten'          => $arten,
			'zellen'         => $this->zellen($arten, array()),
			'fehler'         => array(),
			'eigene'         => array(),
			'waehlbare'      => array(),
		);
	}

	/**
	 * Berechnet die Stände wie der Statusdienst, mit festem Stichtag und acht Wochen Vorlauf.
	 *
	 * @param list<Dokumentart>                $arten     Die Dokumentarten
	 * @param array<int, list<Nachweis>>       $nachweise Nachweise je Dokumentart-ID
	 *
	 * @return array<int, \Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus> Stand je Dokumentart-ID
	 */
	private function zellen(array $arten, array $nachweise): array
	{
		return (new StatusRechner(56))->zeile($arten, $nachweise, $this->tage(0));
	}

	/**
	 * Legt einen Nachweis an.
	 *
	 * @param int                  $id          ID des Nachweises
	 * @param int                  $dokumentart ID der Dokumentart
	 * @param string               $status      Gespeicherter Status
	 * @param array<string, mixed> $mehr        Abweichungen: einschlaegig, gueltigBis, ablehnungsgrund
	 *
	 * @return Nachweis Der Nachweis, ausgestellt und geprüft vor einem Jahr
	 */
	private function nachweis(int $id, int $dokumentart, string $status, array $mehr = array()): Nachweis
	{
		$vorEinemJahr = $this->tage(-365);

		return new Nachweis(
			$id,
			5,
			$dokumentart,
			$status,
			$vorEinemJahr,
			$vorEinemJahr,
			Nachweis::GEPRUEFT === $status ? $vorEinemJahr : 0,
			(string) ($mehr['einschlaegig'] ?? ''),
			array_key_exists('gueltigBis', $mehr) ? $mehr['gueltigBis'] : null,
			(string) ($mehr['ablehnungsgrund'] ?? '')
		);
	}

	/**
	 * Nachweise zu einem Stand aus der Fallliste.
	 *
	 * @param string $fall fehlt, eingereicht, abgelehnt, gueltig, laeuftAb, abgelaufen oder beanstandet
	 *
	 * @return list<Nachweis> Die Nachweise, aus denen der StatusRechner den Stand ableitet
	 */
	private function nachweiseFuer(string $fall): array
	{
		return match ($fall) {
			'eingereicht' => array($this->nachweis(10, 1, Nachweis::EINGEREICHT)),
			'abgelehnt'   => array($this->nachweis(10, 1, Nachweis::ABGELEHNT, array('ablehnungsgrund' => 'Zu alt.'))),
			'gueltig'     => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('gueltigBis' => $this->tage(300)))),
			'laeuftAb'    => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('gueltigBis' => $this->tage(10)))),
			'abgelaufen'  => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('gueltigBis' => $this->tage(-5)))),
			'beanstandet' => array($this->nachweis(10, 1, Nachweis::GEPRUEFT, array('einschlaegig' => 'ja', 'gueltigBis' => $this->tage(300)))),
			default       => array(),
		};
	}

	/**
	 * Ein Tag relativ zum Stichtag als Zeitstempel von 0:00 Uhr.
	 *
	 * @param int $tage Tage ab dem Stichtag (negativ = davor)
	 *
	 * @return int Mitternacht Ortszeit; über mktime gerechnet, damit die Zeitumstellung nicht stört
	 */
	private function tage(int $tage): int
	{
		return mktime(0, 0, 0, 10, 15 + $tage, 2026);
	}

	/**
	 * Rendert das Template mit den gegebenen Variablen.
	 *
	 * Die Bühne hängt wie FragmentTemplate in Contao 5: Ein unbekannter
	 * Schlüssel wirft eine Ausnahme. Während des Renderns fängt ein
	 * Fehlerbehandler alles auf (Hinweise, Warnungen, Veraltet-Meldungen);
	 * bleibt etwas übrig, scheitert der Test mit der Meldung samt Zeile.
	 *
	 * @param array<string, mixed> $daten Variablen des Templates
	 *
	 * @return string Das gerenderte HTML
	 */
	private function rendere(array $daten): string
	{
		$buehne = new class($daten) {
			/**
			 * @param array<string, mixed> $daten Variablen des Templates
			 */
			public function __construct(private readonly array $daten)
			{
			}

			/**
			 * Liefert eine Variable wie FragmentTemplate::get().
			 *
			 * @param string $schluessel Name der Variablen
			 *
			 * @return mixed Der Wert
			 *
			 * @throws \RuntimeException Wenn die Variable nicht gesetzt (oder null) ist
			 */
			public function __get(string $schluessel): mixed
			{
				return $this->daten[$schluessel] ?? throw new \RuntimeException(sprintf('Die Variable "%s" ist nicht gesetzt.', $schluessel));
			}

			/**
			 * Sagt, ob eine Variable gesetzt ist, wie FragmentTemplate::has().
			 *
			 * @param string $schluessel Name der Variablen
			 *
			 * @return bool True, wenn gesetzt und nicht null
			 */
			public function __isset(string $schluessel): bool
			{
				return isset($this->daten[$schluessel]);
			}

			/**
			 * Ersetzt Template::extend(), das hier nichts zu erben hat.
			 *
			 * @param string $name Name des Eltern-Templates
			 */
			public function extend(string $name): void
			{
			}

			/**
			 * Ersetzt Template::block(); der Inhalt wird einfach ausgegeben.
			 *
			 * @param string $name Name des Blocks
			 */
			public function block(string $name): void
			{
			}

			/**
			 * Ersetzt Template::endblock().
			 */
			public function endblock(): void
			{
			}

			/**
			 * Führt das Template mit sich als $this aus.
			 *
			 * @param string $datei Pfad des Templates
			 *
			 * @return string Die Ausgabe
			 */
			public function rendere(string $datei): string
			{
				ob_start();

				try {
					include $datei;

					return (string) ob_get_contents();
				} finally {
					ob_end_clean();
				}
			}
		};

		$fehler = array();
		$vorher = error_reporting(E_ALL);
		set_error_handler(static function (int $nummer, string $text, string $datei, int $zeile) use (&$fehler): bool {
			$fehler[] = $text.' ('.basename($datei).':'.$zeile.')';

			return true;
		});

		try {
			$html = $buehne->rendere(self::VORLAGE);
		} finally {
			restore_error_handler();
			error_reporting($vorher);
		}

		$this->assertSame(array(), $fehler, 'Beim Rendern dürfen keine Hinweise, Warnungen oder Veraltet-Meldungen auftreten.');

		return $html;
	}

	/**
	 * Parst HTML zu einem Dokument.
	 *
	 * @param string $html Das HTML
	 *
	 * @return \DOMDocument Das Dokument; Parserfehler (etwa unbekannte HTML5-Elemente) werden unterdrückt
	 */
	private function dokument(string $html): \DOMDocument
	{
		$dokument = new \DOMDocument();
		$vorher = libxml_use_internal_errors(true);
		$dokument->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>');
		libxml_clear_errors();
		libxml_use_internal_errors($vorher);

		return $dokument;
	}

	/**
	 * Fragt ein Dokument per XPath ab.
	 *
	 * @param string $html Das HTML
	 * @param string $ausdruck Der XPath-Ausdruck
	 *
	 * @return \DOMNodeList Die Treffer
	 */
	private function abfrage(string $html, string $ausdruck): \DOMNodeList
	{
		$treffer = (new \DOMXPath($this->dokument($html)))->query($ausdruck);
		$this->assertInstanceOf(\DOMNodeList::class, $treffer, 'Ungültiger XPath-Ausdruck: '.$ausdruck);

		return $treffer;
	}

	/**
	 * Liest ein Attribut eines Unterelements.
	 *
	 * @param \DOMNode $ausgang   Ausgangsknoten
	 * @param string   $ausdruck  XPath relativ zum Ausgang
	 * @param string   $attribut  Name des Attributs
	 *
	 * @return string Der Attributwert oder ein leerer Text, wenn es das Element nicht gibt
	 */
	private function wert(\DOMNode $ausgang, string $ausdruck, string $attribut = 'value'): string
	{
		$treffer = (new \DOMXPath($ausgang->ownerDocument))->query($ausdruck, $ausgang);

		return false === $treffer || 0 === $treffer->length ? '' : $treffer->item(0)->getAttribute($attribut);
	}
}
