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
        if (! Schema::hasTable('tracking_devices_log')) {
            Schema::create('tracking_devices_log', function (Blueprint $table) {
                $table->increments('track_log_id');
                $table->unsignedBigInteger('tracked_id')->nullable();
                $table->string('device_id')->nullable();
                $table->string('email')->nullable();
                $table->timestamp('current_timestamp')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('tracking_devices_log', function (Blueprint $table) {
                if (! Schema::hasColumn('tracking_devices_log', 'track_log_id')) {
                    $table->increments('track_log_id');
                }
                if (! Schema::hasColumn('tracking_devices_log', 'device_id')) {
                    // only add after if tracked_id exists
                    if (Schema::hasColumn('tracking_devices', 'tracked_id')) {
                        $table->string('device_id')->nullable()->after('tracked_id');
                    } else {
                        $table->string('device_id')->nullable();
                    }
                }
                if (! Schema::hasColumn('tracking_devices_log', 'email')) {
                    if (Schema::hasColumn('tracking_devices_log', 'device_id')) {
                        $table->string('email')->nullable()->after('device_id');
                    } else {
                        $table->string('email')->nullable();
                    }
                }
                if (! Schema::hasColumn('tracking_devices_log', 'current_timestamp')) {
                    if (Schema::hasColumn('tracking_devices_log', 'email')) {
                        $table->timestamp('current_timestamp')->nullable()->after('email');
                    } else {
                        $table->timestamp('current_timestamp')->nullable();
                    }
                }
                if (! Schema::hasColumn('tracking_devices_log', 'status')) {
                    if (Schema::hasColumn('tracking_devices_log', 'current_timestamp')) {
                        $table->string('status')->nullable()->after('current_timestamp');
                    } else {
                        $table->string('status')->nullable();
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tracking_devices_log')) {
            Schema::table('tracking_devices_log', function (Blueprint $table) {
                if (Schema::hasColumn('tracking_devices_log', 'status')) {
                    $table->dropColumn('status');
                }
                if (Schema::hasColumn('tracking_devices_log', 'current_timestamp')) {
                    $table->dropColumn('current_timestamp');
                }
                if (Schema::hasColumn('tracking_devices_log', 'email')) {
                    $table->dropColumn('email');
                }
                if (Schema::hasColumn('tracking_devices_log', 'device_id')) {
                    $table->dropColumn('device_id');
                }
                if (Schema::hasColumn('tracking_devices_log', 'track_log_id')) {
                    // dropping a primary column is risky; only drop if it's not the table's main id
                    try {
                        $table->dropColumn('track_log_id');
                    } catch (\Exception $e) {
                        // ignore failures dropping primary keys
                    }
                }
            });
        }
    }
};
