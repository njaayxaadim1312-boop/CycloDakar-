<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;

/**
 * Crée les comptes de départ.
 *
 * Le compte administrateur est INDISPENSABLE : sans lui, personne ne peut
 * attribuer de rôle, et le club se retrouve avec une plateforme où tout le
 * monde est simple membre.
 *
 * Les identifiants viennent de `.env` (`SEED_ADMIN_*`) : aucun mot de passe
 * n'est écrit en dur dans un fichier versionné.
 */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('SEED_ADMIN_EMAIL', 'admin@cyclodakar.sn');
        $phone = PhoneNumber::normalize((string) env('SEED_ADMIN_PHONE', '770000000'));
        $password = (string) env('SEED_ADMIN_PASSWORD', 'CycloDakar2026!');

        // `updateOrCreate` rend le seeder rejouable sans créer de doublon ni
        // écraser un mot de passe déjà changé par l'administrateur.
        $admin = User::withTrashed()->firstWhere('email', $email);

        if ($admin !== null) {
            $this->command->info("Compte administrateur déjà présent : {$email}");

            return;
        }

        $admin = new User;
        $admin->fill([
            'name' => 'Administrateur Cyclo Dakar',
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ]);
        $admin->role = UserRole::SuperAdmin;
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->save();

        $this->command->newLine();
        $this->command->info('Compte administrateur créé :');
        $this->command->line("  Email     : {$email}");
        $this->command->line('  Téléphone : '.PhoneNumber::format($phone));
        $this->command->line("  Mot de passe : {$password}");
        $this->command->warn('  Changez ce mot de passe dès la première connexion.');
        $this->command->newLine();

        // En local uniquement : des comptes de démonstration pour éprouver les
        // écrans par rôle sans avoir à en créer un par un à la main.
        if (app()->environment('local')) {
            $this->createDemoAccounts();
        }
    }

    private function createDemoAccounts(): void
    {
        $accounts = [
            ['Fatou Sow (collectrice)', 'collecteur@cyclodakar.sn', '770000001', UserRole::Collector],
            ['Moussa Diop (trésorier)', 'tresorier@cyclodakar.sn', '770000002', UserRole::Treasurer],
            ['Awa Ndiaye (membre)', 'membre@cyclodakar.sn', '770000003', UserRole::Member],
        ];

        foreach ($accounts as [$name, $email, $phone, $role]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }

            $user = new User;
            $user->fill([
                'name' => $name,
                'email' => $email,
                'phone' => PhoneNumber::normalize($phone),
                'password' => 'CycloDakar2026!',
            ]);
            $user->role = $role;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();
        }

        $this->command->info('3 comptes de démonstration créés (mot de passe : CycloDakar2026!)');
    }
}
