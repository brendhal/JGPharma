<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_number',
        'from_branch_id',
        'to_branch_id',
        'requested_by',
        'approved_by',
        'status',
        'transfer_date',
        'received_date',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'transfer_date' => 'date',
            'received_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transfer) {
            $transfer->transfer_number ??= self::nextTransferNumber();
            $transfer->requested_by ??= auth()->id();
            $transfer->transfer_date ??= today();

            /*
             * The column has a PENDING default, but a default is only applied
             * by the database, so the freshly inserted model would carry a
             * null status in memory for the rest of the request. Anything
             * reading the status straight after create - the items repeater
             * decides whether to save its lines that way - would read that
             * null as "not pending" and silently do nothing.
             */
            $transfer->status ??= TransferStatus::PENDING;
        });

        /*
         * A transfer touches two branches, so it cannot use the plain branch
         * scope. Instead, a non-admin sees a transfer if either end of it is
         * their branch.
         */
        static::addGlobalScope('branch', function (Builder $query) {
            $user = Auth::user();

            if (! $user || $user->seesAllBranches() || ! $user->branch_id) {
                return;
            }

            $query->where(function (Builder $q) use ($user) {
                $q->where('from_branch_id', $user->branch_id)
                    ->orWhere('to_branch_id', $user->branch_id);
            });
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function nextTransferNumber(): string
    {
        $prefix = 'TRF-'.now()->format('Ymd').'-';

        $last = static::withoutGlobalScopes()
            ->where('transfer_number', 'like', $prefix.'%')
            ->orderByDesc('transfer_number')
            ->value('transfer_number');

        $sequence = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function isPending(): bool
    {
        return $this->status === TransferStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === TransferStatus::APPROVED;
    }
}
