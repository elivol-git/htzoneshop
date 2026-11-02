<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 32)->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->index();
            $table->string('subcategory')->index();
            $table->text('description')->nullable();
            $table->text('attributes')->nullable();
            $table->string('image_url', 512)->nullable();
            $table->decimal('price', 10, 2);
            // Normalized Hebrew/English text used by the keyword (FULLTEXT) search.
            $table->text('search_text');
            // L2-normalized float32 vector, packed binary (see VectorCodec).
            $table->binary('embedding')->nullable();
            $table->string('embedding_model', 128)->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            // Default InnoDB stopwords ("a", "i", "in"...) would drop every
            // ngram containing them (e.g. "air"); disable for this index.
            DB::statement('SET SESSION innodb_ft_enable_stopword = 0');
            // ngram parser tokenizes Hebrew/English into bigrams: handles prefixes
            // (ה/ו/ב/ל/ש) and partial words without a Hebrew stemmer.
            DB::statement('ALTER TABLE products ADD FULLTEXT ft_products_search (search_text) WITH PARSER ngram');
            DB::statement('ALTER TABLE products MODIFY embedding MEDIUMBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
