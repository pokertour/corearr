<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (User::count() === 0) {
            $password = \Illuminate\Support\Str::password(16, true, true, false, false);
            
            User::factory()->create([
                'name' => 'Admin',
                'email' => 'admin@corearr.local',
                'password' => \Illuminate\Support\Facades\Hash::make($password),
            ]);

            $message = "🔐 CoreArr : Premier déploiement. Utilisateur admin@corearr.local créé avec le mot de passe : {$password}";
            
            \Illuminate\Support\Facades\Log::info($message);
            
            if ($this->command) {
                $this->command->info($message);
                $this->command->warn("Veuillez conserver ce mot de passe ou le modifier via l'interface profil.");
            }
        }
    }
}
