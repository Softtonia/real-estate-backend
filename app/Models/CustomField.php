<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomField extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'custom_fields';
    protected $fillable = [
        'group_id', 'field_label', 'field_name_slug', 'field_placeholder',
        'field_type', 'required', 'post_type', 'media_limit', 'media_size',
        'media_format', 'model_fields','checkbox_type'
    ];

    protected $dates = ['deleted_at'];
    public function groupname()
    {
        return $this->belongsTo(Groupname::class, 'group_id');
    }

    public function options()
    {
        return $this->hasMany(CustomFieldOption::class, 'custom_field_id');
    }

    public function repeaterFields()
    {
        return $this->hasMany(CustomFieldRepeater::class, 'custom_field_id');
    }
        public function condition()
    {
        return $this->belongsTo(Condition::class);
    }
    public function repeaters()
    {
        return $this->hasMany(CustomFieldRepeater::class, 'custom_field_id');
    }

}
