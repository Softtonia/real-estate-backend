<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;
    protected $guarded=[];

    public function ticketStatus()
    {
        return $this->hasOne(TicketStatus::class, 'icon_id');
    }

    public function ticketPriority()
    {
        return $this->hasOne(TicketPriority::class, 'icon_id');
    }


// media icon url
    protected $appends = ['media_icon_url'];

    public function getMediaIconUrlAttribute()
    {
        if ($this->media_icon) {
            return rtrim(config('app.url'), '/') . '/' . ltrim($this->media_icon, '/');
        }
        return null;
    }

}
