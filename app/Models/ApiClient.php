<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    use HasFactory;

     protected $fillable = ['client_name', 'client_id', 'client_secret', 'allowed_domain','app-type','status'];
}
