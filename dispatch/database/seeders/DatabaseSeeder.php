<?php

namespace Database\Seeders;

use App\Models\Staff;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Fictional roster. The refs match the staff accounts in WordPress.
     */
    public function run(): void
    {
        $roster = [
            ['ref' => 'rowan', 'name' => 'Rowan Vale', 'areas' => ['north', 'central'], 'skills' => ['cleaning', 'handyman']],
            ['ref' => 'sam', 'name' => 'Sam Ellery', 'areas' => ['central', 'riverside'], 'skills' => ['cleaning', 'garden']],
            ['ref' => 'jordan', 'name' => 'Jordan Pike', 'areas' => ['riverside', 'north'], 'skills' => ['handyman', 'garden']],
        ];

        foreach ($roster as $person) {
            Staff::updateOrCreate(['ref' => $person['ref']], $person);
        }
    }
}
