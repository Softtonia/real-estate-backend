<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientReview extends Model
{
    use HasFactory;
    protected $table='client_reviews';
    protected $guarded=[];
    protected $fillable = ['title', 'review', 'short_description', 'client_photo', 'status'];
}