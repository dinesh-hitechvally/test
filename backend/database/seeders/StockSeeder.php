<?php

namespace Database\Seeders;

use App\Models\Stock;
use Illuminate\Database\Seeder;

class StockSeeder extends Seeder
{
    private const STARTER_STOCKS = [
        ['symbol' => 'NABIL', 'company_name' => 'Nabil Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'NICA', 'company_name' => 'NIC Asia Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'EBL', 'company_name' => 'Everest Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'SANIMA', 'company_name' => 'Sanima Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'GBIME', 'company_name' => 'Global IME Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'HBL', 'company_name' => 'Himalayan Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'ADBL', 'company_name' => 'Agricultural Development Bank Limited', 'sector' => 'Commercial Bank'],
        ['symbol' => 'NHPC', 'company_name' => 'National Hydro Power Company Limited', 'sector' => 'Hydro Power'],
        ['symbol' => 'HIDCL', 'company_name' => 'Hydroelectricity Investment and Development Company Limited', 'sector' => 'Hydro Power'],
        ['symbol' => 'CHCL', 'company_name' => 'Chilime Hydropower Company Limited', 'sector' => 'Hydro Power'],
        ['symbol' => 'UPPER', 'company_name' => 'Upper Tamakoshi Hydropower Limited', 'sector' => 'Hydro Power'],
        ['symbol' => 'NLIC', 'company_name' => 'Nepal Life Insurance Company Limited', 'sector' => 'Life Insurance'],
        ['symbol' => 'NICL', 'company_name' => 'Nepal Insurance Company Limited', 'sector' => 'Non Life Insurance'],
        ['symbol' => 'CIT', 'company_name' => 'Citizen Investment Trust', 'sector' => 'Investment'],
        ['symbol' => 'NTC', 'company_name' => 'Nepal Doorsanchar Company Limited (Nepal Telecom)', 'sector' => 'Telecommunication'],
        ['symbol' => 'NRIC', 'company_name' => 'Nepal Reinsurance Company Limited', 'sector' => 'Reinsurance'],
        ['symbol' => 'SHIVM', 'company_name' => 'Shivam Cements Limited', 'sector' => 'Manufacturing'],
        ['symbol' => 'CBBL', 'company_name' => 'Corporate Bittiya Sanstha Limited', 'sector' => 'Microfinance'],
    ];

    public function run(): void
    {
        foreach (self::STARTER_STOCKS as $stock) {
            Stock::firstOrCreate(['symbol' => $stock['symbol']], $stock);
        }
    }
}
