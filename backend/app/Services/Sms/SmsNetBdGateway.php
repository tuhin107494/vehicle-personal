<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** sms.net.bd (Alpha SMS): a form POST with api_key, msg and to; "error" is 0 on success. */
class SmsNetBdGateway implements SmsGateway
{
    public function name(): string
    {
        return 'smsnetbd';
    }

    public function send(string $phone, string $message): string
    {
        $config = $this->config();

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(20)->post($config['url'], array_filter([
                'api_key' => $config['api_key'],
                'msg' => $message,
                'to' => $phone,
                'sender_id' => $config['sender_id'] ?: null,
            ]));
        } catch (ConnectionException) {
            throw new SmsException('sms.net.bd could not be reached.');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['error'] ?? null) !== 0) {
            $why = is_array($body) && is_string($body['msg'] ?? null) ? mb_substr($body['msg'], 0, 200) : 'HTTP '.$response->status();

            throw new SmsException("sms.net.bd refused the SMS: {$why}");
        }

        $id = $body['data']['request_id'] ?? '';

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

        $balance = is_array($body) && ($body['error'] ?? null) === 0 ? ($body['data']['balance'] ?? null) : null;

        return is_numeric($balance) ? (float) $balance : null;
    }

    /** @return array{url:string,balance_url:string,api_key:string,sender_id:?string} */
    private function config(): array
    {
        $config = config('sms.drivers.smsnetbd');

        if (blank($config['api_key'] ?? null)) {
            throw new SmsException('sms.net.bd is not set up: SMSNETBD_API_KEY is missing in .env.');
        }

        return $config;
    }
}
