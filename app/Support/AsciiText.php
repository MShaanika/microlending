<?php

namespace App\Support;

/**
 * Turns any text into plain printable ASCII (space to tilde), because a CPL (Creditinfo) file must be pure ASCII and every record has a fixed width:
 * one multibyte character would make a line longer than its width and shift every later field.
 *
 * Accented letters become their plain letters (e -> e, u with umlaut -> u), typographic quotes and dashes become the plain ones, whatever is left is
 * run through iconv's //TRANSLIT one run at a time and anything that still is not printable ASCII (including the '?' that iconv puts for a character
 * it does not know) is dropped. Control characters and tabs become a space.
 */
final class AsciiText
{
    /** @var array<string,string>|null */
    private static ?array $map = null;

    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = strtr($text, self::map());
        if (preg_match('/[^\x00-\x7F]/', $text)) {
            $text = (string) preg_replace_callback('/[^\x00-\x7F]+/', static function (array $m): string {
                $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $m[0]) : false;
                return is_string($t) ? (string) preg_replace('/[^A-Za-z0-9 .,;:\'"()\/&@#+-]/', '', $t) : '';
            }, $text);
        }
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    }

    /** True when the text is already pure printable ASCII. */
    public static function isPrintableAscii(string $text): bool
    {
        return preg_match('/^[\x20-\x7E]*$/D', $text) === 1;
    }

    /** @return array<string,string> */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $pairs = [
            'a' => ['à', 'á', 'â', 'ã', 'ä', 'å', 'ā', 'ă', 'ą'], 'A' => ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Ā', 'Ă', 'Ą'],
            'e' => ['è', 'é', 'ê', 'ë', 'ē', 'ė', 'ę', 'ě'], 'E' => ['È', 'É', 'Ê', 'Ë', 'Ē', 'Ė', 'Ę', 'Ě'],
            'i' => ['ì', 'í', 'î', 'ï', 'ī', 'į', 'ı'], 'I' => ['Ì', 'Í', 'Î', 'Ï', 'Ī', 'Į', 'İ'],
            'o' => ['ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ō', 'ő'], 'O' => ['Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ō', 'Ő'],
            'u' => ['ù', 'ú', 'û', 'ü', 'ū', 'ů', 'ű', 'ų'], 'U' => ['Ù', 'Ú', 'Û', 'Ü', 'Ū', 'Ů', 'Ű', 'Ų'],
            'c' => ['ç', 'ć', 'č'], 'C' => ['Ç', 'Ć', 'Č'], 'n' => ['ñ', 'ń', 'ň'], 'N' => ['Ñ', 'Ń', 'Ň'],
            's' => ['ś', 'š', 'ş'], 'S' => ['Ś', 'Š', 'Ş'], 'z' => ['ź', 'ż', 'ž'], 'Z' => ['Ź', 'Ż', 'Ž'],
            'y' => ['ý', 'ÿ'], 'Y' => ['Ý', 'Ÿ'], 'l' => ['ł'], 'L' => ['Ł'], 'd' => ['ð', 'ď'], 'D' => ['Ð', 'Ď'],
            'r' => ['ř'], 'R' => ['Ř'], 't' => ['ť'], 'T' => ['Ť'], 'g' => ['ğ'], 'G' => ['Ğ'],
            'ss' => ['ß'], 'ae' => ['æ'], 'AE' => ['Æ'], 'oe' => ['œ'], 'OE' => ['Œ'], 'th' => ['þ'], 'TH' => ['Þ'],
            "'" => ['‘', '’', '‚', '′', '´', '`'], '"' => ['“', '”', '„', '″', '«', '»'],
            '-' => ['–', '—', '‐', '‑', '−'], '...' => ['…'], ' ' => [' ', ' ', ' ', '​'],
            'EUR' => ['€'], 'GBP' => ['£'], '-*' => ['•'], '(c)' => ['©'], '(r)' => ['®'],
        ];
        $map = [];
        foreach ($pairs as $plain => $chars) {
            foreach ($chars as $char) {
                $map[$char] = $plain === '-*' ? '-' : $plain;
            }
        }
        foreach (["\u{00A0}", "\u{2002}", "\u{2003}", "\u{2009}", "\u{202F}"] as $space) {
            $map[$space] = ' ';
        }
        $map["\u{200B}"] = '';
        return self::$map = $map;
    }
}
