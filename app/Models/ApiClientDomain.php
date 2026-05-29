<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiClientDomain extends Model
{
    use HasFactory;

    protected $table = 'api_client_domains';
    protected $fillable = [
        'api_client_id',
        'domain',
        'normalized_domain',
        'status',
    ];
    public function apiClient(){
        return $this->belongsTo(ApiClient::class);
    }
}
