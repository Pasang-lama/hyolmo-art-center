<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Brand;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Brand::create([
        	"name" => "Hyolmo Art Center",
        	"slug" => "Hyolmo-Arts",
        	"description" => "hyolmo Art Center Pvt. Ltd.",
        	"order" => "1",
        	"logo" => NULL,
        	"created_at" => date("Y-m-d H:i:s"),
        	"updated_at" => date("Y-m-d H:i:s"),
        ]);
    }
}
