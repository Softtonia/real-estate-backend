<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpActivity extends Model
{
    use HasFactory;

    protected $fillable = ['help_article_id', 'like', 'dislike', 'type', 'user_id'];
}
