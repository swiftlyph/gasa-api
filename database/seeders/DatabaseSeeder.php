<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * RoleSeeder is safe (and required) in every environment. DevSeeder
     * creates the four fixed test accounts and is only run outside
     * production.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DevSeeder::class);
        }
    }
}
