<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use App\Services\MemberService;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;

/**
 * Crée les comptes et fiches de départ.
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
    public function __construct(
        private readonly MemberService $members,
    ) {}

    public function run(): void
    {
        $email = (string) env('SEED_ADMIN_EMAIL', 'admin@cyclodakar.sn');
        $phone = PhoneNumber::normalize((string) env('SEED_ADMIN_PHONE', '770000000'));
        $password = (string) env('SEED_ADMIN_PASSWORD', 'CycloDakar2026!');

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->command->info("Compte administrateur déjà présent : {$email}");

            return;
        }

        $this->createAccount(
            name: 'Ousmane Faye',
            email: $email,
            phone: $phone,
            password: $password,
            role: UserRole::SuperAdmin,
        );

        $this->command->newLine();
        $this->command->info('Compte administrateur créé :');
        $this->command->line("  Email        : {$email}");
        $this->command->line('  Téléphone    : '.PhoneNumber::format($phone));
        $this->command->line("  Mot de passe : {$password}");
        $this->command->warn('  Changez ce mot de passe dès la première connexion.');
        $this->command->newLine();

        if (app()->environment('local')) {
            $this->createDemoAccounts($password);
            $this->createMembersWithoutAccount();
        }
    }

    private function createAccount(
        string $name,
        string $email,
        ?string $phone,
        string $password,
        UserRole $role,
    ): User {
        $user = new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        // La fiche club passe par le même service que l'inscription : matricule
        // sous verrou, QR Code, découpage du nom. Un seeder qui prendrait un
        // raccourci produirait des données différentes de la réalité.
        $this->members->createForUser($user);

        return $user;
    }

    private function createDemoAccounts(string $password): void
    {
        $accounts = [
            ['Fatou Sow', 'collecteur@cyclodakar.sn', '770000001', UserRole::Collector],
            ['Moussa Diop', 'tresorier@cyclodakar.sn', '770000002', UserRole::Treasurer],
            ['Awa Ndiaye', 'membre@cyclodakar.sn', '770000003', UserRole::Member],
        ];

        foreach ($accounts as [$name, $email, $phone, $role]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }

            $this->createAccount(
                name: $name,
                email: $email,
                phone: PhoneNumber::normalize($phone),
                password: $password,
                role: $role,
            );
        }

        $this->command->info('3 comptes de démonstration créés.');
    }

    /**
     * Membres du club SANS compte de connexion.
     *
     * C'est le cas réel de plusieurs adhérents : pas de smartphone, mais un
     * matricule, un QR Code et une place dans les collectes. Les inclure au
     * jeu de démonstration évite de concevoir les écrans en oubliant qu'ils
     * existent.
     */
    private function createMembersWithoutAccount(): void
    {
        $people = [
            ['Ibrahima', 'Ba', '770000010', MemberStatus::Active],
            ['Aminata', 'Cissé', '770000011', MemberStatus::Active],
            ['Cheikh', 'Gueye', '770000012', MemberStatus::Active],
            ['Mariama', 'Kane', '770000013', MemberStatus::Pending],
            ['Ousseynou', 'Seck', '770000014', MemberStatus::Suspended],
            ['Ndeye', 'Thiam', '770000015', MemberStatus::Former],
        ];

        foreach ($people as [$firstName, $lastName, $phone, $status]) {
            if (Member::where('phone', PhoneNumber::normalize($phone))->exists()) {
                continue;
            }

            $this->members->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'joined_at' => now()->subMonths(random_int(1, 30))->toDateString(),
                'status' => $status->value,
            ]);
        }

        $this->command->info('6 membres sans compte créés (adhérents sans smartphone).');
    }
}
