<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscribedEmail extends Model
{
    use HasFactory;
    protected $table='subscribed_emails';
    protected $guarded=[];

    protected $fillable = ['subscribe_email', 'is_subscribed', 'user_id', 'custom_tag'];

}