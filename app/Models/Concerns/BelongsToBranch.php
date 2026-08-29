<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes a model to the signed-in user's branch.
 *
 * Administrators see everything. Everyone else only ever sees — and can only
 * ever create — records belonging to the branch they are assigned to, which
 * is what keeps one store's sales out of another store's reports.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $user = Auth::user();

            if (! $user || $user->seesAllBranches()) {
                return;
            }

            if ($user->branch_id) {
                $query->where($query->getModel()->getTable().'.branch_id', $user->branch_id);
            }
        });

        static::creating(function ($model) {
            if (! $model->branch_id) {
                $model->branch_id = Auth::user()?->branch_id;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Escape hatch for reports that legitimately span every branch. */
    public function scopeAllBranches(Builder $query): Builder
    {
        return $query->withoutGlobalScope('branch');
    }
}
