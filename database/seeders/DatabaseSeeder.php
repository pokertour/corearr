<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (User::count() === 0) {
            // Génération d'un mot de passe aléatoire de 16 caractères
            $password = Str::password(16, true, true, false, false);
            
            User::create([
                'name' => 'Admin',
                'email' => 'admin@corearr.local',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            $message = "🔐 CoreArr : Premier déploiement. Utilisateur admin@corearr.local créé avec le mot de passe : {$password}";
            
            // Log dans le fichier ET console Docker
            Log::info($message);
            
            if ($this->command) {
                $this->command->info($message);
                $this->command->warn("Veuillez noter ce mot de passe !! Il est également disponible dans les logs du container.");
            }
        }
    }
}
