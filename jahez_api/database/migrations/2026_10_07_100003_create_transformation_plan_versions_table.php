<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Versions of a transformation plan (ADR-025). Only a draft changes. Publishing it
     * supersedes the previous published version, which stays readable as history.
     *
     * - `is_draft` / `is_published` hold true or NULL; with the unique keys a plan has at
     *   most one draft and one published version.
     * - `revision` counts the saves of a draft: a save names the revision it was based on,
     *   so two administrators editing at once never overwrite each other silently.
     * - `summary_ar` is shown to the factory; `change_note` (what changed and why) only to
     *   IMC.
     */
    public function up(): void
    {
        Schema::create('transformation_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformation_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_draft')->nullable()->default(true);
            $table->boolean('is_published')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('title', 200);
            $table->text('summary_ar')->nullable();
            $table->text('change_note')->nullable();
            $table->foreignId('based_on_version_id')->nullable()->constrained('transformation_plan_versions')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['transformation_plan_id', 'version'], 'plan_versions_plan_version_unique');
            $table->unique(['transformation_plan_id', 'is_draft'], 'plan_versions_plan_draft_unique');
            $table->unique(['transformation_plan_id', 'is_published'], 'plan_versions_plan_published_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transformation_plan_versions ADD CONSTRAINT transformation_plan_versions_status_check CHECK (
                (status = 'draft' AND is_draft = 1 AND is_published IS NULL)
                OR (status = 'published' AND is_draft IS NULL AND is_published = 1)
                OR (status = 'superseded' AND is_draft IS NULL AND is_published IS NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plan_versions');
    }
};
