<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accès (ability) porté par un compte, accordé à la création ou par élévation,
 * et qui n'expire jamais après le compte.
 *
 * @property int $id
 * @property int $user_id
 * @property string $ability
 * @property string $granted_via
 * @property CarbonInterface|null $expires_at
 */
#[Fillable(['ability', 'granted_via', 'expires_at'])]
class AccessGrant extends Model
{
    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    public const VIA_DEFAULT = 'default';

    public const VIA_ELEVATION = 'elevation';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }
}
