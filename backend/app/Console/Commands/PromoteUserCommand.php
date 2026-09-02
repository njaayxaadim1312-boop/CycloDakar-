<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * Attribue un rôle depuis la ligne de commande.
 *
 * POURQUOI CETTE COMMANDE EXISTE
 *
 * L'attribution de rôle passe normalement par l'API, et c'est très bien : elle
 * demande une session, une Policy et un motif. Mais il faut bien un premier
 * administrateur, et il faut pouvoir en rétablir un le jour où le seul compte
 * super administrateur du club est perdu — un téléphone volé, une adresse email
 * qui ne répond plus.
 *
 * ELLE FAIT EXACTEMENT CE QUE FAIT L'API, PAS MOINS.
 *
 * Le rôle change, **les jetons existants sont révoqués**, et le journal d'audit
 * est écrit. Une commande qui se contenterait d'un `UPDATE users SET role` sur
 * la base laisserait des jetons portant les anciennes capacités, et surtout
 * aucune trace : « qui m'a nommé administrateur, et quand ? » est exactement la
 * question qu'on pose après coup.
 *
 * L'auteur est enregistré comme « console » : c'est la vérité, et c'est plus
 * utile qu'un identifiant d'utilisateur inventé.
 */
final class PromoteUserCommand extends Command
{
    protected $signature = 'cyclo:promote
        {email : L\'adresse email du compte}
        {role : MEMBER, RIDE_LEADER, COLLECTOR, TREASURER, ADMIN ou SUPER_ADMIN}
        {--reason= : Le motif, écrit au journal d\'audit}';

    protected $description = 'Attribue un rôle à un compte, avec révocation des jetons et trace d\'audit.';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $role = mb_strtoupper((string) $this->argument('role'));

        $validation = Validator::make(
            ['email' => $email, 'role' => $role],
            [
                'email' => ['required', 'email'],
                'role' => ['required', Rule::in(UserRole::values())],
            ],
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $erreur) {
                $this->error('  '.$erreur);
            }

            $this->line('  Rôles possibles : '.implode(', ', UserRole::values()));

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("  Aucun compte avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $nouveau = UserRole::from($role);
        $ancien = $user->role;

        if ($nouveau === $ancien) {
            $this->line("  {$user->name} est déjà « {$ancien->label()} ». Rien à faire.");

            return self::SUCCESS;
        }

        $membre = $user->member;

        DB::transaction(function () use ($user, $membre, $ancien, $nouveau): void {
            $user->forceFill(['role' => $nouveau])->save();

            // Les jetons existants portent les capacités de l'ANCIEN rôle.
            // Les révoquer force une reconnexion, et donc des jetons
            // cohérents. C'est surtout ce qu'on veut en cas de
            // rétrogradation : l'accès doit tomber tout de suite.
            $user->tokens()->delete();

            $this->audit->logChange(
                action: 'member.role_changed',
                entity: $membre ?? $user,
                attribute: 'role',
                from: $ancien->value,
                to: $nouveau->value,
                reason: $this->option('reason')
                    ?? 'Attribution par la console (php artisan cyclo:promote).',
            );
        });

        $this->newLine();
        $this->line("  <fg=green>✔</> {$user->name} — {$email}");
        $this->line("     {$ancien->label()} → <options=bold>{$nouveau->label()}</>");
        $this->line('     Les sessions ouvertes ont été révoquées : reconnexion nécessaire.');
        $this->newLine();

        return self::SUCCESS;
    }
}
