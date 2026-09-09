<?php

namespace Tests\Unit;

use App\Services\CreditinfoApiException;
use App\Services\CreditinfoAuthException;
use App\Services\CreditinfoTimeoutException;
use App\Services\CreditinfoValidationException;
use PHPUnit\Framework\TestCase;

class CreditinfoApiExceptionTest extends TestCase
{
    public function testEachSubclassExtendsTheBaseApiException(): void
    {
        $this->assertInstanceOf(CreditinfoApiException::class, new CreditinfoAuthException('x'));
        $this->assertInstanceOf(CreditinfoApiException::class, new CreditinfoValidationException('x'));
        $this->assertInstanceOf(CreditinfoApiException::class, new CreditinfoTimeoutException('x'));
    }

    public function testCatchingTheBaseClassCatchesEverySubclass(): void
    {
        foreach ([CreditinfoAuthException::class, CreditinfoValidationException::class, CreditinfoTimeoutException::class] as $class) {
            $caught = null;
            try {
                throw new $class('boom');
            } catch (CreditinfoApiException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, "$class must be catchable via the base CreditinfoApiException type.");
        }
    }

    public function testHttpStatusAndRawBodyAreCarriedThroughUnmodified(): void
    {
        $e = new CreditinfoApiException('failed', 400, '{"status":"Error"}');
        $this->assertSame('failed', $e->getMessage());
        $this->assertSame(400, $e->httpStatus());
        $this->assertSame('{"status":"Error"}', $e->rawBody());
    }

    public function testHttpStatusAndRawBodyDefaultToNull(): void
    {
        $e = new CreditinfoApiException('failed');
        $this->assertNull($e->httpStatus());
        $this->assertNull($e->rawBody());
    }
}
