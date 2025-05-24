<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketStatus extends Model
{
    use HasFactory;
    protected $table='ticket_status';
    protected $guarded=[];
    
      public function media()
    {
        return $this->belongsTo(Media::class, 'icon_id');
    }
}
