<?php

namespace App\Models;

use App\Models\CustomWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WidgetConfiguration extends Model
{
    use HasFactory;

    protected $table = 'widget_configurations';

    protected $fillable = [
        'widget_id',
        'field_key',
        'field_value',
    ];

    protected $casts = [
        'field_value' => 'array',
    ];

    public function widget()
    {
        return $this->belongsTo(CustomWidget::class, 'widget_id');
    }
}