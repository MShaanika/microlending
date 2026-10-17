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
    /** @var array<int, array{rcoId?: int, code?: string, message?: string, detail?: string}> */
    private array $errors;
    private ?int $httpStatus;
    private ?string $summary;

    /**
     * @param array<int, array{rcoId?: int, code?: string, message?: string, detail?: string}> $errors
     *
     * Plain (non-promoted, non-readonly) properties -- this app's local dev
     * PHP is 8.0, which doesn't support readonly promoted properties
     * (8.1+ only); this class failed to even PARSE there (confirmed via
     * php -l), fatally crashing any code path that threw it, though it's
     * fine on sandbox/production (both run 8.1+ -- this exact exception
     * has rendered live there earlier in this app's history).
     */
    public function __construct(array $errors, ?int $httpStatus, ?string $summary)
    {
        $this->errors = $errors;
        $this->httpStatus = $httpStatus;
        $this->summary = $summary;
        $first = $errors[0] ?? [];
        $text = trim(($first['code'] ?? '') . ' ' . ($first['message'] ?? $summary ?? 'Mandate placement API error'));
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
