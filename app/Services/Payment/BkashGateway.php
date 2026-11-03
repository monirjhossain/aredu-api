<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BkashGateway
{
    /**
     * @param  array{
     *     amount: string,
     *     merchant_invoice_number: string,
     *     callback_url: string,
     *     payer_reference: string
     * }  $payload
     * @return array{payment_id: string, gateway_url: string}
     */
    public function createPayment(array $payload): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/tokenized/checkout/create', [
                'mode' => '0011',
                'payerReference' => $payload['payer_reference'],
                'callbackURL' => $payload['callback_url'],
                'amount' => $payload['amount'],
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => $payload['merchant_invoice_number'],
            ]);

        $paymentId = $response->json('paymentID');
        $gatewayUrl = $response->json('bkashURL');
        $statusCode = (string) $response->json('statusCode');

        if (! $response->successful() || $statusCode !== '0000' || ! is_string($paymentId) || $paymentId === '' || ! is_string($gatewayUrl) || $gatewayUrl === '') {
            $message = $response->json('statusMessage');
            throw new HttpException(
                502,
                is_string($message) && $message !== '' ? $message : 'bKash could not create the payment.',
            );
        }

        return [
            'payment_id' => $paymentId,
            'gateway_url' => $gatewayUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executePayment(string $paymentId): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/tokenized/checkout/execute', [
                'paymentID' => $paymentId,
            ]);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new HttpException(502, 'bKash could not execute the payment.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function queryPayment(string $paymentId): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/tokenized/checkout/payment/status', [
                'paymentID' => $paymentId,
            ]);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new HttpException(502, 'bKash could not query the payment.');
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => $this->accessToken(),
            'X-App-Key' => $this->appKey(),
        ];
    }

    private function accessToken(): string
    {
        $cached = Cache::get('bkash.access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$username, $password, $appKey, $appSecret] = $this->credentials();

        $response = Http::withHeaders([
            'username' => $username,
            'password' => $password,
        ])
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/tokenized/checkout/token/grant', [
                'app_key' => $appKey,
                'app_secret' => $appSecret,
            ]);

        $token = $response->json('id_token');
        $statusCode = (string) $response->json('statusCode');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (! $response->successful() || $statusCode !== '0000' || ! is_string($token) || $token === '') {
            throw new HttpException(503, 'bKash is not configured.');
        }

        Cache::put('bkash.access_token', $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    private function appKey(): string
    {
        return $this->credentials()[2];
    }

    private function baseUrl(): string
    {
        $sandbox = (bool) config('services.bkash.sandbox', true);

        return $sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function credentials(): array
    {
        $username = config('services.bkash.username');
        $password = config('services.bkash.password');
        $appKey = config('services.bkash.app_key');
        $appSecret = config('services.bkash.app_secret');

        if (
            ! is_string($username) || $username === ''
            || ! is_string($password) || $password === ''
            || ! is_string($appKey) || $appKey === ''
            || ! is_string($appSecret) || $appSecret === ''
        ) {
            throw new HttpException(503, 'bKash is not configured.');
        }

        return [$username, $password, $appKey, $appSecret];
    }
}
