<?php

namespace App\Services;

/**
 * Plain {{key}} substitution for notification_templates.message_body --
 * no docx/PhpWord merge needed like DocumentFieldResolver, just a text
 * string. Unknown/unresolved placeholders are left as literal "{{key}}"
 * rather than silently blanked, so staff notice a missing merge field
 * instead of sending a message with a blank gap in it.
 */
class NotificationMergeService
{
    public static function render(string $body, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($context) {
                $key = $matches[1];
                return array_key_exists($key, $context) ? (string) $context[$key] : $matches[0];
            },
            $body
        );
    }
}
