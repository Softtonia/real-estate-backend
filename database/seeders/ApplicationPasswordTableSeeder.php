<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApplicationPasswordTableSeeder extends Seeder
{
    /**
     * Seed fixed application passwords from configuration.
     */
    public function run(): void
    {
        if (! Schema::hasTable('application_passwords')) {
            $this->command?->warn(
                'application_passwords table not found.'
            );

            return;
        }

        if (! Schema::hasTable('api_clients')) {
            $this->command?->warn(
                'api_clients table not found.'
            );

            return;
        }

        $now = Carbon::now();

        /*
         * api_client_slug must exactly match api_clients.slug.
         *
         * fixed_key_name is used as the stable identifier for updating
         * an existing fixed application password.
         */
        $passwords = [
            [
                'fixed_key_name' => 'fixed_admin_panel',
                'api_client_slug' => 'admin-panel',
                'name' => 'Fixed Admin Panel Application Password',
                'env_key' => 'FIXED_ADMIN_PANEL_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_react_admin',
                'api_client_slug' => 'api-key-port-5173',
                'name' => 'Fixed Local React Admin Application Password',
                'env_key' => 'FIXED_LOCAL_REACT_ADMIN_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_api',
                'api_client_slug' => 'api-key-port-8000',
                'name' => 'Fixed Local API Application Password',
                'env_key' => 'FIXED_LOCAL_API_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_business_live',
                'api_client_slug' => 'businesscom',
                'name' => 'Fixed Business Live Application Password',
                'env_key' => 'FIXED_BUSINESS_LIVE_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_business_local',
                'api_client_slug' => 'business-localhost-api-key-port-5173',
                'name' => 'Fixed Business Local Application Password',
                'env_key' => 'FIXED_BUSINESS_LOCAL_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_nextjs',
                'api_client_slug' => 'local-sagar',
                'name' => 'Fixed Local Next.js Application Password',
                'env_key' => 'FIXED_LOCAL_NEXTJS_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_holiplaces_website',
                'api_client_slug' => 'holiplacescom',
                'name' => 'Fixed Holiplaces Website Application Password',
                'env_key' => 'FIXED_HOLIPLACES_WEBSITE_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_mobile_application',
                'api_client_slug' => 'mobile-application',
                'name' => 'Fixed Mobile Application Password',
                'env_key' => 'FIXED_MOBILE_APPLICATION_TOKEN',
                'abilities' => ['*'],
            ],
        ];

        $applicationPasswordColumns = Schema::getColumnListing(
            'application_passwords'
        );

        $apiClientHasDeletedAt = Schema::hasColumn(
            'api_clients',
            'deleted_at'
        );

        foreach ($passwords as $password) {
            try {
                $clientQuery = DB::table('api_clients')
                    ->where('slug', $password['api_client_slug']);

                if ($apiClientHasDeletedAt) {
                    $clientQuery->whereNull('deleted_at');
                }

                $client = $clientQuery->first();

                if (! $client) {
                    $this->command?->warn(
                        sprintf(
                            'API client not found for %s. Expected slug: %s',
                            $password['fixed_key_name'],
                            $password['api_client_slug']
                        )
                    );

                    continue;
                }

                $plainToken = $this->getTokenFromConfig(
                    $password['env_key']
                );

                if ($plainToken === '') {
                    $this->command?->warn(
                        sprintf(
                            'Token missing for %s. Config key: api_security.fixed_tokens.%s',
                            $password['fixed_key_name'],
                            $password['env_key']
                        )
                    );

                    continue;
                }

                $data = [
                    'api_client_id' => $client->id,
                    'name' => $password['name'],

                    /*
                     * Store only a SHA-256 hash in the database.
                     * Never store the complete plain token.
                     */
                    'token_hash' => hash('sha256', $plainToken),

                    /*
                     * This is only used to identify the token.
                     */
                    'token_prefix' => $this->tokenPrefix($plainToken),

                    'abilities' => json_encode(
                        $password['abilities'],
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),

                    'expires_at' => null,
                    'revoked_at' => null,
                    'updated_at' => $now,
                ];

                if (
                    in_array(
                        'fixed_key_name',
                        $applicationPasswordColumns,
                        true
                    )
                ) {
                    $data['fixed_key_name'] =
                        $password['fixed_key_name'];
                }

                if (
                    in_array(
                        'last_used_at',
                        $applicationPasswordColumns,
                        true
                    )
                ) {
                    $data['last_used_at'] = null;
                }

                if (
                    in_array(
                        'last_used_ip',
                        $applicationPasswordColumns,
                        true
                    )
                ) {
                    $data['last_used_ip'] = null;
                }

                if (
                    in_array(
                        'last_user_agent',
                        $applicationPasswordColumns,
                        true
                    )
                ) {
                    $data['last_user_agent'] = null;
                }

                $data = $this->onlyExistingColumns(
                    $data,
                    $applicationPasswordColumns
                );

                /*
                 * Prefer fixed_key_name as the permanent identifier.
                 * Fall back to api_client_id and name for older schemas.
                 */
                if (
                    in_array(
                        'fixed_key_name',
                        $applicationPasswordColumns,
                        true
                    )
                ) {
                    $match = [
                        'fixed_key_name' =>
                            $password['fixed_key_name'],
                    ];
                } else {
                    $match = [
                        'api_client_id' => $client->id,
                        'name' => $password['name'],
                    ];
                }

                $existing = DB::table('application_passwords')
                    ->where($match)
                    ->first();

                DB::transaction(
                    function () use (
                        $existing,
                        $data,
                        $now,
                        $applicationPasswordColumns
                    ): void {
                        if ($existing) {
                            DB::table('application_passwords')
                                ->where('id', $existing->id)
                                ->update($data);

                            return;
                        }

                        if (
                            in_array(
                                'created_at',
                                $applicationPasswordColumns,
                                true
                            )
                        ) {
                            $data['created_at'] = $now;
                        }

                        DB::table('application_passwords')
                            ->insert(
                                $this->onlyExistingColumns(
                                    $data,
                                    $applicationPasswordColumns
                                )
                            );
                    }
                );

                $action = $existing ? 'Updated' : 'Created';

                $this->command?->info(
                    sprintf(
                        '%s %s: %s',
                        $action,
                        $password['fixed_key_name'],
                        $password['name']
                    )
                );

                $this->command?->line(
                    'Client Slug: ' .
                    $password['api_client_slug']
                );

                $this->command?->line(
                    'Token Prefix: ' .
                    $this->tokenPrefix($plainToken)
                );

                $this->command?->newLine();
            } catch (Throwable $exception) {
                report($exception);

                $this->command?->error(
                    sprintf(
                        'Failed to seed %s: %s',
                        $password['fixed_key_name'],
                        $exception->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Get the plain token from Laravel configuration.
     *
     * Do not call env() from seeders because it may return null
     * when Laravel configuration is cached.
     */
    private function getTokenFromConfig(string $envKey): string
    {
        $token = config(
            'api_security.fixed_tokens.' . $envKey
        );

        if (! is_string($token)) {
            return '';
        }

        return trim($token);
    }

    /**
     * Return a safe token identifier.
     */
    private function tokenPrefix(string $plainToken): string
    {
        return substr($plainToken, 0, 24);
    }

    /**
     * Remove fields that do not exist in the current table schema.
     */
    private function onlyExistingColumns(
        array $data,
        array $existingColumns
    ): array {
        return array_filter(
            $data,
            static fn (
                mixed $value,
                string $column
            ): bool => in_array(
                $column,
                $existingColumns,
                true
            ),
            ARRAY_FILTER_USE_BOTH
        );
    }
}