<?php

namespace App\Services;

/**
 * Tells whether an uploaded PDF has nothing in it: no readable text on any page and no picture either. A bank statement downloaded
 * from a bank always has text; a scan has a picture; a PDF with neither is an empty page the applicant exported or photographed by mistake.
 *
 * It deliberately says "blank" only when it is sure. A file that cannot be opened (damaged, password-protected, odd structure) is NOT
 * reported blank -- the caller keeps its normal handling for those -- and a scan of white paper cannot be told from a real scan without
 * drawing the page, so that case is not caught here.
 */
class BlankPdfDetector
{
    /** Readable characters (letters/digits) below which the text of the whole file counts as empty. */
    private const MIN_TEXT_CHARACTERS = 20;

    public static function isBlank(string $path): bool
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class) || !is_file($path) || filesize($path) === 0) {
            return false;
        }

        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            $pages = $pdf->getPages();
            if ($pages === []) {
                return false;
            }

            $text = (string) $pdf->getText();
            if (preg_match_all('/[\p{L}\p{N}]/u', $text) >= self::MIN_TEXT_CHARACTERS) {
                return false;
            }

            foreach ($pdf->getObjectsByType('XObject') as $object) {
                $subtype = $object->getHeader()->get('Subtype');
                if ($subtype !== null && $subtype->getContent() === 'Image') {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
