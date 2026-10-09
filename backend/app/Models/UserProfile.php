<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'first_name', 'last_name', 'phone', 'gender', 'date_of_birth', 'occupation', 'bio', 'timezone', 'avatar',
    'country', 'province', 'city', 'street_address', 'postal_code',
])]
class UserProfile extends Model
{
    // Keyed by user_id (no id column) and only updated_at is kept.
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
