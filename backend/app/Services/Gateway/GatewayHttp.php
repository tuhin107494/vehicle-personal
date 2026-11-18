<?php

namespace App\Services\Gateway;

use App\Models\Gateway\GatewayLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every call a gateway class makes goes through here: a timeout, retries with exponential backoff on connection errors
 * and 5xx answers, and a scrubbed copy of the request and the response in gateway_logs. Hosts come from the saved
 * gateway settings, never from a request.
 */
class GatewayHttp
{
    public const TIMEOUT_SECONDS = 20;

    public const RETRIES = 3;

    public function __construct(private string $gatewayCode, private ?int $shopId = null, private ?int $attemptId = null) {}

    /** @param  array<string, string>  $headers */
    public function post(string $action, string $url, array $body, array $headers = []): Response
    {
        return $this->send($action, fn (PendingRequest $http) => $http->withHeaders($headers)->post($url, $body), ['url' => $url, 'body' => $body, 'headers' => $headers]);
    }

    /** @param  array<string, string>  $headers */
    public function get(string $action, string $url, array $query = [], array $headers = []): Response
    {
        return $this->send($action, fn (PendingRequest $http) => $http->withHeaders($headers)->get($url, $query), ['url' => $url, 'query' => $query, 'headers' => $headers]);
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(string $action, callable $call, array $request): Response
    {
        $this->log('request', $action, $request);

        try {
            // 0.5 s, 1 s, 2 s between tries; only connection failures and server errors are retried, a 4xx is an answer
            $response = $call(Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)
                ->retry(self::RETRIES, fn (int $attempt) => 500 * (2 ** ($attempt - 1)), fn ($e) => ! ($e instanceof RequestException) || $e->response->serverError(), throw: false));
        } catch (\Throwable $e) {
            $this->log('error', $action, ['message' => $e->getMessage()]);
            throw $e;
        }

        $this->log('response', $action, $response->json() ?? ['body' => mb_substr($response->body(), 0, 2000)], $response->status());

        return $response;
    }

    private function log(string $direction, string $action, array $payload, ?int $status = null): void
    {
        GatewayLog::create([
            'shop_id' => $this->shopId, 'attempt_id' => $this->attemptId, 'gateway_code' => $this->gatewayCode,
            'direction' => $direction, 'action' => $action, 'payload' => GatewayLog::scrub($payload), 'http_status' => $status,
        ]);
    }
}
