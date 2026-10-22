<?php

namespace Tests\Unit;

use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\Loan;
use App\Services\CollexiaApiException;
use App\Services\CollexiaEncrApiClient;
use App\Services\CollexiaEncrDisbursementService;
use App\Support\CollexiaV3Codes;
use PHPUnit\Framework\TestCase;

/**
 * EnCr loan payout (optional, behind a switch). Mocks stand in for the database and for
 * Collexia, so none of this touches a real account or sends anything.
 */
class CollexiaEncrDisbursementTest extends TestCase
{
    // --- Collexia's bank / account-type translation ---

    public function testBankNamesMapToCollexiaIds(): void
    {
        $this->assertSame(65, CollexiaV3Codes::banIdFromName('FNB'));
        $this->assertSame(65, CollexiaV3Codes::banIdFromName('First National Bank'));
        $this->assertSame(64, CollexiaV3Codes::banIdFromName('  bank windhoek '));
        $this->assertSame(72, CollexiaV3Codes::banIdFromName('Standard Bank Namibia'));
        $this->assertSame(81, CollexiaV3Codes::banIdFromName('NamPost'));
    }

    public function testUnknownBankIsNotGuessed(): void
    {
        $this->assertNull(CollexiaV3Codes::banIdFromName('Capitec'));
        $this->assertNull(CollexiaV3Codes::banIdFromName(''));
        $this->assertNull(CollexiaV3Codes::banIdFromName('Bank'));
    }

    public function testAccountTypeLabels(): void
    {
        $this->assertSame(1, CollexiaV3Codes::accountTypeFromLabel('Cheque'));
        $this->assertSame(1, CollexiaV3Codes::accountTypeFromLabel('Current'));
        $this->assertSame(2, CollexiaV3Codes::accountTypeFromLabel('Savings'));
        $this->assertNull(CollexiaV3Codes::accountTypeFromLabel(''));
        $this->assertNull(CollexiaV3Codes::accountTypeFromLabel('Transmission'));
    }

    // --- references ---

    public function testReferenceIs14AlphanumericCharsAndDiffersEachTime(): void
    {
        $a = CollexiaEncrDisbursementService::newReference(8);
        $b = CollexiaEncrDisbursementService::newReference(8);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{14}$/', $a);
        $this->assertStringStartsWith('ER000008', $a);
        $this->assertNotSame($a, $b);
    }

    public function testStatementReferenceKeepsLettersDigitsSpacesOnlyAndIs40Max(): void
    {
        $this->assertSame('Loan LN261021475BFB', CollexiaEncrDisbursementService::statementReference('Loan LN-261021-475BFB'));
        $this->assertSame(40, strlen(CollexiaEncrDisbursementService::statementReference(str_repeat('A', 80))));
    }

    public function testCollexiaStatusMapsToOurState(): void
    {
        $this->assertSame('Completed', CollexiaEncrDisbursementService::stateFromStatus(3));
        foreach ([4, 5, 7] as $failed) {
            $this->assertSame('Failed', CollexiaEncrDisbursementService::stateFromStatus($failed));
        }
        foreach ([1, 2, 6] as $inFlight) {
            $this->assertSame('Submitted', CollexiaEncrDisbursementService::stateFromStatus($inFlight));
        }
    }

    // --- the exact bodies sent ---

    private function envelope(): array
    {
        return ['remoteGid' => 39, 'systemUserName' => 'cxuser', 'messageDate' => '20260622', 'messageTime' => '144230'];
    }

    private function params(array $over = []): array
    {
        return $over + [
            'remoteReferenceNo' => 'ER000008ABC123', 'amount' => 10000, 'toAccountNo' => '634722174', 'toBanId' => 65, 'toAccountType' => 1,
            'ownStatementReference' => 'Loan LN1', 'beneficiaryReference' => 'Loan disbursement LN1',
            'fromAccountNo' => '123456', 'fromBanId' => 64, 'fromAccountType' => 1, 'fromGlobalId' => null,
            'uacId' => 45, 'transferAlreadyAuthed' => true, 'frontEndUserName' => 'willemk',
        ];
    }

    public function testTransferBodyMatchesTheSpecExample(): void
    {
        $body = CollexiaEncrApiClient::transferBody($this->params(['fromGlobalId' => 18113]), $this->envelope());
        $json = json_decode(json_encode($body), true);

        $this->assertSame(39, $json['remoteGid']);
        $this->assertSame('ER000008ABC123', $json['remoteReferenceNo']);
        $this->assertSame(18113, $json['fromGlobalId']);
        $this->assertSame('123456', $json['fromAccountNo']);
        $this->assertSame('634722174', $json['toAccountNo']);   // a string, as the spec's own sample has it
        $this->assertSame(65, $json['toBanId']);                // numbers, not strings
        $this->assertSame(10000.0, (float) $json['transactionAmount']);
        $this->assertSame('', $json['contractReference']);      // sent as "" exactly like Collexia's sample
        $this->assertTrue($json['transferAlreadyAuthed']);
        $this->assertSame(45, $json['uacId']);
        $this->assertSame('willemk', $json['frontEndUserName']);
    }

    public function testFromGlobalIdIsLeftOutWhenNotProvisioned(): void
    {
        $body = CollexiaEncrApiClient::transferBody($this->params(), $this->envelope());
        $this->assertArrayNotHasKey('fromGlobalId', $body);
    }

    public function testInfoBodyMatchesTheSpecExample(): void
    {
        $body = CollexiaEncrApiClient::infoBody('ER000008ABC123', 'willemk', 45, $this->envelope());
        $this->assertSame(
            ['remoteReferenceNo', 'systemUserName', 'frontEndUserName', 'remoteGid', 'messageDate', 'messageTime', 'uacId'],
            array_keys($body)
        );
        $this->assertSame(45, $body['uacId']);
    }

