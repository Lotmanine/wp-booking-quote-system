<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = ['booking_id', 'version', 'staff_id', 'slot_start'];

    protected function casts(): array
    {
        return ['slot_start' => 'datetime'];
    }
}
