<?php

namespace App\Services\Sms;

use App\Models\Scopes\ShopScope;
use App\Models\SmsTemplate;

/**
 * The four due-alert texts. Each shop may edit its own (Bangla and English) or switch a stage off; a stage it never edited uses the text here.
 * Placeholders: {name} {amount} {due_date} {ref} {office_phone} {shop}. A Bangla part holds about 70 characters, so keep each to one part.
 */
class SmsTemplates
{
    /** stage => template key */
    public const KEYS = ['upcoming' => 'due_upcoming', 'today' => 'due_today', 'overdue' => 'due_overdue', 'final' => 'due_final'];

    public const PLACEHOLDERS = ['name', 'amount', 'due_date', 'ref', 'office_phone', 'shop'];

    /** key => [Bangla, English] */
    public const DEFAULTS = [
        'due_upcoming' => ['প্রিয় {name}, ৳{amount} পরিশোধের শেষ তারিখ {due_date}।', 'Dear {name}, Tk {amount} is due on {due_date}.'],
        'due_today' => ['আজ ৳{amount} পরিশোধের শেষ দিন। রেফ: {ref}', 'Tk {amount} is due today. Ref: {ref}'],
        'due_overdue' => ['৳{amount} বকেয়া আছে ({due_date} থেকে)। দ্রুত পরিশোধ করুন।', 'Tk {amount} overdue since {due_date}. Please pay soon.'],
        'due_final' => ['শেষ স্মরণ: ৳{amount} বকেয়া। কল: {office_phone}', 'Final reminder: Tk {amount} due. Call {office_phone}'],
    ];

    /**
     * The shop's four templates, its own edits over the built-in text. Read by shop id, so the scheduler and the queue can use it too.
     *
     * @return array<string,array{key:string,stage:string,body_bn:string,body_en:string,is_active:bool,edited:bool}> by key
     */
    public static function forShop(int $shopId): array
    {
        // explicit shop filter: the scheduler and the queue have no signed-in user, and a test run may have one from another shop
        $saved = SmsTemplate::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->get()->keyBy('key');
        $out = [];

        foreach (self::KEYS as $stage => $key) {
            $row = $saved[$key] ?? null;
            $out[$key] = [
                'key' => $key,
                'stage' => $stage,
                'body_bn' => $row->body_bn ?? self::DEFAULTS[$key][0],
                'body_en' => $row->body_en ?? self::DEFAULTS[$key][1],
                'is_active' => $row ? $row->is_active : true,
                'edited' => $row !== null,
            ];
        }

        return $out;
    }

    /** @param array<string,string> $vars */
    public static function render(string $body, array $vars): string
    {
        $pairs = [];

        foreach (self::PLACEHOLDERS as $name) {
            $pairs['{'.$name.'}'] = $vars[$name] ?? '';
        }

        return trim(preg_replace('/\s+/u', ' ', strtr($body, $pairs)));
    }

    /** How many SMS parts a message takes: 160/153 characters in plain GSM text, 70/67 once it has Bangla or other Unicode. */
    public static function parts(string $message): int
    {
        $length = mb_strlen($message);
        $unicode = preg_match('/[^\x0A\x0D\x20-\x7E]/u', $message) === 1;
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }
}
