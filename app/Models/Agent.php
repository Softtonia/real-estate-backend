<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasFactory;
    protected $table='agent_details';
    protected $guarded=[];

    public function status()
{
    return $this->hasOne(AgentStatus::class);
}
public function locations()
    {
        return $this->belongsToMany(Location::class);
    }

}
