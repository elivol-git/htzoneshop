<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_embeddings', function (Blueprint $table) {
            $table->char('query_hash', 64)->primary();
            $table->string('model', 128);
            $table->string('query', 255);
            $table->binary('embedding');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE query_embeddings MODIFY embedding MEDIUMBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('query_embeddings');
    }
};
