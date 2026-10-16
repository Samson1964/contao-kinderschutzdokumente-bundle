<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank;

use Doctrine\DBAL\Connection;

/**
 * Findet die Seiten, auf denen das Frontend-Modul „Meine Unterlagen“ steht.
 *
 * Nur lesend. Gesucht wird über tl_content → tl_article → tl_page: ein
 * Inhaltselement vom Typ „module“, dessen Modul (tl_module) den Typ
 * „kinderschutz_unterlagen“ hat, in einem Artikel der Seite. Die Spalten
 * (tl_content.type/module/pid/ptable, tl_article.pid, tl_page.title/alias)
 * sind in Contao 4.13 und 5.7 gleich. Ein leeres ptable stammt aus sehr alten
 * Installationen und bedeutet ebenfalls „tl_article“.
 *
 * Nicht gefunden wird ein Modul, das nur über das Seitenlayout oder in einem
 * verschachtelten Element (ptable „tl_content“) eingebunden ist; das ist
 * selten, die Einstellungsmaske erklärt es.
 */
class SeitenRepository
{
	/**
	 * Typ des Frontend-Moduls „Kinderschutz: Meine Unterlagen“.
	 */
	public const MODULTYP = 'kinderschutz_unterlagen';

	/**
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liefert die Seiten mit dem Frontend-Modul „Meine Unterlagen“.
	 *
	 * Auch unveröffentlichte Seiten, Artikel und Elemente zählen: Wer die
	 * Seite gerade einrichtet, soll sie schon auswählen können.
	 *
	 * @return list<array{id: int, title: string, alias: string}> Jede Seite
	 *         einmal, nach Titel und ID sortiert; leer, wenn es keine gibt
	 */
	public function mitUnterlagenModul(): array
	{
		$zeilen = $this->db->fetchAllAssociative(
			"SELECT DISTINCT p.id, p.title, p.alias FROM tl_content c
				INNER JOIN tl_module m ON m.id = c.module
				INNER JOIN tl_article a ON a.id = c.pid
				INNER JOIN tl_page p ON p.id = a.pid
				WHERE c.type = 'module' AND m.type = ? AND (c.ptable = 'tl_article' OR c.ptable = '')
				ORDER BY p.title, p.id",
			array(self::MODULTYP),
		);

		return array_map(static fn (array $zeile): array => array('id' => (int) $zeile['id'], 'title' => (string) $zeile['title'], 'alias' => (string) $zeile['alias']), $zeilen);
	}

	/**
	 * Liefert eine Seite.
	 *
	 * @param int $id ID der Seite
	 *
	 * @return array{id: int, title: string, alias: string}|null Die Seite, oder
	 *         null, wenn es sie nicht (mehr) gibt
	 */
	public function seite(int $id): ?array
	{
		$zeile = $this->db->fetchAssociative('SELECT id, title, alias FROM tl_page WHERE id = ?', array($id));

		return false === $zeile ? null : array('id' => (int) $zeile['id'], 'title' => (string) $zeile['title'], 'alias' => (string) $zeile['alias']);
	}
}
