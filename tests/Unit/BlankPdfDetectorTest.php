<?php

namespace Tests\Unit;

use App\Services\BlankPdfDetector;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;

/** A bank statement PDF with no text and no picture is blank; anything with content, or that cannot be judged, is not. */
class BlankPdfDetectorTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    private function pdf(callable $draw): string
    {
        $pdf = new Fpdi();
        $pdf->AddPage();
        $draw($pdf);
        $path = tempnam(sys_get_temp_dir(), 'blank') . '.pdf';
        $pdf->Output('F', $path);
        $this->files[] = $path;
        return $path;
    }

    public function testAnEmptyPageIsBlank(): void
    {
        $this->assertTrue(BlankPdfDetector::isBlank($this->pdf(static function ($pdf) {
        })));
    }

    public function testAPageWithATitleOnlyIsStillBlankButAStatementIsNot(): void
    {
        $title = $this->pdf(static function ($pdf) {
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, 'Page 1');
        });
        $this->assertTrue(BlankPdfDetector::isBlank($title), 'a few characters are not a statement');

        $statement = $this->pdf(static function ($pdf) {
            $pdf->SetFont('Arial', '', 10);
            foreach (['Statement Period : 30 April 2025 to 31 May 2025', 'Opening Balance 613.37Cr', '03 May Fnb App Transfer From Emily 600.00Cr 613.37Cr', 'Closing Balance 1213.37Cr'] as $line) {
                $pdf->Cell(0, 8, $line, 0, 1);
            }
        });
        $this->assertFalse(BlankPdfDetector::isBlank($statement));
    }

    public function testAScannedPicturePageIsNotBlank(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        imagepng($image, $png);
        $this->files[] = $png;

        $this->assertFalse(BlankPdfDetector::isBlank($this->pdf(static function ($pdf) use ($png) {
            $pdf->Image($png, 10, 10, 100, 100);
        })));
    }

    public function testFilesThatCannotBeJudgedAreNotCalledBlank(): void
    {
        $this->assertFalse(BlankPdfDetector::isBlank('C:/no/such/file.pdf'));

        $junk = tempnam(sys_get_temp_dir(), 'junk') . '.pdf';
        file_put_contents($junk, 'this is not a pdf');
        $this->files[] = $junk;
        $this->assertFalse(BlankPdfDetector::isBlank($junk));
    }
}
