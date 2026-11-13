<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox is how the payment and subscription modules talk: an event is written in the same transaction as the
 * change it reports, and a publisher hands it on afterwards, so a crash in between loses nothing.
 * The audit log keeps every super admin action on billing and gateways (never a secret value).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 80);
            $table->json('payload');
            $table->enum('status', ['pending', 'published', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('action', 60); // gateway.updated, gateway.mode_changed, subscription.extended, payment.approved, refund.requested
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // [{field, from, to}] for non-secret fields only; a secret field is listed by name with no values
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('outbox_events');
    }
};
