<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        if (! Schema::hasTable('dcs_random_checks')) {
            Schema::create('dcs_random_checks', function (Blueprint $table) use ($officeTable, $accountTable) {
                $table->id();
                $table->unsignedInteger('office_id');
                $table->foreign('office_id')->references('id')->on($officeTable);
                $table->string('doc_type_key', 40)->default('all');
                $table->unsignedInteger('checked_by');
                $table->foreign('checked_by')->references('id')->on($accountTable);
                $table->unsignedSmallInteger('sample_size')->default(0);
                $table->unsignedInteger('pool_size')->default(0);
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();

                $table->index(['office_id', 'checked_at']);
            });
        } elseif (! Schema::hasColumn('dcs_random_checks', 'doc_type_key')) {
            Schema::table('dcs_random_checks', function (Blueprint $table) {
                $table->string('doc_type_key', 40)->default('all')->after('office_id');
            });
        }

        if (! Schema::hasTable('dcs_random_check_items')) {
            Schema::create('dcs_random_check_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('random_check_id')
                    ->constrained('dcs_random_checks')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('masterlist_id');
                $table->foreign('masterlist_id')
                    ->references('id')
                    ->on('dcs_masterlist_registration')
                    ->cascadeOnDelete();
                $table->string('doc_type_key', 40)->nullable();
                $table->string('doc_type_label', 80)->nullable();
                $table->unsignedInteger('item_no');
                $table->string('doc_no')->nullable();
                $table->integer('rev_no')->nullable();
                $table->string('doc_title')->nullable();
                $table->date('effectivity_date')->nullable();
                $table->string('availability', 10)->nullable(); // yes | no
                $table->text('remarks')->nullable();
                $table->text('recommended_actions')->nullable();
                $table->timestamps();

                $table->unique(['random_check_id', 'masterlist_id'], 'dcs_rc_items_check_ml_unique');
                $table->index(['random_check_id', 'item_no']);
            });
        } else {
            Schema::table('dcs_random_check_items', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_random_check_items', 'doc_type_key')) {
                    $table->string('doc_type_key', 40)->nullable()->after('masterlist_id');
                }
                if (! Schema::hasColumn('dcs_random_check_items', 'doc_type_label')) {
                    $table->string('doc_type_label', 80)->nullable()->after('doc_type_key');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_random_check_items');
        Schema::dropIfExists('dcs_random_checks');
    }
};
