<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS due alerts: the shop's edited templates (a stage without a row uses the built-in text), a log of every SMS,
 * and a switch on customers and sales so staff can stop reminders for one customer or one sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('key', 30); // due_upcoming, due_today, due_overdue, due_final
            $table->text('body_bn');
            $table->text('body_en');
            $table->boolean('is_active')->default(true); // off: that stage sends nothing
            $table->timestamps();
            $table->unique(['shop_id', 'key']);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->string('due_type', 20)->nullable(); // installment or promise; empty for a test SMS
            $table->unsignedBigInteger('due_id')->nullable();
            $table->string('stage', 10)->nullable(); // upcoming, today, overdue, final
            $table->string('phone', 20);
            $table->text('message');
            $table->unsignedTinyInteger('parts')->default(1);
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('provider', 20)->nullable();
            $table->string('provider_msg_id', 100)->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'skipped'])->default('queued');
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            // one SMS per due per stage, even if the job runs twice
            $table->unique(['shop_id', 'due_type', 'due_id', 'stage']);
            $table->index(['shop_id', 'status', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('sms_enabled')->default(true)->after('notes');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('sms_enabled')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('sms_enabled'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('sms_enabled'));
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_templates');
    }
};
