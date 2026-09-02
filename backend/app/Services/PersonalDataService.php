<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Export et effacement des données personnelles d'un membre.
 *
 * LA LIGNE DE PARTAGE : CE QUI APPARTIENT AU MEMBRE, ET CE QUI ENGAGE LE CLUB.
 *
 * Un membre peut faire effacer ce qui ne concerne que lui : ses traces GPS —
 * qui révèlent son domicile, ses horaires et ses habitudes — sa photo, son
 * téléphone, ses notifications.
 *
 * Il ne peut PAS faire effacer les écritures comptables auxquelles il a
 * participé. Un encaissement de 5 000 FCFA n'est pas seulement sa donnée : il
 * engage la caisse du club, il figure dans un rapport peut-être déjà présenté
 * en assemblée générale, et le supprimer rendrait ce rapport faux. La règle I2
 * l'interdit d'ailleurs pour tout le monde : les écritures sont append-only.
 *
 * Ces lignes-là sont donc **ANONYMISÉES**, pas supprimées. Le montant reste, la
 * date reste, le poste reste — le nom disparaît. La comptabilité demeure juste
 * et le membre n'y est plus identifiable.
 *
 * C'est exactement ce que prévoit le RGPD quand l'effacement se heurte à une
 * obligation légale de conservation : on efface ce qu'on peut, on anonymise le
 * reste, et on le dit.
 *
 * L'EXPORT PART AVANT L'EFFACEMENT.
 *
 * Un membre qui demande la suppression de son compte doit pouvoir emporter ses
 * sorties. L'interface le lui propose ; ici, les deux opérations sont
 * distinctes, pour qu'aucune ne dépende de l'autre.
 */
