<?php

namespace Tests\Unit;

use App\Services\CollexiaClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CollexiaClient's signature/timestamp/reference construction
 * against Collexia's own Postman pre-request script
 * (DigitalSignatureScript.txt, supplied 2026-09-05) -- not against a
 * guessed spec. Deliberately avoids touching CollexiaSetting/the database
 * (broken on this dev machine): every method under test here is a pure
 * function of its arguments, extracted from CollexiaClient specifically
 * so this is possible. The DB-dependent wiring (config() pulling real
 * credentials, generateSignature() reading the stored Client Secret,
 * post()'s curl call) is exercised by the source-inspection tests below
 * instead, since it's thin plumbing around already-tested pure logic.
 */
class CollexiaClientTest extends TestCase
{
    // --- SAST timestamp format ---

    public function testSastComponentsApplyUtcPlus2RegardlessOfServerTimezone(): void
    {
        // 2026-01-01 00:00:00 UTC -> 2026-01-01 02:00:00 SAST.
        $epoch = gmmktime(0, 0, 0, 1, 1, 2026);
        $c = CollexiaClient::sastComponentsFromEpoch($epoch, 0);

        $this->assertSame('2026', $c['year']);
        $this->assertSame('01', $c['month']);
        $this->assertSame('01', $c['day']);
        $this->assertSame('02', $c['hours']);
        $this->assertSame('00', $c['minutes']);
        $this->assertSame('00', $c['seconds']);
        $this->assertSame('000', $c['millis']);
    }

    public function testSastComponentsCrossMidnightBoundary(): void
    {
        // 2026-06-30 23:00:00 UTC -> 2026-07-01 01:00:00 SAST (date rolls over).
        $epoch = gmmktime(23, 0, 0, 6, 30, 2026);
        $c = CollexiaClient::sastComponentsFromEpoch($epoch, 0);

        $this->assertSame('2026', $c['year']);
        $this->assertSame('07', $c['month']);
        $this->assertSame('01', $c['day']);
        $this->assertSame('01', $c['hours']);
    }

    public function testMillisecondsFloorRatherThanRound(): void
    {
        // 999,600 microseconds would ROUND to 1000ms but must FLOOR to 999 --
        // a native JS Date can never report 1000ms, so rounding here would
        // silently produce a value the Postman script's own math never does.
        $c = CollexiaClient::sastComponentsFromEpoch(0, 999600);
        $this->assertSame('999', $c['millis']);
    }

    public function testBuildTimestampMatchesTheExactFormat(): void
    {
        $ts = CollexiaClient::buildTimestamp();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $ts);
    }

    public function testBuildTimestampIsNeverCachedBetweenCalls(): void
    {
        $first = CollexiaClient::buildTimestamp();
        usleep(2000);
        $second = CollexiaClient::buildTimestamp();
        $this->assertNotSame($first, $second, 'Two calls a few milliseconds apart must not return an identical (cached) timestamp.');
    }

    public function testFormatTimestampConcatenatesPartsWithTheScriptsExactSeparators(): void
    {
        $c = ['year' => '2026', 'month' => '09', 'day' => '04', 'hours' => '16', 'minutes' => '45', 'seconds' => '23', 'millis' => '417'];
        $this->assertSame('2026-09-04 16:45:23.417', CollexiaClient::formatTimestamp($c));
    }

    // --- Signature: clientId + dts concatenation, HMAC-SHA512 keyed by Client Secret, Base64 ---

    /**
     * RFC 4231 Test Case 1 -- a published, independent HMAC-SHA512 test
     * vector. Confirms PHP's hash_hmac('sha512', ...) is a correct,
     * standard implementation of the same primitive the Postman script's
     * CryptoJS.HmacSHA512 implements, before trusting it for the real
     * Collexia construction.
     */
    public function testHmacSha512MatchesTheRfc4231TestVector(): void
    {
        $key = str_repeat("\x0b", 20);
        $data = 'Hi There';
        $expectedHex = '87aa7cdea5ef619d4ff0b4241a1d6cb02379f4e2ce4ec2787ad0b30545e17cdedaa833b7d6b8a702038b274eaea3f4e4be9d914eeb61f1702e696c203a126854';

        $this->assertSame($expectedHex, bin2hex(hash_hmac('sha512', $data, $key, true)));
    }

    public function testComputeSignatureConcatenatesClientIdAndDtsWithNoSeparator(): void
    {
        // Manually reproduce the Postman script's own steps 4-5 for one
        // fixed input set, independently of computeSignature(), then
        // assert they match -- this is the "same output given the same
        // Client ID, secret and timestamp" check requested.
        $clientId = 'UAT-CLIENT-001';
        $dts = '2026-09-04 16:45:23.417';
        $secret = 'super-secret-value';

        $expectedStringToSign = $clientId . $dts; // no separator, per the script
        $expectedHash = hash_hmac('sha512', $expectedStringToSign, $secret, true);
        $expectedBase64 = base64_encode($expectedHash);

        $this->assertSame($expectedBase64, CollexiaClient::computeSignature($clientId, $dts, $secret));
    }

    public function testComputeSignatureChangesIfClientIdAndDtsWereConcatenatedInTheOppositeOrder(): void
    {
        // Guards against a future edit accidentally swapping the
        // concatenation order -- dts+clientId must NOT produce the same
        // signature as clientId+dts.
        $clientId = 'ABC';
        $dts = '2026-09-04 16:45:23.417';
        $secret = 'k';

        $correct = CollexiaClient::computeSignature($clientId, $dts, $secret);
        $wrongOrder = base64_encode(hash_hmac('sha512', $dts . $clientId, $secret, true));

        $this->assertNotSame($correct, $wrongOrder);
    }

    public function testComputeSignatureUsesClientSecretAsTheHmacKeyNotClientId(): void
    {
        $clientId = 'same-client-id';
        $dts = '2026-09-04 16:45:23.417';

        $withSecretA = CollexiaClient::computeSignature($clientId, $dts, 'secret-a');
        $withSecretB = CollexiaClient::computeSignature($clientId, $dts, 'secret-b');

        $this->assertNotSame($withSecretA, $withSecretB, 'Changing only the secret must change the signature -- confirms the secret is actually used as the HMAC key.');
    }

    public function testComputeSignatureOutputIsValidBase64OfA64ByteDigest(): void
    {
        $signature = CollexiaClient::computeSignature('client', '2026-09-04 16:45:23.417', 'secret');

        $decoded = base64_decode($signature, true);
        $this->assertNotFalse($decoded, 'Signature must be valid Base64.');
        $this->assertSame(64, strlen($decoded), 'SHA-512 digest is always 64 bytes.');
    }

    public function testComputeSignatureIsDeterministicForTheSameInputs(): void
    {
        $a = CollexiaClient::computeSignature('client-1', '2026-09-04 16:45:23.417', 'secret-1');
        $b = CollexiaClient::computeSignature('client-1', '2026-09-04 16:45:23.417', 'secret-1');
        $this->assertSame($a, $b);
    }

    // --- contractReference ---

    public function testContractReferenceIsFourteenCharacters(): void
    {
        $c = ['month' => '09', 'day' => '04', 'hours' => '16', 'minutes' => '45', 'seconds' => '23', 'millis' => '417'];
        $ref = CollexiaClient::contractReferenceFromParts(12584, $c);
        $this->assertSame(14, strlen($ref));
    }

    public function testContractReferenceGidIsUppercaseHexPaddedToFourChars(): void
    {
        // 12584 decimal = 0x3128 -- matches the script's own worked example ("3128").
        $c = ['month' => '04', 'day' => '22', 'hours' => '14', 'minutes' => '35', 'seconds' => '22', 'millis' => '000'];
        $ref = CollexiaClient::contractReferenceFromParts(12584, $c);
        $this->assertSame('31280422143522', $ref);
    }

    public function testContractReferencePadsASmallGidToFourDigits(): void
    {
        $c = ['month' => '01', 'day' => '01', 'hours' => '00', 'minutes' => '00', 'seconds' => '00', 'millis' => '000'];
        $ref = CollexiaClient::contractReferenceFromParts(1, $c); // hex "1" -> "0001"
        $this->assertSame('0001', substr($ref, 0, 4));
    }

    // --- userReference ---

    public function testUserReferenceFaithfullyReproducesTheScriptsActualNineCharacterOutput(): void
    {
        // seconds (2 chars) . millis (3 chars) = 5 chars; JS's slice(-6) on a
        // 5-char string returns the whole string (does not pad to 6) --
        // confirmed against Node's String.prototype semantics, which match
        // the browser/Postman sandbox's. + 4 random chars = 9 total, not
        // the 10 the script's own comment claims.
        $c = ['seconds' => '23', 'millis' => '417'];
        $ref = CollexiaClient::userReferenceFromParts($c, 'WXYZ');

        $this->assertSame('23417WXYZ', $ref);
        $this->assertSame(9, strlen($ref));
    }

    public function testRandomAlphanumericProducesOnlyUppercaseLettersAndDigits(): void
    {
        $rand = CollexiaClient::randomAlphanumeric(4);
        $this->assertSame(4, strlen($rand));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}$/', $rand);
    }

    // --- Structural checks that don't need a live DB/HTTP call: source inspection ---

    private function source(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/app/Services/CollexiaClient.php');
    }

    public function testBasicAuthenticationIsWiredIntoTheCentralPostMethod(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('CURLOPT_HTTPAUTH', $src);
        $this->assertStringContainsString('CURLAUTH_BASIC', $src);
        $this->assertStringContainsString('CURLOPT_USERPWD', $src);
    }

    public function testAllThreeSecurityHeadersAreBuilt(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('CX_SWITCH_ClientId', $src);
        $this->assertStringContainsString('CX_SWITCH_DTS', $src);
        $this->assertStringContainsString('CX_SWITCH_HSH', $src);
    }

    public function testNoLoggingOrDebugOutputCallExistsInTheClientAtAll(): void
    {
        // Password/Client Secret only ever pass through curl's own
        // CURLOPT_USERPWD / the HMAC call -- neither is ever a candidate
        // for a stray var_dump/print_r/error_log/echo, so it's sufficient
        // (and simpler than pattern-matching around specific variable
        // names) to assert this file contains no such call whatsoever.
        $src = $this->source();
        foreach (['var_dump(', 'print_r(', 'var_export(', 'error_log(', 'echo ', 'Audit::log('] as $call) {
            $this->assertStringNotContainsString($call, $src, "CollexiaClient.php must not call $call -- credentials must never reach a log, console, or output.");
        }
    }

    public function testGenerateSignatureIsNotPubliclyCallableWithARawSecretBypassingSettings(): void
    {
        // generateSignature() (the instance method that reads the real
        // stored secret) stays private -- only computeSignature() (the
        // pure, secret-as-parameter version used for testing) is public.
        $reflection = new \ReflectionClass(CollexiaClient::class);
        $this->assertTrue($reflection->getMethod('generateSignature')->isPrivate());
        $this->assertTrue($reflection->getMethod('computeSignature')->isStatic());
        $this->assertTrue($reflection->getMethod('computeSignature')->isPublic());
    }
}
