<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = ['ref', 'name', 'areas', 'skills', 'active'];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'skills' => 'array',
            'active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
