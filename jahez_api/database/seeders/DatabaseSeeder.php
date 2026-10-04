<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Safe to run repeatedly.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // Synthetic logins for local development only; never created in other environments
        // because their passwords are publicly known.
        if (app()->environment('local', 'testing')) {
            if (User::query()->where('email', 'test@example.com')->doesntExist()) {
                User::factory()->imcAdmin()->create([
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                ]);
            }

            $this->call(LocalDemoSeeder::class);
        }
    }
}
