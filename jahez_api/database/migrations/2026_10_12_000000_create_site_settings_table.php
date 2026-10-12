<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Landing-page content IMC administrators edit (texts in Arabic and English,
     * plus the brand logo file). Keyed rows defined by App\Models\SiteSetting::SPEC;
     * the seeder ensures every key exists, so the public map never misses one.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->string('section', 40);
            $table->string('type', 20);
            $table->text('value_ar')->nullable();
            $table->text('value_en')->nullable();
            $table->string('image_disk')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_mime_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
