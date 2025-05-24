<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpChildcategory extends Model
{
    

	use HasFactory;
    protected $table='help_childcategories';
    protected $guarded=[];


    public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(HelpSubcategory::class, 'help_subcategory_id');
    }

    public function articles()
    {
        return $this->hasMany(HelpArticle::class);
    }
}