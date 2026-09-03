<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(StockSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'admin@sharemarket.test'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        $user->watchlists()->firstOrCreate(['name' => 'My Watchlist']);
    }
}
