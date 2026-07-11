<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add linkedin_doc_title column to posts table.
     *
     * Allows user to customize the document title that appears on LinkedIn
     * when sharing a PDF. If empty, defaults to the PDF filename.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('linkedin_doc_title', 200)->nullable()->after('alt_text');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('linkedin_doc_title');
        });
    }
};
