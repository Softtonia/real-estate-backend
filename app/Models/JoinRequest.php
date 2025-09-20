<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JoinRequest extends Model
{
    use HasFactory;
    protected $table = "join_requests";

    protected $fillable = [
        'user_id',
        'type',
        'status',
    ];
     public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function consultancy()
    {
        return $this->belongsTo(User::class, 'consultancy_id');
    }

    public function company()
    {
        return $this->belongsTo(User::class, 'company_id');
    }

}
