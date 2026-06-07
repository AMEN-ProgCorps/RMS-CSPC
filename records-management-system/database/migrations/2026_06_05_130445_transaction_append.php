<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure dts_transactions table exists before proceeding
        if (!Schema::hasTable('dts_transactions')) {
            throw new \Exception('Table dts_transactions does not exist. Run prior migrations first.');
        }

        if (!Schema::hasTable('dts_transaction_version')) {
            Schema::create('dts_transaction_version', function (Blueprint $table) {
                // use primary() instead of chaining unique()->primary()
                $table->string('append_id')->primary();
                $table->string('child_transaction_id')->index();
                $table->string('parent_transaction_id')->index();
                $table->timestamp('date_append')->useCurrent();
                $table->foreign('child_transaction_id')->references('transaction_in')->on('dts_transactions')->onDelete('cascade');
                $table->foreign('parent_transaction_id')->references('transaction_in')->on('dts_transactions')->onDelete('cascade');
            });
        }

        // add append_transaction column to dts_transactions that references dts_transaction_version.append_id
        if (!Schema::hasColumn('dts_transactions', 'append_transaction')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                $table->string('append_transaction', 255)->nullable()->after('doc_dir');
                $table->foreign('append_transaction')->references('append_id')->on('dts_transaction_version')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // remove foreign key and column from dts_transaction first
        if (Schema::hasTable('dts_transactions')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('dts_transactions', 'append_transaction')) {
                    try {
                        $table->dropForeign(['append_transaction']);
                    } catch (\Exception $e) {
                        // Foreign key may not exist, continue
                    }
                    $table->dropColumn('append_transaction');
                }
            });
        }

        // drop foreign keys on dts_transaction_version before dropping the table
        if (Schema::hasTable('dts_transaction_version')) {
            Schema::table('dts_transaction_version', function (Blueprint $table) {
                // attempt to drop the foreign keys if they exist
                try {
                    $table->dropForeign(['child_transaction_id']);
                } catch (\Exception $e) {
                    // Foreign key may not exist
                }
                try {
                    $table->dropForeign(['parent_transaction_id']);
                } catch (\Exception $e) {
                    // Foreign key may not exist
                }
            });
            Schema::dropIfExists('dts_transaction_version');
        }
    }
};
