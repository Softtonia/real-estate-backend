<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Keyword extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'keywords';


    /**
     * Get the property associated with the keyword.
     */
    public function property()
    {
        return $this->belongsTo(Propertylist::class, 'property_id');
    }

    public function importKeyword()
    {
        return $this->belongsTo(ImportKeyword::class, 'keyword');
    }

    /**
     * Get the project associated with the keyword.
     */
    public function project()
    {
        return $this->belongsTo(ProjectList::class, 'project_id');
    }

     public function developer()
    {
        return $this->belongsTo(Developerlist::class, 'developer_id');
    }
}
