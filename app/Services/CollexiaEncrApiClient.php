<?php

namespace App\Services;

/**
 * Collexia EnCr (Enhanced Credit) JSON REST API client, per "EnCr JSON REST API
 * Interface Specification V3.00" (22 July 2025): the two endpoints
 * POST /api/coswitchuadsrest/v3/encr/transfer (7.1) and /encr/info (7.2).
 *
 * Same split as CollexiaEndoApiClient: HTTP transport, Basic Auth and the
 * CX_SWITCH_* signing headers all live in CollexiaClient, so this class only
 * builds the JSON bodies. It never decides WHEN money is sent -- that is
 * CollexiaEncrDisbursementService, behind the EnCr toggle.
 *
 * A successful transfer returns 200/204 with NO body (spec 7.1): the call only
 * proves Collexia captured the request. Whether the money actually left is only
 * known from transferInfo() (spec 4.1 step 3).
 */
class CollexiaEncrApiClient
{
    private CollexiaClient $client;

    public function __construct(?CollexiaClient $client = null)
    {
        $this->client = $client ?? new CollexiaClient();
    }

    public function lastDebug(): ?array
    {
        return $this->client->lastDebug();
    }

    /**
     * 7.1 EnCr Transfer -- POST /encr/transfer. $p keys: remoteReferenceNo, amount,
     * toAccountNo, toBanId, toAccountType, ownStatementReference,
     * beneficiaryReference, fromAccountNo, fromBanId, fromAccountType,
     * fromGlobalId (optional), uacId, transferAlreadyAuthed, frontEndUserName.
     */
    public function transfer(array $p): array
    {
        return $this->client->post('/encr/transfer', self::transferBody($p, $this->envelope()));
    }

    /** 7.2 EnCr Transfer Information -- POST /encr/info. */
    public function transferInfo(string $remoteReferenceNo, string $frontEndUserName, int $uacId): array
    {
        return $this->client->post('/encr/info', self::infoBody($remoteReferenceNo, $frontEndUserName, $uacId, $this->envelope()));
    }

    /** The per-call fields every EnCr body carries: gid, system user and the SAST message date/time. */
    private function envelope(): array
    {
        $sast = CollexiaClient::sastDateTimeParts();
        return [
            'remoteGid' => $this->client->configInt('remote_gid'),
            'systemUserName' => $this->client->config('system_username'),
            'messageDate' => $sast['date'],
            'messageTime' => $sast['time'],
        ];
    }

    /**
     * Spec 9.1. A pure function of its arguments so the exact JSON can be pinned
     * in a test. Types follow the spec's own example: GIDs, bank ids, account
     * types and uacId are JSON numbers, account numbers are strings, the amount a
     * decimal, and contractReference is sent as "" exactly as Collexia's sample does.
     */
    public static function transferBody(array $p, array $envelope): array
    {
        $body = [
            'remoteGid' => (int) $envelope['remoteGid'],
            'remoteReferenceNo' => (string) $p['remoteReferenceNo'],
            'messageDate' => $envelope['messageDate'],
            'messageTime' => $envelope['messageTime'],
        ];
        if (!empty($p['fromGlobalId'])) {
            $body['fromGlobalId'] = (int) $p['fromGlobalId'];
        }
        $body += [
            'fromAccountNo' => (string) $p['fromAccountNo'],
            'fromAccountType' => (int) $p['fromAccountType'],
            'fromBanId' => (int) $p['fromBanId'],
            'systemUserName' => (string) $envelope['systemUserName'],
            'frontEndUserName' => (string) $p['frontEndUserName'],
            'transactionAmount' => round((float) $p['amount'], 2),
            'ownStatementReference' => (string) $p['ownStatementReference'],
            'beneficiaryReference' => (string) $p['beneficiaryReference'],
            'contractReference' => '',
            'toAccountNo' => (string) $p['toAccountNo'],
            'toBanId' => (int) $p['toBanId'],
            'toAccountType' => (int) $p['toAccountType'],
            'transferAlreadyAuthed' => (bool) $p['transferAlreadyAuthed'],
            'uacId' => (int) $p['uacId'],
        ];

        return $body;
    }

    /** Spec 9.2. */
    public static function infoBody(string $remoteReferenceNo, string $frontEndUserName, int $uacId, array $envelope): array
    {
        return [
            'remoteReferenceNo' => $remoteReferenceNo,
            'systemUserName' => (string) $envelope['systemUserName'],
            'frontEndUserName' => $frontEndUserName,
            'remoteGid' => (int) $envelope['remoteGid'],
            'messageDate' => $envelope['messageDate'],
            'messageTime' => $envelope['messageTime'],
            'uacId' => $uacId,
        ];
    }
}
