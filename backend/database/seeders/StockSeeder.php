<?php

namespace Database\Seeders;

use App\Models\Stock;
use Illuminate\Database\Seeder;

class StockSeeder extends Seeder
{
    private const STARTER_STOCKS = [];

    public function run(): void
    {
        foreach (self::STARTER_STOCKS as $stock) {
            Stock::firstOrCreate(['symbol' => $stock['symbol']], $stock);
        }
    }
}