final class PersonalDataService
{
    /**
     * Tout ce que le club détient sur un membre, en un objet.
     *
     * Les traces GPS complètes y sont : ce sont SES données, et un export qui
     * n'en donnerait que le résumé ne lui permettrait pas de les reprendre
     * ailleurs.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $member = $user->member;

        return [
            'export' => [
                'genere_le' => now()->toIso8601String(),
                'club' => config('cyclo.club_name', 'Cyclo Dakar'),
                'a_propos' => "Toutes les données que le club détient sur vous. "
                    ."Les écritures comptables auxquelles vous avez participé n'y "
                    .'figurent que sous forme de montants : elles engagent la caisse '
                    ."du club et ne peuvent pas être effacées, mais votre nom en sera "
                    .'retiré si vous supprimez votre compte.',
            ],

            'compte' => [
                'nom' => $user->name,
                'email' => $user->email,
                'telephone' => $user->phone,
                'role' => $user->role->value,
                'cree_le' => $user->created_at?->toIso8601String(),
                'derniere_connexion' => $user->last_login_at?->toIso8601String(),
            ],

            'fiche_club' => $member === null ? null : [
                'matricule' => $member->matricule,
                'prenom' => $member->first_name,
                'nom' => $member->last_name,
                'telephone' => $member->phone,
                'email' => $member->email,
                'statut' => $member->status->value,
                'adhesion' => $member->joined_at?->toDateString(),
                'contact_urgence' => [
                    'nom' => $member->emergency_contact_name,
                    'telephone' => $member->emergency_contact_phone,
                ],
            ],

            'sorties' => $member === null ? [] : $this->activites($member),
            'cotisations' => $member === null ? [] : $this->cotisations($member),
            'paiements' => $member === null ? [] : $this->paiements($member),
            'defis' => $member === null ? [] : $this->defis($member),
            'notifications' => $this->notifications($user),
        ];
    }

    /**
     * Efface ce qui appartient au membre, anonymise ce qui engage le club.
     *
     * @return array<string, int> Ce qui a été fait, poste par poste.
     */
    public function forget(User $user): array
    {
        $member = $user->member;
        $bilan = [];

        DB::transaction(function () use ($user, $member, &$bilan): void {
            if ($member !== null) {
                $bilan['sorties_supprimees'] = $this->effacerActivites($member);
                $bilan['paiements_anonymises'] = $this->anonymiserPaiements($member);
                $bilan['photos_supprimees'] = $this->effacerImages($member);

                /*
                 | La fiche est ANONYMISÉE, pas supprimée.
                 |
                 | Elle est référencée par des lignes de collecte et des
                 | paiements que la règle I2 interdit d'effacer. La supprimer
                 | violerait une contrainte de clé étrangère — et si on la
                 | contournait, on laisserait des encaissements orphelins :
                 | de l'argent en caisse que plus rien n'explique.
                 |
                 | Le matricule est conservé : c'est lui qui relie une écriture
                 | à une ligne, et il ne dit rien de la personne.
                 */
                $member->forceFill([
                    'first_name' => 'Membre',
                    'last_name' => 'supprimé',
                    'phone' => null,
                    'email' => null,
                    'emergency_contact_name' => null,
                    'emergency_contact_phone' => null,
                    'notes' => null,
                    'photo_path' => null,
                    'cover_path' => null,
                    // Le jeton QR est révoqué : une carte imprimée ne doit plus
                    // rien ouvrir.
                    'qr_token' => \Illuminate\Support\Str::random(43),
                ])->save();
            }

            $bilan['notifications_supprimees'] = $user->notifications()->delete();
            $bilan['appareils_oublies'] = DB::table('push_tokens')
                ->where('user_id', $user->id)->delete();

            // Toutes les sessions tombent : le compte n'existe plus.
            $user->tokens()->delete();

            /*
             | Le compte lui-même part en suppression douce.
             |
             | `audit_logs.user_id` référence les utilisateurs : un effacement
             | franc ferait disparaître l'auteur d'opérations financières, et le
             | journal d'audit ne dirait plus qui a fait quoi. Or c'est
             | précisément le document qu'on consulte quand quelque chose cloche.
             |
             | L'identité, elle, est bien effacée : ne restent qu'un identifiant
             | et une adresse neutralisée.
             */
            $user->forceFill([
                'name' => 'Compte supprimé',
                'email' => 'supprime-'.$user->id.'@cyclodakar.invalid',
                'phone' => null,
                'is_active' => false,
            ])->save();

            $user->delete();
        });

        return $bilan;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Supprime les sorties ET leurs points bruts.
     *
     * Les points d'abord : ce sont eux qui portent la position, et une
     * suppression interrompue doit laisser des sorties sans trace plutôt que
     * des traces sans sortie.
     */
    private function effacerActivites(Member $member): int
    {
        $ids = Activity::query()->where('member_id', $member->id)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table('activity_points')->whereIn('activity_id', $ids)->delete();
        DB::table('activity_stats')->whereIn('activity_id', $ids)->delete();

        return Activity::query()->whereIn('id', $ids)->forceDelete();
    }

    /**
     * Détache les paiements de leur auteur SANS toucher aux montants.
     *
     * Le membre reste lié — c'est la contrainte comptable — mais sa fiche a été
     * anonymisée juste avant. Les notes libres, elles, peuvent contenir un nom
     * ou un détail personnel : elles partent.
     */
    private function anonymiserPaiements(Member $member): int
    {
        return DB::table('payments')
            ->where('member_id', $member->id)
            ->update(['note' => null]);
    }

    private function effacerImages(Member $member): int
    {
        $disque = Storage::disk(config('cyclo.uploads.public_disk'));
        $supprimees = 0;

        foreach ([$member->photo_path, $member->cover_path] as $chemin) {
            if ($chemin !== null && $disque->exists($chemin)) {
                $disque->delete($chemin);
                $supprimees++;
            }
        }

        return $supprimees;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    private function activites(Member $member): array
    {
        return Activity::query()
            ->where('member_id', $member->id)
            ->with('stats')
            ->orderBy('started_at')
            ->get()
            ->map(fn (Activity $a) => [
                'uuid' => $a->uuid,
                'titre' => $a->title,
                'sport' => $a->sport->value,
                'debut' => $a->started_at?->toIso8601String(),
                'distance_m' => (int) $a->distance_m,
                'duree_s' => (int) $a->duration_s,
                'temps_actif_s' => (int) $a->moving_time_s,
                'denivele_m' => (int) $a->elevation_gain_m,
                'visibilite' => $a->visibility->value,
                // La trace simplifiée, encodée : c'est elle qui permet de
                // réimporter le parcours ailleurs.
                'trace_polyligne' => $a->stats?->polyline,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cotisations(Member $member): array
    {
        return DB::table('participation_members')
            ->join('participations', 'participations.id', '=', 'participation_members.participation_id')
            ->where('participation_members.member_id', $member->id)
            ->get([
                'participations.name as collecte',
                'participation_members.expected_amount',
                'participation_members.paid_amount',
                'participation_members.status',
            ])
            ->map(fn ($l) => [
                'collecte' => $l->collecte,
                'attendu_fcfa' => (int) $l->expected_amount,
                'verse_fcfa' => (int) $l->paid_amount,
                'statut' => $l->status,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paiements(Member $member): array
    {
        return DB::table('payments')
            ->where('member_id', $member->id)
            ->orderBy('paid_on')
            ->get(['receipt_number', 'amount', 'method', 'paid_on', 'cancelled_at'])
            ->map(fn ($p) => [
                'recu' => $p->receipt_number,
                'montant_fcfa' => (int) $p->amount,
                'moyen' => $p->method,
                'date' => $p->paid_on,
                'annule' => $p->cancelled_at !== null,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defis(Member $member): array
    {
        return DB::table('challenge_members')
            ->join('challenges', 'challenges.id', '=', 'challenge_members.challenge_id')
            ->where('challenge_members.member_id', $member->id)
            ->get(['challenges.title', 'challenge_members.progress', 'challenge_members.completed_at'])
            ->map(fn ($d) => [
                'defi' => $d->title,
                'progression' => (int) $d->progress,
                'reussi_le' => $d->completed_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(User $user): array
    {
        return $user->notifications()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($n) => [
                'recue_le' => $n->created_at?->toIso8601String(),
                'lue' => $n->read_at !== null,
                'contenu' => $n->data,
            ])
            ->all();
    }
}
