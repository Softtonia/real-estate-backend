<?php

namespace Database\Seeders;

use App\Models\Notification\UserNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class UserNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('user_notifications')) {
            return;
        }

        $users = User::query()->take(10)->get();

        if ($users->isEmpty()) {
            return;
        }

        $sampleNotifications = [
            [
                'type' => 'system',
                'title' => 'Password Changed',
                'body' => 'Your password was changed successfully.',
                'minutes_ago' => 30,
                'read' => true,
            ],
            [
                'type' => 'system',
                'title' => 'Login Notification',
                'body' => 'New login detected on Chrome (Windows) from current IP.',
                'minutes_ago' => 45,
                'read' => true,
            ],
            [
                'type' => 'leads',
                'title' => 'New Lead Assigned',
                'body' => 'You have been assigned a new lead "Michael Vance" for Luxury 3BHK Villa.',
                'minutes_ago' => 120,
                'read' => false,
            ],
            [
                'type' => 'projects',
                'title' => 'Project Update',
                'body' => 'Project "Skyline Residences" has been updated by administration.',
                'minutes_ago' => 360,
                'read' => false,
            ],
            [
                'type' => 'kyc',
                'title' => 'KYC Status Update',
                'body' => 'Your KYC documents are currently in review by our verification team.',
                'minutes_ago' => 1440,
                'read' => true,
            ],
            [
                'type' => 'membership',
                'title' => 'Plan Activated',
                'body' => 'Your Professional Agent membership subscription is now active.',
                'minutes_ago' => 2880,
                'read' => true,
            ],
            [
                'type' => 'system',
                'title' => 'Security Notice',
                'body' => 'Account security check completed. All permissions are verified.',
                'minutes_ago' => 4320,
                'read' => true,
            ],
        ];

        foreach ($users as $user) {
            foreach ($sampleNotifications as $index => $item) {
                $exists = UserNotification::where('user_id', $user->id)
                    ->where('title', $item['title'])
                    ->exists();

                if (!$exists) {
                    $createdAt = Carbon::now()->subMinutes($item['minutes_ago']);
                    UserNotification::create([
                        'user_id' => $user->id,
                        'title' => $item['title'],
                        'body' => $item['body'],
                        'type' => $item['type'],
                        'read_at' => $item['read'] ? $createdAt->copy()->addMinutes(10) : null,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }
            }
        }
    }
}
