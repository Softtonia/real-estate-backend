<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpArticle extends Model
{

	use HasFactory;
    protected $table='help_articles';
    protected $guarded=[];


   public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(HelpSubcategory::class, 'help_subcategory_id');
    }

    public function childcategory()
    {
        return $this->belongsTo(HelpChildcategory::class, 'help_childcategory_id');
    }
}