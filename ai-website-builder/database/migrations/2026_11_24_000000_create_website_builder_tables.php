<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per website being built.
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('city')->nullable();
            $table->string('slug')->unique();
            // queued | processing | awaiting_answers | ready | failed
            $table->string('stage')->default('queued')->index();
            $table->string('stage_detail')->nullable();
            $table->text('last_error')->nullable();
            $table->string('theme')->default('classic');
            $table->string('accent_color', 7)->default('#1f6f5c');
            // The business the owner confirmed when several share a name.
            $table->json('identity')->nullable();
            $table->boolean('identity_resolved')->default(false);
            $table->boolean('research_done')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Everything we read: links, uploaded files, web search results, pasted text.
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // social | google_business | website | upload | web_search | pasted | owner
            $table->string('kind');
            $table->string('label');
            $table->text('url')->nullable();
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->string('original_name')->nullable();
            // pending | read | blocked | failed
            $table->string('status')->default('pending');
            $table->longText('content')->nullable();
            $table->text('error')->nullable();
            // Prompt-injection scan results: list of suspicious lines that were removed.
            $table->json('injection_findings')->nullable();
            $table->boolean('facts_extracted')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // Atomic, sourced facts. The website is only ever built from confirmed facts.
        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->index();
            $table->text('value');
            $table->json('meta')->nullable();
            // Verbatim quote from the source that supports this fact.
            $table->text('evidence')->nullable();
            // confirmed | conflict | rejected
            $table->string('status')->default('confirmed')->index();
            $table->timestamps();
        });

        // Things only the owner can answer: which business, which phone, missing info.
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // identity | conflict | missing | blocked_source
            $table->string('type');
            $table->string('field')->nullable();
            $table->string('dedupe_key')->nullable()->index();
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->boolean('allow_custom')->default(true);
            $table->boolean('blocking')->default(false);
            // open | answered | skipped
            $table->string('status')->default('open')->index();
            $table->text('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('visible')->default(true);
            $table->json('content');
            // ai | template | owner
            $table->string('origin')->default('template');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('section_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->json('content');
            $table->string('origin');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        // Audit trail of every AI call (no prompts with secrets, just sizes and outcomes).
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');
            $table->string('model')->nullable();
            // ok | error
            $table->string('status');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('prompt_chars')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('section_revisions');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('facts');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('sites');
    }
};
