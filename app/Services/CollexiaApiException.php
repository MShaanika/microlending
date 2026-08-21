<?php

namespace App\Services;

/**
 * Thrown for a Collexia EnDO error response, which is always shaped as
 * {"errors":[{rcoId,code,message,detail}], "status":<int>, "summary":<str>}
 * per the V3 spec's Error Response structure (9.21-9.22). Carries the
 * parsed errors so callers can branch on rcoId/code (e.g. Appendix B)
 * instead of string-matching a flat message.
 */
class CollexiaApiException extends \RuntimeException
{
    /** @param array<int, array{rcoId?: int, code?: string, message?: string, detail?: string}> $errors */
    public function __construct(
        private readonly array $errors,
        private readonly ?int $httpStatus,
        private readonly ?string $summary,
    ) {
        $first = $errors[0] ?? [];
        $text = trim(($first['code'] ?? '') . ' ' . ($first['message'] ?? $summary ?? 'Collexia API error'));
        parent::__construct($text, (int) ($first['rcoId'] ?? 0));
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }
}
