<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportKeyword extends Model
{
    use HasFactory;
    protected $table = 'import_keywords';
    protected $fillable = [
        'keyword_name',
        'slug',
        'keyword_type'
    ];


    public function properties()
    {
        return $this->belongsToMany(Propertylist::class, 'keywords', 'keyword', 'property_id');
    }

    public function projects()
    {
        return $this->belongsToMany(ProjectList::class, 'keywords', 'keyword', 'project_id');
    }
}
