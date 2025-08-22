<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadType extends Model
{
    use HasFactory;

    protected $table = 'lead_types';

    protected $fillable = [
        'name',
        'description',
        'status',
        'slug'
    ];

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }
}
