<?php

namespace App\Services\Sms;

use RuntimeException;

/** A gateway refused an SMS or could not be reached. The message is safe to show staff: it never holds the API key. */
class SmsException extends RuntimeException {}
