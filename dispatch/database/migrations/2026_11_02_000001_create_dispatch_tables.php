<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->string('ref', 40)->unique();
            $table->string('name', 120);
            $table->json('areas');
            $table->json('skills');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('dispatch_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedInteger('version');
            $table->string('area', 40);
            $table->string('skill', 40);
            $table->dateTime('slot_start');
            $table->string('status', 20)->default('queued');
            $table->string('outcome', 20)->nullable();
            $table->string('staff_ref', 40)->nullable();
            $table->string('reason', 190)->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'version']);
        });

        // The unique pair means one person can never hold two jobs in one slot.
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedInteger('version');
            $table->foreignId('staff_id')->constrained('staff');
            $table->dateTime('slot_start');
            $table->timestamps();
            $table->unique(['staff_id', 'slot_start']);
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('dispatch_requests');
        Schema::dropIfExists('staff');
    }
};
