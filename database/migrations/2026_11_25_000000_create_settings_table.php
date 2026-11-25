<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Creates the settings store with a unique, non-null natural key. */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('settings', function (Blueprint $table) use ($driver): void {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->charset('utf8mb4');
                $table->collation('utf8mb4_bin');
            }

            $table->id();
            $table->string('scope', 32);
            $table->string('scope_id', 36)->default('');
            $table->string('group', 64);
            $table->string('key', 191);
            $table->json('value')->nullable();
            $table->string('encryption', 16)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'group', 'key'], 'settings_scope_unique');
            $table->index(['scope', 'scope_id', 'group'], 'settings_scope_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
