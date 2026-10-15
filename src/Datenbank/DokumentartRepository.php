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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;

/**
 * Liest die Dokumentarten aus tl_kinderschutz_dokumentart.
 */
class DokumentartRepository
{
	/**
	 * @param Connection $db Die Datenbankverbindung von Contao
	 */
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liefert die aktiven Dokumentarten in der eingestellten Reihenfolge.
	 *
	 * @return list<Dokumentart> Die Arten; inaktive fehlen
	 */
	public function aktive(): array
	{
		return array_map(
			static fn (array $zeile): Dokumentart => Dokumentart::ausZeile($zeile),
			$this->db->fetchAllAssociative("SELECT * FROM tl_kinderschutz_dokumentart WHERE published = '1' ORDER BY reihenfolge, name")
		);
	}

	/**
	 * Liefert eine Dokumentart, auch eine inaktive.
	 *
	 * @param int $id ID der Dokumentart
	 *
	 * @return Dokumentart|null Die Art, oder null, wenn es sie nicht gibt
	 */
	public function eine(int $id): ?Dokumentart
	{
		$zeile = $this->db->fetchAssociative('SELECT * FROM tl_kinderschutz_dokumentart WHERE id = ?', array($id));

		return false === $zeile ? null : Dokumentart::ausZeile($zeile);
	}
}
