<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_order_id',
        'addon_order_id',
        'user_id',
        'invoice_number',
        'invoice_date',
        'currency',
        'taxable_amount',
        'gst_percentage',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'gst_amount',
        'total_amount',
        'billing_name',
        'billing_email',
        'billing_phone',
        'billing_gst_number',
        'billing_address',
        'place_of_supply',
        'invoice_pdf_disk',
        'invoice_pdf_path',
        'status',
        'metadata',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'taxable_amount' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'billing_address' => 'array',
        'metadata' => 'array',
    ];

    public function membershipOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'membership_order_id');
    }

    public function addonOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipAddonOrder::class, 'addon_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}