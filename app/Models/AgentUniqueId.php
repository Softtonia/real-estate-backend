<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentUniqueId extends Model
{
    use HasFactory;
    protected $table='agents_unique_id';
    protected $guarded=[];
}
