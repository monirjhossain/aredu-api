<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SslCommerzGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{gateway_url: string, session_key: string|null}
     */
    public function initiate(array $payload): array
    {
        [$storeId, $storePassword, $base] = $this->credentials();

        $response = Http::asForm()
            ->timeout(20)
            ->post($base.'/gwprocess/v4/api.php', array_merge($payload, [
                'store_id' => $storeId,
                'store_passwd' => $storePassword,
            ]));

        $status = $response->json('status');
        $gatewayUrl = $response->json('GatewayPageURL');

        if (! $response->successful() || $status !== 'SUCCESS' || ! is_string($gatewayUrl) || $gatewayUrl === '') {
            $reason = $response->json('failedreason');
            $message = is_string($reason) && $reason !== ''
                ? $reason
                : 'SSLCommerz could not start the payment.';

            throw new HttpException(502, $message);
        }

        $sessionKey = $response->json('sessionkey');

        return [
            'gateway_url' => $gatewayUrl,
            'session_key' => is_string($sessionKey) ? $sessionKey : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(string $validationId): array
    {
        [$storeId, $storePassword, $base] = $this->credentials();

        $response = Http::timeout(20)->get($base.'/validator/api/validationserverAPI.php', [
            'val_id' => $validationId,
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'format' => 'json',
        ]);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new HttpException(502, 'SSLCommerz could not validate the payment.');
        }

        return $payload;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function credentials(): array
    {
        $storeId = config('services.sslcommerz.store_id');
        $storePassword = config('services.sslcommerz.store_password');
        $sandbox = (bool) config('services.sslcommerz.sandbox', true);

        if (! is_string($storeId) || $storeId === '' || ! is_string($storePassword) || $storePassword === '') {
            throw new HttpException(503, 'SSLCommerz is not configured.');
        }

        $base = $sandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        return [$storeId, $storePassword, $base];
    }
}
