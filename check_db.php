<?php
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Database Check ===\n";

echo "Property class exists: " . (class_exists('App\Models\Property') ? 'YES' : 'NO') . "\n";
echo "PropertyList class exists: " . (class_exists('App\Models\PropertyList') ? 'YES' : 'NO') . "\n";

try {
    echo "properties table exists: " . (Schema::hasTable('properties') ? 'YES' : 'NO') . "\n";
} catch (\Throwable $e) {
    echo "properties table error: " . $e->getMessage() . "\n";
}

$tables = ['tickets', 'ticket_status', 'ticket_priorities', 'ticket_types', 'ticket_departments', 'ticket_attachments', 'ticket_cc_users', 'tickets_response'];
foreach ($tables as $table) {
    try {
        echo "$table exists: " . (Schema::hasTable($table) ? 'YES' : 'NO') . "\n";
    } catch (\Throwable $e) {
        echo "$table error: " . $e->getMessage() . "\n";
    }
}

try {
    echo "ticket_status records: " . DB::table('ticket_status')->count() . "\n";
    echo "ticket_priorities records: " . DB::table('ticket_priorities')->count() . "\n";
    echo "ticket_types records: " . DB::table('ticket_types')->count() . "\n";
    echo "ticket_departments records: " . DB::table('ticket_departments')->count() . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

try {
    $user = DB::table('users')->whereNotNull('api_token')->first();
    if ($user) {
        echo "User with api_token: id={$user->id}, name={$user->first_name} {$user->last_name}\n";
    } else {
        echo "No users with api_token found\n";
    }
} catch (\Throwable $e) {
    echo "Users error: " . $e->getMessage() . "\n";
}

try {
    $setting = DB::table('site_settings')->first();
    if ($setting) {
        $keys = array_keys((array)$setting);
        echo "Site settings fields: " . implode(', ', $keys) . "\n";
    } else {
        echo "No site settings\n";
    }
} catch (\Throwable $e) {
    echo "Site settings error: " . $e->getMessage() . "\n";
}

// Log file check
$logPath = __DIR__ . '/storage/logs/laravel.log';
if (file_exists($logPath)) {
    $logContent = file_get_contents($logPath);
    $lines = explode("\n", $logContent);
    $lastLines = array_slice($lines, -100);
    echo "\n=== Last 100 log lines ===\n";
    echo implode("\n", $lastLines);
} else {
    echo "No log file found\n";
}