<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Medicine extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'generic_name',
        'strength',
        'dosage_form',
        'requires_prescription',
        'manufacturer',
        'registration_number',
    ];

    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** "Amoxicillin 500mg Capsule" */
    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->generic_name,
            $this->strength,
            $this->dosage_form,
        ])));
    }
}
