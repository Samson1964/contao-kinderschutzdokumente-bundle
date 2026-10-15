<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Regeln;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\UploadPruefer;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Testdateien;

/**
 * Prüft Typerkennung am Inhalt, Größengrenzen und Upload-Fehlercodes.
 */
class UploadPrueferTest extends TestCase
{
	/**
	 * PDF, JPEG und PNG werden erkannt, die Endung folgt dem Inhalt.
	 */
	public function testZulaessigeTypen(): void
	{
		$pruefer = new UploadPruefer(1024 * 1024);

		foreach (array('pdf' => Testdateien::pdf(), 'jpg' => Testdateien::jpeg(), 'png' => Testdateien::png()) as $endung => $pfad) {
			$this->assertNull($pruefer->fehler($pfad, (int) filesize($pfad), UPLOAD_ERR_OK), $endung);
			$this->assertSame($endung, $pruefer->endung($pfad));
		}
	}

	/**
	 * Die Endung richtet sich nach dem erkannten Inhalt, nicht nach dem Dateinamen.
	 *
	 * Ein PNG, das als „.pdf“ hochgeladen wird, wird angenommen und unter der
	 * Endung „png“ gespeichert; ein PDF unter der Endung „.jpg“ unter „pdf“.
	 * Ein Prüfer, der den Dateinamen auswertet, würde hier „pdf“ bzw. „jpg“
	 * liefern und den Test rot machen.
	 */
	public function testEndungFolgtDemInhalt(): void
	{
		$pruefer = new UploadPruefer(1024 * 1024);

		$pngMitPdfEndung = Testdateien::datei((string) file_get_contents(Testdateien::png()), 'pdf');
		$this->assertSame('png', $pruefer->endung($pngMitPdfEndung));
		$this->assertNull($pruefer->fehler($pngMitPdfEndung, (int) filesize($pngMitPdfEndung), UPLOAD_ERR_OK));

		$pdfMitJpgEndung = Testdateien::datei((string) file_get_contents(Testdateien::pdf()), 'jpg');
		$this->assertSame('pdf', $pruefer->endung($pdfMitJpgEndung));
		$this->assertNull($pruefer->fehler($pdfMitJpgEndung, (int) filesize($pdfMitJpgEndung), UPLOAD_ERR_OK));
	}

	/**
	 * Ein PHP-Skript mit Endung .pdf wird abgewiesen.
	 */
	public function testFalscheEndungHilftNicht(): void
	{
		$pfad = Testdateien::datei('<?php echo "hallo";', 'pdf');

		$this->assertSame('typ', (new UploadPruefer(1024 * 1024))->fehler($pfad, (int) filesize($pfad), UPLOAD_ERR_OK));
	}

	/**
	 * Zu groß, leer und abgebrochen werden unterschieden.
	 */
	public function testGroesseUndFehlercodes(): void
	{
		$pfad = Testdateien::pdf();
		$pruefer = new UploadPruefer(10);

		$this->assertSame('zuGross', $pruefer->fehler($pfad, (int) filesize($pfad), UPLOAD_ERR_OK));
		$this->assertSame('zuGross', $pruefer->fehler('', 0, UPLOAD_ERR_INI_SIZE));
		$this->assertSame('upload', $pruefer->fehler('', 0, UPLOAD_ERR_PARTIAL));
		$this->assertSame('upload', $pruefer->fehler('', 0, UPLOAD_ERR_NO_FILE));
		$this->assertSame('leer', (new UploadPruefer(100))->fehler($pfad, 0, UPLOAD_ERR_OK));
	}

	/**
	 * Eine Datei genau in Höchstgröße wird angenommen, ein Byte mehr nicht.
	 *
	 * Die Höchstgröße ist eine einschließlich gemeinte Grenze. Der Test nagelt
	 * den Vergleich „größer als“ fest — ein „größer oder gleich“ würde Dateien
	 * in genau der erlaubten Größe zu Unrecht abweisen.
	 */
	public function testDateiGenauInHoechstgroesse(): void
	{
		$pfad = Testdateien::pdf();
		$groesse = (int) filesize($pfad);

		$this->assertNull((new UploadPruefer($groesse))->fehler($pfad, $groesse, UPLOAD_ERR_OK));
		$this->assertSame('zuGross', (new UploadPruefer($groesse - 1))->fehler($pfad, $groesse, UPLOAD_ERR_OK));
	}

	/**
	 * Der PHP-Fehlercode UPLOAD_ERR_FORM_SIZE (MAX_FILE_SIZE des Formulars) gilt als „zu groß“.
	 *
	 * Wie bei UPLOAD_ERR_INI_SIZE liegt dann keine brauchbare Datei vor; die
	 * Prüfung beendet sich vor dem Zugriff auf den Pfad.
	 */
	public function testFormularGroesseGiltAlsZuGross(): void
	{
		$this->assertSame('zuGross', (new UploadPruefer(1024 * 1024))->fehler('', 0, UPLOAD_ERR_FORM_SIZE));
	}

	/**
	 * Die kleinste Grenze aus Parameter und php.ini gewinnt.
	 */
	public function testWirksameGroesse(): void
	{
		$this->assertSame(10 * 1024 * 1024, UploadPruefer::wirksameGroesse(10, '40M', '0'));
		$this->assertSame(2 * 1024 * 1024, UploadPruefer::wirksameGroesse(10, '2M', '8M'));
		$this->assertSame(512 * 1024, UploadPruefer::wirksameGroesse(10, '40M', '512K'));
		$this->assertSame(10 * 1024 * 1024, UploadPruefer::wirksameGroesse(10, '1G', ''));
	}
}
