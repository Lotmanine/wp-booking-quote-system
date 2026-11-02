<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchRequest extends Model
{
    protected $fillable = ['booking_id', 'version', 'area', 'skill', 'slot_start', 'status', 'outcome', 'staff_ref', 'reason'];

    protected function casts(): array
    {
        return ['slot_start' => 'datetime'];
    }
}
