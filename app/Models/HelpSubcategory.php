<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpSubcategory extends Model
{
    use HasFactory;
    protected $table='help_subcategories';
    protected $guarded=[];

    public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    public function childcategories()
    {
        return $this->hasMany(HelpChildcategory::class);
    }

    public function articles()
    {
        return $this->hasMany(HelpArticle::class);
    }
    
}
