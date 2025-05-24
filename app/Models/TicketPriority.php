<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketPriority extends Model
{
    use HasFactory;
    protected $table='ticket_priorities';
    protected $guarded=[];
    
    
    public function media()
    {
        return $this->belongsTo(Media::class, 'icon_id');
    }
}
