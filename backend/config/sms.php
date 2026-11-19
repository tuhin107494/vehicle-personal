<?php

/*
 * SMS due alerts. The gateway keys live only in .env. Every SMS goes through App\Services\Sms\SmsService and the "sms" queue.
 * Drivers: "log" (writes to the log, sends nothing: the default), "smsnetbd" (sms.net.bd / Alpha) and "bulksmsbd".
 */
return [
    'driver' => env('SMS_DRIVER', 'log'),

    // tried when the main driver fails; leave blank for none
    'fallback' => env('SMS_FALLBACK_DRIVER') ?: null,

    // what one SMS part costs, in taka, for the cost report
    'cost_per_part' => (float) env('SMS_COST_PER_PART', 0.35),

    // SMS go out only between these hours (24h clock, in this timezone); a send outside them waits for the next start
    'timezone' => 'Asia/Dhaka',
    'send_from' => 9,
    'send_until' => 20,

    // the daily balance check emails the super admins when the gateway balance falls below this (taka); 0 turns it off
    'low_balance' => (float) env('SMS_LOW_BALANCE', 200),

    // fixed hosts only (no URL comes from a request or a setting)
    'drivers' => [
        'smsnetbd' => [
            'url' => 'https://api.sms.net.bd/sendsms',
            'balance_url' => 'https://api.sms.net.bd/user/balance/',
            'api_key' => env('SMSNETBD_API_KEY'),
            'sender_id' => env('SMSNETBD_SENDER_ID'),
        ],
        'bulksmsbd' => [
            'url' => 'https://bulksmsbd.net/api/smsapi',
            'balance_url' => 'https://bulksmsbd.net/api/getBalanceApi',
            'api_key' => env('BULKSMSBD_API_KEY'),
            'sender_id' => env('BULKSMSBD_SENDER_ID'),
        ],
    ],
];
