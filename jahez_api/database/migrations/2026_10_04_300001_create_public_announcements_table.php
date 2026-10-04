<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Announcements IMC publishes on the public landing page (ADR-022): workshops,
     * campaigns and calls to register. They are not service promotions (ADR-020), which
     * belong to one provider's listing and are shown only to eligible factories. An
     * announcement is a draft until published, visible between its optional start and
     * end, and ordered by sort_order. The cover image is a private file served only
     * while the announcement is live.
     */
    public function up(): void
    {
        Schema::create('public_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('description');
            $table->string('badge_text', 40)->nullable();
            $table->string('color', 20);
            $table->string('link_path', 255)->nullable();
            $table->json('tags')->nullable();
            $table->string('countdown_text', 60)->nullable();
            $table->string('cover_disk', 50)->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_mime_type', 100)->nullable();
            $table->string('cover_alt', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published_at', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_announcements');
    }
};
