<?php

namespace App\Models;

use App\Models\DynamicPost;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use App\Models\Notification\NotificationTopicSubscriber;
use App\Models\Notification\UserNotification;

class User extends Authenticatable implements CanResetPassword
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * Database table.
     */
    protected $table = 'users';

    /**
     * Attributes allowed for mass assignment.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'user_name',
        'email',
        'google_id',
        'phone',
        'password',
        'role_id',
        'unique_id',
        'isapproved',
        'reject_reason',
        'kyc',
        'is_otp_verified',
        'created_by',
        'email_otp_expires_at',
        'token_created_at',
    ];

    /**
     * Attributes hidden from arrays and JSON responses.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'password' => 'hashed',
        'role_id' => 'integer',
        'isapproved' => 'integer',
        'kyc' => 'integer',
        'is_otp_verified' => 'boolean',
        'created_by' => 'integer',
        'email_otp_expires_at' => 'datetime',
        'token_created_at' => 'datetime',
    ];

    /**
     * Simplified permission check.
     */
    public function hasPermission(string $permissionName): bool
    {
        return $this->hasPermissionTo($permissionName);
    }

    /**
     * User's primary application role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
            'id'
        );
    }

    /**
     * Direct unique ID relationship.
     *
     * users.unique_id contains a value such as OWN001.
     * unique_ids.unique_id contains the same value.
     */
    public function uniqueId(): BelongsTo
    {
        return $this->belongsTo(
            UniqueID::class,
            'unique_id',
            'unique_id'
        );
    }

    /**
     * Many-to-many unique IDs through pivot table.
     *
     * Keep this only when user_has_unique_ids is actually used.
     */
    public function uniqueIds(): BelongsToMany
    {
        return $this->belongsToMany(
            UniqueID::class,
            'user_has_unique_ids',
            'user_id',
            'unique_id_id'
        );
    }

    /**
     * User personal detail record.
     */
    public function personalDetail(): HasOne
    {
        return $this->hasOne(
            UserPersonalDetail::class,
            'user_id',
            'id'
        );
    }

    public function userPersonalDetail(): HasOne
    {
        return $this->personalDetail();
    }

    /**
     * User business detail record.
     */
    public function businessDetail(): HasOne
    {
        return $this->hasOne(
            UserBusinessDetail::class,
            'user_id',
            'id'
        );
    }

    public function userBusinessDetail(): HasOne
    {
        return $this->businessDetail();
    }

    /**
     * User detail record (legacy compatibility, fallback to personalDetail).
     */
    public function userDetail(): HasOne
    {
        return $this->personalDetail();
    }

    public function userDetails(): HasOne
    {
        return $this->personalDetail();
    }

    /**
     * User tickets.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(
            Ticket::class,
            'user_id',
            'id'
        );
    }

    /**
     * Agent join requests.
     */
    public function joinRequestsAsAgent(): HasMany
    {
        return $this->hasMany(
            JoinRequest::class,
            'agent_id',
            'id'
        );
    }

    /**
     * Consultancy join requests.
     */
    public function joinRequestsAsConsultancy(): HasMany
    {
        return $this->hasMany(
            JoinRequest::class,
            'consultancy_id',
            'id'
        );
    }

    /**
     * User country.
     *
     * This requires country_id in the users table.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(
            Country::class,
            'country_id',
            'id'
        );
    }

    /**
     * User state.
     *
     * This requires state_id in the users table.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(
            State::class,
            'state_id',
            'id'
        );
    }

    /**
     * User city.
     *
     * This requires city_id in the users table.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(
            City::class,
            'city_id',
            'id'
        );
    }


    /**
     * Dynamic posts assigned to user.
     */
    public function assignedListings(): BelongsToMany
    {
        return $this->belongsToMany(
            DynamicPost::class,
            'dynamic_post_user',
            'user_id',
            'dynamic_post_id'
        )
            ->withPivot([
                'assigned_by',
            ])
            ->withTimestamps();
    }

    /**
     * Full-name accessor.
     *
     * Vijay Kumar:
     * first_name = Vijay
     * last_name  = Kumar
     * full_name  = Vijay Kumar
     */
    public function getFullNameAttribute(): string
    {
        return trim(
            implode(' ', array_filter([
                $this->first_name,
                $this->last_name,
            ]))
        );
    }

    /**
     * Email used for password reset.
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    /**
     * Send password-reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(
            new ResetPasswordNotification($token)
        );
    }
    public function kycRequests()
    {
        return $this->hasMany(\App\Models\KycRequest::class);
    }

    public function latestKycRequest()
    {
        return $this->hasOne(\App\Models\KycRequest::class)->latestOfMany();
    }

    public function approvedKycRequest()
    {
        return $this->hasOne(\App\Models\KycRequest::class)
            ->where('status', \App\Models\KycRequest::STATUS_APPROVED)
            ->latestOfMany();
    }

    public function kycDocuments()
    {
        return $this->hasMany(\App\Models\KycDocument::class);
    }

    public function kycActivities()
    {
        return $this->hasMany(\App\Models\KycActivity::class);
    }

    public function kycExemption()
    {
        return $this->hasOne(\App\Models\KycUserExemption::class);
    }
    public function memberships()
    {
        return $this->hasMany(\App\Models\Membership\UserMembership::class);
    }

    public function activeMembership()
    {
        return $this->hasOne(\App\Models\Membership\UserMembership::class)
            ->where('status', \App\Models\Membership\UserMembership::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>', now());
            })
            ->latestOfMany();
    }

    public function membershipOrders()
    {
        return $this->hasMany(\App\Models\Membership\MembershipOrder::class);
    }

    public function membershipPayments()
    {
        return $this->hasMany(\App\Models\Membership\MembershipPayment::class);
    }

    public function membershipCreditBalances()
    {
        return $this->hasMany(\App\Models\Membership\MembershipCreditBalance::class);
    }

    public function membershipCreditTransactions()
    {
        return $this->hasMany(\App\Models\Membership\MembershipCreditTransaction::class);
    }

    public function membershipNotifications()
    {
        return $this->hasMany(\App\Models\Membership\MembershipNotification::class);
    }

    public function ownedMembershipTeams()
    {
        return $this->hasMany(\App\Models\Membership\MembershipTeam::class, 'owner_user_id');
    }

    public function membershipTeamMembers()
    {
        return $this->hasMany(\App\Models\Membership\MembershipTeamMember::class);
    }
    public function notificationDevices(): HasMany
    {
        return $this->hasMany(NotificationDevice::class);
    }

    public function activeNotificationDevices(): HasMany
    {
        return $this->hasMany(NotificationDevice::class)
            ->where('status', true)
            ->whereNull('revoked_at');
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function unreadInAppNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)
            ->whereNull('read_at');
    }

    public function notificationTopicSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationTopicSubscriber::class);
    }
}
