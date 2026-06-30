<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            if (!Schema::hasColumn('api_clients', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (!Schema::hasColumn('api_clients', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }

            if (!Schema::hasColumn('api_clients', 'type')) {
                $table->string('type')->default('custom')->after('slug');
            }

            if (!Schema::hasColumn('api_clients', 'allowed_origins')) {
                $table->json('allowed_origins')->nullable()->after('status');
            }

            if (!Schema::hasColumn('api_clients', 'permissions')) {
                $table->json('permissions')->nullable()->after('allowed_origins');
            }

            if (!Schema::hasColumn('api_clients', 'rate_limit_per_minute')) {
                $table->unsignedInteger('rate_limit_per_minute')->default(300)->after('permissions');
            }

            if (!Schema::hasColumn('api_clients', 'requires_signature')) {
                $table->boolean('requires_signature')->default(false)->after('rate_limit_per_minute');
            }

            if (!Schema::hasColumn('api_clients', 'description')) {
                $table->text('description')->nullable()->after('requires_signature');
            }

            if (!Schema::hasColumn('api_clients', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('description');
            }

            if (!Schema::hasColumn('api_clients', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $legacyColumns = [
            'client_name',
            'client_id',
            'client_secret',
            'app_type',
            'allowed_domain',
            'nextjs_internal_key',
            'used_by_origin',
        ];

        foreach ($legacyColumns as $column) {
            if (Schema::hasColumn('api_clients', $column)) {
                Schema::table('api_clients', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            if (!Schema::hasColumn('api_clients', 'client_name')) {
                $table->string('client_name')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'client_id')) {
                $table->string('client_id')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'client_secret')) {
                $table->text('client_secret')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'app_type')) {
                $table->string('app_type')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'allowed_domain')) {
                $table->json('allowed_domain')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'nextjs_internal_key')) {
                $table->string('nextjs_internal_key')->nullable();
            }

            if (!Schema::hasColumn('api_clients', 'used_by_origin')) {
                $table->string('used_by_origin')->nullable();
            }
        });
    }
};