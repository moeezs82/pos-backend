<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collision-safe invoice numbering (handover doc G2).
 *
 * The old scheme read MAX(invoice_no) for the day and added 1 with no lock,
 * so two sales syncing at once from different devices could read the same max
 * and collide on the invoice_no UNIQUE index — the loser threw a 500 and the
 * queued sale was wrongly marked failed, exactly under the simultaneous
 * reconnect burst this system is built for.
 *
 * This table replaces that read-then-increment with an atomic, row-locked
 * per-day counter. `ymd` is the invoice date (Ymd) — numbering is global per
 * day, matching the existing INV-YYYYMMDD-### format and its global unique
 * index. `next_seq` is the next sequence to hand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('ymd', 8)->primary(); // e.g. 20260704
            $table->unsignedBigInteger('next_seq')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_counters');
    }
};
