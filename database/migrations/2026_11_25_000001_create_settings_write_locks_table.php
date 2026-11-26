<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Provides fixed, identity-free rows for serializing writes to settings groups. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_write_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('settings_write_locks')->insert(array_map(
            static fn (int $id): array => ['id' => $id],
            range(0, 63),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_write_locks');
    }
};
