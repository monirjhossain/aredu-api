<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SYSTEM_SLUGS = [
        'super_admin',
        'teacher',
        'student',
    ];

    protected $fillable = [
        'name',
        'slug',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function isSystem(): bool
    {
        return in_array($this->slug, self::SYSTEM_SLUGS, true);
    }
}
