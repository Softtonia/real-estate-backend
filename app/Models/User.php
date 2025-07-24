<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;

class User extends Authenticatable implements CanResetPassword
{
use HasApiTokens, HasFactory, Notifiable, HasRoles;

protected $fillable = [
        'first_name',
        'last_name',
        'fullname',
        'email',
        'password',
        'role_id',
        'phone',
        'api_token',
        'country_code',
        'requestId',
	    'email_otp',
        'email_otp_expires_at',
        'deactive_reason',
        'token_created_at',
        'unique_id',
        'isapproved','google_id','user_name'
    ];

        protected $table='users';
        protected $guarded=[];

        protected $hidden = [
        'password',
        'remember_token',
        ];

        protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        ];

    // Define the hasPermission method to simplify permission checks
    public function hasPermission($permissionName)
    {
    return $this->hasPermissionTo($permissionName);
    }

    // Relationships
    // User model
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function ticket()
    {
    return $this->hasMany(Ticket::class);
    }

    // If your email column is not named 'email', override the getEmailForPasswordReset method
    public function getEmailForPasswordReset()
    {
    return $this->email;
    }

    // Override the sendPasswordResetNotification method
    public function sendPasswordResetNotification($token)
    {
    $this->notify(new ResetPasswordNotification($token));
    }

    // User.php
    public function uniqueIds()
    {
    return $this->belongsToMany(UniqueID::class, 'user_has_unique_ids');
    }

    // UniqueID.php
    public function users()
    {
    return $this->belongsToMany(User::class, 'user_has_unique_ids');
    }

    public function uniqueId()
    {
    return $this->belongsTo(UniqueID::class);
    }
    public function userDetails()
    {
    return $this->hasOne(UserDetail::class, 'user_id');
    }

    public function joinRequestsAsAgent()
    {
        return $this->hasMany(JoinRequest::class, 'agent_id');
    }

    public function joinRequestsAsConsultancy()
    {
        return $this->hasMany(JoinRequest::class, 'consultancy_id');
    }


    public function consultancyProjects()
    {
        return $this->hasMany(CompanyConsultancyProject::class, 'company_id');
    }

    public function userDetail()
    {
        return $this->hasOne(UserDetail::class, 'user_id');
    }

     public function assignedProjects()
    {
        return $this->hasMany(ProjectList::class, 'developer_id');
    }

    // A user has many developer listings
    public function developerListings()
    {
        return $this->hasMany(Developerlist::class);
    }

}
