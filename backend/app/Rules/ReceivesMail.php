<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/** The email's domain exists and can take mail, which catches typos like gmial.con. It cannot prove the mailbox itself exists. */
class ReceivesMail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->hasMailServer(Str::afterLast((string) $value, '@'))) {
            $fail('Enter an email address that can receive mail.');
        }
    }

    /** A domain with no MX record may still take mail on its A record, as the mail standard allows. */
    protected function hasMailServer(string $domain): bool
    {
        return checkdnsrr($domain.'.', 'MX') || checkdnsrr($domain.'.', 'A');
    }
}
