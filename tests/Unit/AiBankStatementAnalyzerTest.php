<?php

namespace Tests\Unit;

use App\Services\AiBankStatementAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Incident 2026-11-09: a bank statement uploaded as a JPG made the AI analysis fail on a live application with OpenAI's
 * "Invalid file data ... unsupported MIME type 'image/jpeg'", because every upload was sent as a FILE. A photo or screenshot has to be sent as an
 * IMAGE; only a PDF is a file.
 */
class AiBankStatementAnalyzerTest extends TestCase
{
    public function testAPdfTravelsAsAFileWithItsNameAndBase64Data(): void
    {
        $bytes = "%PDF-1.4\nnot a real pdf, the bytes do not matter\n";
        $part = AiBankStatementAnalyzer::contentPart('pdf', $bytes, 'March statement');

        $this->assertSame('input_file', $part['type']);
        $this->assertSame('March statement', $part['filename']);
        $this->assertSame('data:application/pdf;base64,' . base64_encode($bytes), $part['file_data']);
        $this->assertArrayNotHasKey('image_url', $part);
    }

    /** @dataProvider imageExtensions */
    public function testAPhotoOrScreenshotTravelsAsAnImageNeverAsAFile(string $extension, string $mime): void
    {
        $bytes = "\xFF\xD8\xFF\xE0 not really an image, the bytes do not matter";
        $part = AiBankStatementAnalyzer::contentPart($extension, $bytes, 'statement photo');

        $this->assertSame('input_image', $part['type'], 'OpenAI refuses an image sent as input_file ("unsupported MIME type")');
        $this->assertSame('data:' . $mime . ';base64,' . base64_encode($bytes), $part['image_url']);
        $this->assertSame('high', $part['detail']);
        $this->assertArrayNotHasKey('file_data', $part);
        $this->assertArrayNotHasKey('filename', $part);
    }

    public static function imageExtensions(): array
    {
        return [
            'jpg' => ['jpg', 'image/jpeg'],
            'jpeg' => ['jpeg', 'image/jpeg'],
            'JPG in capitals' => ['JPG', 'image/jpeg'],
            'png' => ['png', 'image/png'],
            'PNG in capitals' => ['PNG', 'image/png'],
        ];
    }

    public function testTypesThatCannotBeReadAreLeftOut(): void
    {
        foreach (['csv', 'docx', 'xlsx', 'heic', 'txt', ''] as $extension) {
            $this->assertNull(AiBankStatementAnalyzer::contentPart($extension, 'x', 'file'), "'$extension' must not be sent to the AI");
        }
    }

    public function testTheRequestBodyCarriesEachKindInItsOwnShape(): void
    {
        $content = array_merge(
            [['type' => 'input_text', 'text' => 'analyse']],
            [AiBankStatementAnalyzer::contentPart('pdf', 'pdf-bytes', 'a.pdf'), AiBankStatementAnalyzer::contentPart('jpg', 'jpg-bytes', 'b.jpg')]
        );
        $json = json_encode(['input' => [['role' => 'user', 'content' => $content]]]);
        $decoded = json_decode($json, true)['input'][0]['content'];

        $this->assertSame(['input_text', 'input_file', 'input_image'], array_column($decoded, 'type'));
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('jpg-bytes'), $decoded[2]['image_url']);
    }
}
