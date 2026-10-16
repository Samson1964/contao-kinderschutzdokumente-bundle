<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\SeitenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank;

/**
 * Prüft die Suche nach Seiten mit dem Frontend-Modul „Meine Unterlagen“.
 */
class SeitenRepositoryTest extends TestCase
{
	/**
	 * Gefunden werden nur Seiten mit einem Modul-Element des richtigen Typs,
	 * jede Seite einmal, nach Titel sortiert.
	 */
	public function testFindetSeitenMitDemModul(): void
	{
		$db = Datenbank::verbindung();
		$unterlagen = $this->modul($db, SeitenRepository::MODULTYP);
		$anmeldung = $this->modul($db, 'login');

		$zielseite = $this->seite($db, 'Zielseite', 'ziel');
		$this->element($db, $this->artikel($db, $zielseite), 'module', $unterlagen);
		$this->element($db, $this->artikel($db, $zielseite), 'module', $unterlagen);

		$alt = $this->seite($db, 'Alte Seite', 'alt');
		$this->element($db, $this->artikel($db, $alt), 'module', $unterlagen, '');

		$this->element($db, $this->artikel($db, $this->seite($db, 'Nur Anmeldung', 'login')), 'module', $anmeldung);
		$this->element($db, $this->artikel($db, $this->seite($db, 'Text', 'text')), 'text', $unterlagen);
		$this->element($db, $this->artikel($db, $this->seite($db, 'Verschachtelt', 'tief')), 'module', $unterlagen, 'tl_content');

		$this->assertSame(
			array(
				array('id' => $alt, 'title' => 'Alte Seite', 'alias' => 'alt'),
				array('id' => $zielseite, 'title' => 'Zielseite', 'alias' => 'ziel'),
			),
			(new SeitenRepository($db))->mitUnterlagenModul(),
		);
	}

	/**
	 * Ohne passende Seite ist die Liste leer.
	 */
	public function testOhneModulLeer(): void
	{
		$this->assertSame(array(), (new SeitenRepository(Datenbank::verbindung()))->mitUnterlagenModul());
	}

	/**
	 * Eine einzelne Seite wird gefunden, eine fehlende ergibt null.
	 */
	public function testEinzelneSeite(): void
	{
		$db = Datenbank::verbindung();
		$id = $this->seite($db, 'Zielseite', 'ziel');

		$this->assertSame(array('id' => $id, 'title' => 'Zielseite', 'alias' => 'ziel'), (new SeitenRepository($db))->seite($id));
		$this->assertNull((new SeitenRepository($db))->seite($id + 1));
	}

	/**
	 * Legt eine Seite an.
	 *
	 * @param Connection $db    Die Testdatenbank
	 * @param string     $titel Seitentitel
	 * @param string     $alias Alias
	 *
	 * @return int Die ID der Seite
	 */
	private function seite(Connection $db, string $titel, string $alias): int
	{
		$db->insert('tl_page', array('title' => $titel, 'alias' => $alias));

		return (int) $db->lastInsertId();
	}

	/**
	 * Legt einen Artikel auf einer Seite an.
	 *
	 * @param Connection $db    Die Testdatenbank
	 * @param int        $seite ID der Seite
	 *
	 * @return int Die ID des Artikels
	 */
	private function artikel(Connection $db, int $seite): int
	{
		$db->insert('tl_article', array('pid' => $seite));

		return (int) $db->lastInsertId();
	}

	/**
	 * Legt ein Frontend-Modul an.
	 *
	 * @param Connection $db  Die Testdatenbank
	 * @param string     $typ Modultyp
	 *
	 * @return int Die ID des Moduls
	 */
	private function modul(Connection $db, string $typ): int
	{
		$db->insert('tl_module', array('type' => $typ));

		return (int) $db->lastInsertId();
	}

	/**
	 * Legt ein Inhaltselement an.
	 *
	 * @param Connection $db      Die Testdatenbank
	 * @param int        $artikel ID des Elternartikels
	 * @param string     $typ     Elementtyp
	 * @param int        $modul   ID des eingebundenen Moduls
	 * @param string     $ptable  Elterntabelle; leer wie in alten Installationen
	 */
	private function element(Connection $db, int $artikel, string $typ, int $modul, string $ptable = 'tl_article'): void
	{
		$db->insert('tl_content', array('pid' => $artikel, 'ptable' => $ptable, 'type' => $typ, 'module' => $modul));
	}
}
