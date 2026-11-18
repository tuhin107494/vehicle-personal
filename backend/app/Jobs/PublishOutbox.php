<?php

namespace App\Jobs;

use App\Services\Gateway\OutboxPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends pending outbox events. Nudged after every payment and run by the scheduler every minute as the safety net; only one runs at a time. */
class PublishOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('payments');
    }

    public function handle(OutboxPublisher $publisher): void
    {
        $publisher->publish();
    }
}
