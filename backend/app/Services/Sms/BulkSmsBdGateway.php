<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** BulkSMSBD: a form POST with api_key, senderid, number and message; "response_code" 202 means it was accepted. */
class BulkSmsBdGateway implements SmsGateway
{
    public function name(): string
    {
        return 'bulksmsbd';
    }

    public function send(string $phone, string $message): string
    {
        $config = $this->config();

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(20)->post($config['url'], [
                'api_key' => $config['api_key'],
                'senderid' => $config['sender_id'] ?? '',
                'number' => $phone,
                'message' => $message,
                'type' => 'text',
            ]);
        } catch (ConnectionException) {
            throw new SmsException('BulkSMSBD could not be reached.');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || (int) ($body['response_code'] ?? 0) !== 202) {
            $why = is_array($body) && is_string($body['error_message'] ?? null) ? mb_substr($body['error_message'], 0, 200) : 'code '.(is_array($body) ? ($body['response_code'] ?? $response->status()) : $response->status());

            throw new SmsException("BulkSMSBD refused the SMS: {$why}");
        }

        $id = $body['message_id'] ?? '';

        return is_scalar($id) ? (string) $id : '';
    }

    public function balance(): ?float
    {
        $config = $this->config();

        try {
            $body = Http::connectTimeout(5)->timeout(20)->get($config['balance_url'], ['api_key' => $config['api_key']])->json();
        } catch (ConnectionException) {
            return null;
        }

        $balance = is_array($body) ? ($body['balance'] ?? null) : null;

        return is_numeric($balance) ? (float) $balance : null;
    }

    /** @return array{url:string,balance_url:string,api_key:string,sender_id:?string} */
    private function config(): array
    {
        $config = config('sms.drivers.bulksmsbd');

        if (blank($config['api_key'] ?? null)) {
            throw new SmsException('BulkSMSBD is not set up: BULKSMSBD_API_KEY is missing in .env.');
        }

        return $config;
    }
}
