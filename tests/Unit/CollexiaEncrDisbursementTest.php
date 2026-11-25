<?php

namespace Tests\Unit;

use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\Loan;
use App\Services\CollexiaApiException;
use App\Services\CollexiaEncrApiClient;
use App\Services\CollexiaEncrDisbursementService;
use App\Services\LoanReleaseService;
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
        return $over + ['id' => 5, 'loan_id' => 8, 'borrower_id' => 4, 'amount' => '10000.00', 'status' => 'Pending', 'encr_reference_no' => 'ER000008ABC123', 'encr_state' => 'Not Sent', 'encr_maybe_received' => 0];
    }

    /** @return array{0: CollexiaEncrDisbursementService, 1: \PHPUnit\Framework\MockObject\MockObject, 2: \PHPUnit\Framework\MockObject\MockObject} */
    private function service(array $disbursement, bool $encrOn = true, ?array $bank = null, array $opts = []): array
    {
        $settings = $this->createMock(CollexiaSetting::class);
        $settings->method('encrEnabled')->willReturn($encrOn);
        $settings->method('encrFromGlobalId')->willReturn(null);
        $settings->method('encrTransferAlreadyAuthed')->willReturn(true);
        $settings->method('get')->willReturnCallback(fn ($k) => ['collexia_encr_from_account_no' => '123456', 'collexia_encr_from_ban_id' => '64', 'collexia_encr_from_account_type' => '1', 'collexia_encr_uac_id' => '45'][$k] ?? '');

        $loans = $this->createMock(Loan::class);
        $loans->method('disbursementById')->willReturn($disbursement);
        $loans->method('find')->willReturn(['loan_no' => 'LN-261021-475BFB']);
        // the send is claimed under a row lock before it goes out, and its outcome written under one (no database here)
        if (empty($opts['ownClaim'])) {
            $loans->method('claimEncrSend')->willReturn(array_key_exists('claimed', $opts) ? $opts['claimed'] : $disbursement);
        }
        $loans->method('disbursementForUpdate')->willReturn($opts['locked'] ?? $disbursement);
        $loans->method('transaction')->willReturnCallback(fn (callable $fn) => $fn(null));

        $borrowers = $this->createMock(Borrower::class);
        $borrowers->method('bankDetails')->willReturn($bank ?? ['bank_name' => 'FNB', 'account_number' => '634 722 174', 'account_type' => 'Savings']);

        $api = $this->createMock(CollexiaEncrApiClient::class);
        $release = $this->createMock(LoanReleaseService::class);

        return [new CollexiaEncrDisbursementService($settings, $api, $loans, $borrowers, $release), $api, $loans, $release];
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

    public function testPayingTheClientFromTheirOwnAccountIsRefusedBeforeSending(): void
    {
        // Same number as the paying account (123456, bank id 64 = Bank Windhoek), written with spaces and a leading zero.
        [$service, $api, $loans] = $this->service($this->disbursement(), true, ['bank_name' => 'Bank Windhoek', 'account_number' => '0123 456', 'account_type' => 'Savings']);
        $api->expects($this->never())->method('transfer');
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(
            fn ($f) => $f['encr_state'] === 'Not Sent' && str_contains($f['encr_last_message'], 'same account the payout is paid FROM')
        ));
        $r = $service->send(5, 'willemk');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Not sent to Collexia', $r['message']);
        $this->assertStringNotContainsString('may not have reached', $r['message']);
    }

    public function testTheSameNumberAtAnotherBankIsAllowed(): void
    {
        [$service, $api] = $this->service($this->disbursement(), true, ['bank_name' => 'FNB', 'account_number' => '123456', 'account_type' => 'Savings']);
        $api->expects($this->once())->method('transfer')->willReturn([]);
        $this->assertTrue($service->send(5, 'willemk')['ok']);
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

    // --- waiting for Collexia: what is posted, and when ---

    public function testTheSwitchOffMeansNoEnCrSoTheReleaseIsManual(): void
    {
        $settings = $this->createMock(CollexiaSetting::class);
        $settings->method('encrEnabled')->willReturn(false);
        $service = new CollexiaEncrDisbursementService($settings, $this->createMock(CollexiaEncrApiClient::class), $this->createMock(Loan::class), $this->createMock(Borrower::class), $this->createMock(LoanReleaseService::class));
        $this->assertFalse($service->available(), 'with EnCr off the Release form offers no EnCr option and LoanController refuses pay_via_encr');
    }

    public function testCompletedHandsTheLoanToTheFinaliserWithCollexiasDate(): void
    {
        [$service, $api, , $release] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->method('transferInfo')->willReturn(['status' => 3, 'amountPaidOut' => 10000.00, 'responseDescription' => 'ok', 'statusDate' => '20261124']);
        $release->expects($this->once())->method('finaliseEncrPayout')->with(5, 9, '2026-11-24')
            ->willReturn(['finalised' => true, 'error' => false, 'message' => 'The loan is now released and posted.']);

        $r = $service->checkStatus(5, 'willemk', 9);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['finalised']);
    }

    public function testAPostingFailureAfterCompletedIsReportedNotHidden(): void
    {
        [$service, $api, , $release] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
        $api->method('transferInfo')->willReturn(['status' => 3, 'amountPaidOut' => 10000.00]);
        $release->method('finaliseEncrPayout')->willReturn(['finalised' => false, 'error' => true, 'message' => 'posting it failed: period closed']);

        $r = $service->checkStatus(5, 'willemk', 9);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('period closed', $r['message']);
    }

    public function testNothingButCompletedIsFinalised(): void
    {
        foreach ([1, 2, 6, 4, 5, 7] as $status) {
            [$service, $api, , $release] = $this->service($this->disbursement(['encr_state' => 'Submitted']));
            $api->method('transferInfo')->willReturn(['status' => $status]);
            $release->expects($this->never())->method('finaliseEncrPayout');
            $service->checkStatus(5, 'willemk', 9);
        }
    }

    public function testAnOldPayoutPostedAtReleaseIsNotPostedAgain(): void
    {
        [$service, $api, , $release] = $this->service($this->disbursement(['encr_state' => 'Submitted', 'status' => 'Disbursed']));
        $api->method('transferInfo')->willReturn(['status' => 3, 'amountPaidOut' => 10000.00]);
        $release->expects($this->never())->method('finaliseEncrPayout');
        $this->assertTrue($service->checkStatus(5, 'willemk', 9)['ok']);
    }

    public function testAServerErrorOnSendIsNotAClearNo(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 192, 'code' => '192', 'message' => 'Internal server error']], 500, 'Error'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent' && $f['encr_maybe_received'] === 1));
        $this->assertFalse($service->send(5, 'willemk')['ok']);
    }

    public function testANetworkFailureMayHaveReachedCollexiaButASwitchedOffClientSentNothing(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new \RuntimeException('Failed to reach the Collexia API: timeout'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_maybe_received'] === 1));
        $service->send(5, 'willemk');

        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new \RuntimeException('The Collexia API integration is disabled (Collections > Debit Order API Settings).'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_maybe_received'] === 0));
        $service->send(5, 'willemk');
    }

    public function testAPayeeRefusalKeepsWhatWasKnownAboutAnEarlierUnclearSend(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_maybe_received' => 1]), true, ['bank_name' => 'Capitec', 'account_number' => '1', 'account_type' => 'Savings']);
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => !array_key_exists('encr_maybe_received', $f)));
        $service->send(5, 'willemk');
    }

    public function testAPayoutIsClaimedBeforeItGoesOutAndNotSentWhenTheClaimIsRefused(): void
    {
        // Claim refused: set aside (paid another way / cancelled) or already moved on since the page was loaded.
        [$service, $api, $loans] = $this->service($this->disbursement(), true, null, ['claimed' => null]);
        $api->expects($this->never())->method('transfer');
        $loans->expects($this->never())->method('updateDisbursementFields');
        $r = $service->send(5, 'willemk');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no longer waiting to be sent', $r['message']);

        // Claim granted: it is taken BEFORE the request goes out.
        [$service, $api, $loans] = $this->service($this->disbursement(), true, null, ['ownClaim' => true]);
        $order = [];
        $loans->expects($this->once())->method('claimEncrSend')->with(5)->willReturnCallback(function () use (&$order) {
            $order[] = 'claim';
            return $this->disbursement();
        });
        $api->expects($this->once())->method('transfer')->willReturnCallback(function () use (&$order) {
            $order[] = 'transfer';
            return [];
        });
        $this->assertTrue($service->send(5, 'willemk')['ok']);
        $this->assertSame(['claim', 'transfer'], $order);
    }

    public function testAClearRefusalOfARetryKeepsTheDoubtFromAnEarlierUnclearSend(): void
    {
        // The first attempt got a 5xx (flag set); the retry is refused with a validation error, not the duplicate code.
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_maybe_received' => 1]));
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 1001, 'code' => '1001', 'message' => 'Account Invalid']], 400, 'Transaction Failed'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_state'] === 'Not Sent' && $f['encr_maybe_received'] === 1));
        $r = $service->send(5, 'willemk');
        $this->assertStringContainsString('Check payout status', $r['message']);
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned($this->disbursement(['encr_maybe_received' => 1])));

        // A request our own client refused before sending does not clear it either.
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_maybe_received' => 1]));
        $api->method('transfer')->willThrowException(new \RuntimeException('The Collexia API integration is disabled (Collections > Debit Order API Settings).'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_maybe_received'] === 1));
        $service->send(5, 'willemk');

        // With no earlier doubt, a clear refusal is a clear "not received".
        [$service, $api, $loans] = $this->service($this->disbursement());
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 1001, 'code' => '1001', 'message' => 'Account Invalid']], 400, 'Transaction Failed'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_maybe_received'] === 0));
        $service->send(5, 'willemk');
    }

    public function testARefusedAnswerIsNeverWrittenOverAPayoutSetAsideMeanwhile(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(), true, null, ['locked' => $this->disbursement(['status' => 'Cancelled'])]);
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 1001, 'code' => '1001', 'message' => 'Account Invalid']], 400, 'Transaction Failed'));
        $loans->expects($this->never())->method('updateDisbursementFields');
        $r = $service->send(5, 'willemk');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('set aside', $r['message']);
    }

    public function testAfterAnUnclearSendTheStatusCanBeCheckedAndANotFoundSettlesIt(): void
    {
        [$service, $api, $loans] = $this->service($this->disbursement(['encr_maybe_received' => 1]));
        $api->expects($this->once())->method('transferInfo')->willThrowException(new CollexiaApiException([['code' => '404', 'message' => 'Transfer not found']], 404, 'Not found'));
        $loans->expects($this->once())->method('updateDisbursementFields')->with(5, $this->callback(fn ($f) => $f['encr_maybe_received'] === 0));
        $r = $service->checkStatus(5, 'willemk', 9);
        $this->assertStringContainsString('never reached it', $r['message']);
    }

    public function testASetAsidePayoutIsNeverSentOrChecked(): void
    {
        [$service, $api] = $this->service($this->disbursement(['status' => 'Cancelled']));
        $api->expects($this->never())->method('transfer');
        $api->expects($this->never())->method('transferInfo');
        $this->assertFalse($service->send(5, 'willemk')['ok']);
        $this->assertFalse($service->checkStatus(5, 'willemk')['ok']);
    }

    public function testPayingAnotherWayOrCancellingIsOnlyAllowedWhenCollexiaWillCertainlyNotPay(): void
    {
        $d = $this->disbursement();
        $this->assertNull(LoanReleaseService::whyPayoutCannotBeAbandoned($d), 'not sent, clear answer');
        $this->assertNull(LoanReleaseService::whyPayoutCannotBeAbandoned(['encr_state' => 'Failed'] + $d));
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned(['encr_state' => 'Submitted'] + $d));
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned(['encr_state' => 'Completed'] + $d));
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned(['encr_maybe_received' => 1] + $d), 'last send unclear');
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned(['encr_state' => 'Weird'] + $d));
        $this->assertNotNull(LoanReleaseService::whyPayoutCannotBeAbandoned(null));
    }

    public function testThePaidOnDateIsCollexiasWithinTheReleaseAndToday(): void
    {
        $today = date('Y-m-d');
        $this->assertSame('2026-11-24', CollexiaEncrDisbursementService::dateFromCollexia('20261124'));
        $this->assertNull(CollexiaEncrDisbursementService::dateFromCollexia('20261331'));
        $this->assertNull(CollexiaEncrDisbursementService::dateFromCollexia(null));
        $this->assertSame($today, LoanReleaseService::paidOnDate(null, '2020-01-01'));
        $this->assertSame('2020-01-05', LoanReleaseService::paidOnDate('2020-01-05', '2020-01-01'));
        $this->assertSame('2020-01-01', LoanReleaseService::paidOnDate('2019-12-31', '2020-01-01'), 'never before the release');
        $this->assertSame($today, LoanReleaseService::paidOnDate('2999-01-01', '2020-01-01'), 'never in the future');
    }
}
