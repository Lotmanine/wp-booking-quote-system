<?php

namespace App\Services;

class WordPressSigner
{
    /**
     * Build the signed envelope from PLAN.md section 5.
     *
     * @return array{headers: array<string, string>, body: array{payload: string}}
     */
    public function sign(string $payload): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $mac = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$payload, (string) config('dispatch.hmac_secret'));

        return [
            'headers' => [
                'X-QHS-Key-Id' => 'dispatch',
                'X-QHS-Timestamp' => $timestamp,
                'X-QHS-Nonce' => $nonce,
                'X-QHS-Signature' => 'v1='.$mac,
            ],
            'body' => ['payload' => $payload],
        ];
    }
}
