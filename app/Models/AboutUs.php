<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AboutUs extends Model
{
    use HasFactory;

     protected $table = 'about_us';

    protected $fillable = [
        'page_title',
        'about_urbanrealities',
        'what_we_offer',
        'for_buyers_renters',
        'for_sellers_landlords',
        'our_mission_and_vision',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];
}
