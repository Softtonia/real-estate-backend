<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('api_clients')) {
            throw new RuntimeException('api_clients table does not exist.');
        }

        $columns = Schema::getColumnListing('api_clients');

        Schema::table('api_clients', function (Blueprint $table) use ($columns) {
            if (!in_array('name', $columns, true)) {
                $table->string('name')->nullable()->after('id');
            }

            if (!in_array('slug', $columns, true)) {
                $table->string('slug')->nullable()->unique()->after('name');
            }

            if (!in_array('type', $columns, true)) {
                $table->string('type')->default('custom')->index()->after('slug');
            }

            if (!in_array('allowed_origins', $columns, true)) {
                $table->json('allowed_origins')->nullable()->after('allowed_domain');
            }

            if (!in_array('permissions', $columns, true)) {
                $table->json('permissions')->nullable()->after('allowed_origins');
            }

            if (!in_array('rate_limit_per_minute', $columns, true)) {
                $table->unsignedInteger('rate_limit_per_minute')->default(300)->after('permissions');
            }

            if (!in_array('requires_signature', $columns, true)) {
                $table->boolean('requires_signature')->default(false)->after('rate_limit_per_minute');
            }

            if (!in_array('description', $columns, true)) {
                $table->text('description')->nullable()->after('requires_signature');
            }

            if (!in_array('deleted_at', $columns, true)) {
                $table->softDeletes();
            }
        });

        $this->backfillOldClients();
    }

    private function backfillOldClients(): void
    {
        DB::table('api_clients')
            ->orderBy('id')
            ->chunkById(100, function ($clients) {
                foreach ($clients as $client) {
                    $name = $client->name
                        ?? $client->client_name
                        ?? 'API Client ' . $client->id;

                    $type = $client->type
                        ?? $client->app_type
                        ?? 'custom';

                    $slug = $client->slug ?: $this->uniqueSlug($name, $client->id);

                    $allowedOrigins = $client->allowed_origins;

                    if (!$allowedOrigins && !empty($client->allowed_domain)) {
                        $allowedOrigins = $this->normalizeAllowedDomains($client->allowed_domain);
                    }

                    DB::table('api_clients')
                        ->where('id', $client->id)
                        ->update([
                            'name' => $name,
                            'slug' => $slug,
                            'type' => $type,
                            'allowed_origins' => $allowedOrigins,
                            'permissions' => $client->permissions ?: json_encode(['*']),
                            'rate_limit_per_minute' => $client->rate_limit_per_minute ?: 300,
                            'requires_signature' => $client->requires_signature ?? false,
                        ]);
                }
            });
    }

    private function normalizeAllowedDomains(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode(array_values(array_filter($decoded)));
        }

        return json_encode(
            array_values(
                array_filter(
                    array_map('trim', explode(',', $value))
                )
            )
        );
    }

    private function uniqueSlug(string $name, int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'api-client';
        $slug = $base;
        $counter = 1;

        while (
            DB::table('api_clients')
                ->where('slug', $slug)
                ->where('id', '!=', $ignoreId)
                ->exists()
        ) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'slug',
                'type',
                'allowed_origins',
                'permissions',
                'rate_limit_per_minute',
                'requires_signature',
                'description',
                'deleted_at',
            ]);
        });
    }
};