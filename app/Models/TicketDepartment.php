<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketDepartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'icon_id',
        'ticket_department_name',
        'display_order',
    ];

    public function media(){
        return $this->belongsTo(Media::class, 'icon_id');
    }
}
