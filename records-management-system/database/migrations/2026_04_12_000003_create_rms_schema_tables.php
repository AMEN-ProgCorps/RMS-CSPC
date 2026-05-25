<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * before Run the migrations. do the create database rms first;
     */
    public function up(): void
    {
        Schema::create('condition_details', function (Blueprint $table) {
            $table->increments('key_id');
            $table->boolean('is_sadm')->default(false);
            $table->boolean('can_access_dts')->default(false);
            $table->boolean('can_access_archv')->default(false);
            $table->boolean('can_access_dcs')->default(false);
            $table->boolean('can_modify_docflow')->default(false);
            $table->boolean('can_modify_accountlist')->default(false);
            $table->boolean('can_modify_pass')->default(false);
            $table->boolean('can_modify_user')->default(false);
            $table->boolean('can_view_all_list')->default(false);
            $table->boolean('can_view_all_archive')->default(false);
        });

        Schema::create('condition_key', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key_name');
            $table->text('key_description')->nullable();
            $table->unsignedInteger('modifier_key');
            $table->foreign('modifier_key')->references('key_id')->on('condition_details');
        });

        Schema::create('condition_defaults', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('key_id');
            $table->foreign('key_id')->references('id')->on('condition_key');
        });

        Schema::create('office', function (Blueprint $table) {
            $table->increments('id');
            $table->string('office_name')->unique();
            $table->string('office_code', 50)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('account', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->unique();
            $table->string('password');
            $table->unsignedInteger('account_status')->default(1);
            $table->boolean('account_active')->default(true);
            $table->dateTime('date_created')->useCurrent();
            $table->dateTime('date_updated')->useCurrentOnUpdate()->useCurrent();
            $table->foreign('account_status')->references('id')->on('condition_key');
        });

        Schema::create('account_details', function (Blueprint $table) {
            $table->unsignedInteger('account_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->unsignedInteger('office_id')->nullable();
            $table->string('email')->unique();
            $table->string('contact_number', 25)->nullable();
            $table->boolean('is_currently_online')->default(false);
            $table->dateTime('last_online_time')->nullable();

            $table->primary('account_id');
            $table->foreign('account_id')->references('id')->on('account')->onDelete('cascade');
            $table->foreign('office_id')->references('id')->on('office')->nullOnDelete();
        });

        DB::table('condition_details')->insert([
            'is_sadm' => false,
            'can_access_dts' => false,
            'can_access_archv' => false,
            'can_access_dcs' => false,
            'can_modify_docflow' => false,
            'can_modify_accountlist' => false,
            'can_modify_pass' => false,
            'can_modify_user' => false,
            'can_view_all_list' => false,
            'can_view_all_archive' => false,
        ]);

        DB::table('condition_key')->insert([
            'key_name' => 'Default',
            'key_description' => 'Default condition key with lowest permission',
            'modifier_key' => 1,
        ]);

        DB::table('condition_defaults')->insert([
            'key_id' => 1,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_details');
        Schema::dropIfExists('account');
        Schema::dropIfExists('office');
        Schema::dropIfExists('condition_defaults');
        Schema::dropIfExists('condition_key');
        Schema::dropIfExists('condition_details');
    }
};