    // --- sending ---

    private function disbursement(array $over = []): array
    {
        return $over + ['id' => 5, 'loan_id' => 8, 'borrower_id' => 4, 'amount' => '10000.00', 'encr_reference_no' => 'ER000008ABC123', 'encr_state' => 'Not Sent'];
    }

    /** @return array{0: CollexiaEncrDisbursementService, 1: \PHPUnit\Framework\MockObject\MockObject, 2: \PHPUnit\Framework\MockObject\MockObject} */
    private function service(array $disbursement, bool $encrOn = true, ?array $bank = null): array
    {
        $settings = $this->createMock(CollexiaSetting::class);
        $settings->method('encrEnabled')->willReturn($encrOn);
        $settings->method('encrFromGlobalId')->willReturn(null);
        $settings->method('encrTransferAlreadyAuthed')->willReturn(true);
        $settings->method('get')->willReturnCallback(fn ($k) => ['collexia_encr_from_account_no' => '123456', 'collexia_encr_from_ban_id' => '64', 'collexia_encr_from_account_type' => '1', 'collexia_encr_uac_id' => '45'][$k] ?? '');

        $loans = $this->createMock(Loan::class);
        $loans->method('disbursementById')->willReturn($disbursement);
        $loans->method('find')->willReturn(['loan_no' => 'LN-261021-475BFB']);

        $borrowers = $this->createMock(Borrower::class);
        $borrowers->method('bankDetails')->willReturn($bank ?? ['bank_name' => 'FNB', 'account_number' => '634 722 174', 'account_type' => 'Savings']);

        $api = $this->createMock(CollexiaEncrApiClient::class);

        return [new CollexiaEncrDisbursementService($settings, $api, $loans, $borrowers), $api, $loans];
    }

    public function testSuccessfulSendIsSubmittedNotPaid(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->expects($this->once())->method('transfer')->with($this->callback(function (array $p) {
            return $p['remoteReferenceNo'] === 'ER000008ABC123' && $p['toAccountNo'] === '634722174' && $p['toBanId'] === 65 && $p['toAccountType'] === 2
                && $p['ownStatementReference'] === 'Loan LN261021475BFB' && abs($p['amount'] - 10000.0) < 0.001;
        }))->willReturn([]);
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Submitted'));

        $this->assertTrue($service->send(5, 'willemk')['ok']);
    }

    public function testAnAlreadySentPayoutIsNeverSentAgain(): void
    {
        [$service, $api] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->expects($this->never())->method('transfer');
        $this->assertFalse($service->send(5, 'willemk')['ok']);
    }

    public function testNothingIsSentWhileEnCrIsSwitchedOff(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(), false);
        $api->expects($this->never())->method('transfer');
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent'));
        $this->assertFalse($service->send(5, 'willemk')['ok']);
    }

    public function testAPayeeThatCannotBeResolvedSendsNothing(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(), true, ['bank_name' => 'Capitec', 'account_number' => '1', 'account_type' => 'Savings']);
        $api->expects($this->never())->method('transfer');
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent'));
        $this->assertFalse($service->send(5, 'willemk')['ok']);
    }

    public function testRefusalLeavesItRetryableWithTheSameReference(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 1001, 'code' => '1001', 'message' => 'Account Invalid']], 400, 'Transaction Failed'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent' && !array_key_exists('encr_reference_no', $f)));

        $result = $service->send(5, 'willemk');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Account Invalid', $result['message']);
    }

    public function testADuplicateReferenceMeansAlreadyReceivedNotAFailure(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 10626, 'code' => '10626', 'message' => 'Duplicate Transfer Reference']], 400, 'Transaction Failed'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Submitted'));

        $this->assertTrue($service->send(5, 'willemk')['ok']);
    }

    public function testANetworkFailureIsSafeToRetry(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new \RuntimeException('Failed to reach the Collexia API: timeout'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent'));

        $result = $service->send(5, 'willemk');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot be paid twice', $result['message']);
    }

    // --- checking ---

    public function testOnlyCompletedMeansPaid(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->method('transferInfo')->willReturn(['status' => 3, 'amountPaidOut' => 10000.00, 'responseCode' => '0', 'responseDescription' => 'Transaction Successful']);
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Completed' && $f['encr_status_code'] === 3));

        $this->assertTrue($service->checkStatus(5, 'willemk')['ok']);
    }

    public function testADeclinedPayoutIsFlaggedAsNotPaid(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->method('transferInfo')->willReturn(['status' => 7, 'amountPaidOut' => 0, 'responseCode' => '48', 'responseDescription' => 'Account no fails CDV routine']);
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Failed'));

        $result = $service->checkStatus(5, 'willemk');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('did NOT leave', $result['message']);
    }

    public function testACompletedPayoutForTheWrongAmountIsWarnedAbout(): void
    {
        [$service, $api] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->method('transferInfo')->willReturn(['status' => 3, 'amountPaidOut' => 9000.00, 'responseCode' => '0', 'responseDescription' => 'ok']);

        $this->assertStringContainsString('WARNING', $service->checkStatus(5, 'willemk')['message']);
    }

    public function testStatusCannotBeCheckedBeforeItWasSent(): void
    {
        [$service, $api] = $this->service($this->disbursement());
        $api->expects($this->never())->method('transferInfo');
        $this->assertFalse($service->checkStatus(5, 'willemk')['ok']);
    }
}
