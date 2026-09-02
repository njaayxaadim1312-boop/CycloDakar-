<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\ChallengeMetric;
use App\Models\Activity;
use App\Models\LeaderboardSnapshot;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Les classements du club.
 *
 * UNE SORTIE PRIVÉE NE CLASSE JAMAIS SON AUTEUR.
 *
 * C'est la règle la plus importante de ce fichier, et elle n'est pas
 * négociable. Un membre qui marque une sortie « privée » a demandé qu'elle ne
 * soit pas vue ; la faire apparaître dans un classement — même sous forme d'un
 * total, même sans la carte — trahirait exactement ce qu'il a demandé. Un
 * classement est une publication.
 *
 * Le corollaire est assumé : un membre qui met tout en privé n'apparaît nulle
 * part, et c'est normal. Mieux vaut un classement incomplet qu'un classement
 * qui publie ce qu'on lui a confié.
 *
 * UNE PÉRIODE RÉVOLUE EST FIGÉE, LA PÉRIODE EN COURS SE CALCULE.
 *
 * Les sorties bougent après coup : le mobile synchronise en différé, un membre
 * passe une sortie en privé, une trace est corrigée. Recalculé, le classement
 * de septembre changerait donc en octobre — après que le club a félicité
 * quelqu'un. Une période close est un fait ; on l'arrête et on ne la retouche
 * plus. La période en cours, elle, n'est pas finie : la figer n'aurait aucun
 * sens.
 */
final class LeaderboardService
{
    /** Au-delà, la liste ne se lit plus : on affiche le podium et son rang. */
    private const TOP = 20;

    /**
     * Le classement d'une période.
     *
     * @return array{entries: list<array<string, mixed>>, frozen: bool, period_key: string, me: array<string, mixed>|null}
     */
    public function build(
        string $period,
        ChallengeMetric $metric,
        ?string $sport = null,
        ?Member $viewer = null,
        ?string $key = null,
    ): array {
        $cle = $key ?? self::periodKey($period, now());
        [$debut, $fin] = self::bounds($period, $cle);

        // Une période close a-t-elle déjà été figée ? Si oui, elle fait foi.
        $fige = $fin->isPast() && LeaderboardSnapshot::query()
            ->where('period', $period)
            ->where('period_key', $cle)
            ->where('metric', $metric->value)
            ->where('sport', $sport)
            ->exists();

        $lignes = $fige
            ? $this->fromSnapshot($period, $cle, $metric, $sport)
            : $this->compute($metric, $sport, $debut, $fin);

        return [
            'period_key' => $cle,
            'frozen' => $fige,
            'entries' => array_slice($lignes, 0, self::TOP),
            // Le rang du lecteur, MÊME s'il est hors du top. Un classement qui
            // ne montre que les vingt premiers dit à tous les autres qu'ils ne
            // comptent pas ; connaître son rang est précisément ce qui donne
            // envie de le remonter.
            'me' => $viewer === null ? null : $this->findMe($lignes, $viewer),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Le classement calculé en direct depuis les activités.
     *
     * @return list<array<string, mixed>>
     */
    private function compute(
        ChallengeMetric $metric,
        ?string $sport,
        Carbon $debut,
        Carbon $fin,
    ): array {
        $colonne = $metric->column();

        $valeur = $colonne === null
            ? DB::raw('COUNT(*) as valeur')
            : DB::raw("SUM(activities.{$colonne}) as valeur");

        $lignes = $this->scope($sport, $debut, $fin)
            ->join('members', 'members.id', '=', 'activities.member_id')
            ->groupBy('members.id', 'members.uuid', 'members.first_name', 'members.last_name', 'members.matricule', 'members.photo_path')
            ->orderByDesc('valeur')
            // Départage stable : à valeur égale, celui qui a fait le plus de
            // sorties passe devant. Sans second critère, l'ordre dépendrait du
            // moteur de base et changerait d'un appel à l'autre.
            ->orderByDesc('activites')
            ->orderBy('members.id')
            ->get([
                'members.id as member_id',
                'members.uuid',
                'members.first_name',
                'members.last_name',
                'members.matricule',
                'members.photo_path',
                $valeur,
                DB::raw('COUNT(*) as activites'),
            ]);

        return $this->rank($lignes->all());
    }

    /**
     * Le classement figé, relu tel quel.
     *
     * @return list<array<string, mixed>>
     */
    private function fromSnapshot(
        string $period,
        string $cle,
        ChallengeMetric $metric,
        ?string $sport,
    ): array {
        return LeaderboardSnapshot::query()
            ->where('period', $period)
            ->where('period_key', $cle)
            ->where('metric', $metric->value)
            ->where('sport', $sport)
            ->with('member')
            ->orderBy('rank')
            ->get()
            ->map(fn (LeaderboardSnapshot $ligne) => [
                'rank' => (int) $ligne->rank,
                'member' => [
                    'uuid' => $ligne->member->uuid,
                    'full_name' => $ligne->member->fullName(),
                    'initials' => $ligne->member->initials(),
                    'matricule' => $ligne->member->matricule,
                    'photo_url' => $ligne->member->photoUrl(),
                ],
                'member_id' => (int) $ligne->member_id,
                'value' => (int) $ligne->value,
                'activities' => (int) $ligne->activities,
            ])
            ->values()
            ->all();
    }

    /**
     * La requête de base : ce qui a le droit d'entrer dans un classement.
     *
     * @return Builder<Activity>
     */
    private function scope(?string $sport, Carbon $debut, Carbon $fin): Builder
    {
        $query = Activity::query()
            // Seules les sorties TERMINÉES : une sortie en cours n'a pas de
            // statistiques fiables, et une sortie abandonnée n'a rien à
            // classer.
            ->where('activities.status', ActivityStatus::Completed)
            // ET SURTOUT : jamais une sortie privée. Voir l'en-tête.
            ->where('activities.visibility', '!=', ActivityVisibility::Private)
            ->whereBetween('activities.started_at', [$debut, $fin]);

        if ($sport !== null) {
            $query->where('activities.sport', $sport);
        }

        return $query;
    }

    /**
     * Attribue les rangs, en gérant les ex æquo.
     *
     * Deux membres à 120 km partagent la 1re place, et le suivant est 3e — la
     * convention du sport. Les numéroter 1, 2, 3 donnerait une deuxième place
     * à quelqu'un qui a fait exactement autant que le premier.
     *
     * @param  list<object>  $lignes
     * @return list<array<string, mixed>>
     */
    private function rank(array $lignes): array
    {
        $classement = [];
        $rang = 0;
        $precedente = null;

        foreach ($lignes as $index => $ligne) {
            $valeur = (int) $ligne->valeur;

            if ($valeur !== $precedente) {
                $rang = $index + 1;
                $precedente = $valeur;
            }

            // `forceFill` et non le constructeur : `photo_path` n'est pas
            // massivement assignable, et c'est très bien ainsi — une photo ne
            // se change pas par un corps de requête. Ici on ne fait
            // qu'emprunter le modèle pour ses accesseurs de présentation.
            $membre = (new Member)->forceFill([
                'first_name' => $ligne->first_name,
                'last_name' => $ligne->last_name,
                'photo_path' => $ligne->photo_path,
            ]);

            $classement[] = [
                'rank' => $rang,
                'member' => [
                    'uuid' => $ligne->uuid,
                    'full_name' => $membre->fullName(),
                    'initials' => $membre->initials(),
                    'matricule' => $ligne->matricule,
                    'photo_url' => $membre->photoUrl(),
                ],
                'member_id' => (int) $ligne->member_id,
                'value' => $valeur,
                'activities' => (int) $ligne->activites,
            ];
        }

        return $classement;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array<string, mixed>|null
     */
    private function findMe(array $lignes, Member $viewer): ?array
    {
        foreach ($lignes as $ligne) {
            if ($ligne['member_id'] === $viewer->id) {
                return $ligne + ['total' => count($lignes)];
            }
        }

        // Absent du classement : le dire explicitement vaut mieux que `null`,
        // qui se confondrait avec « pas encore chargé ».
        return [
            'rank' => null,
            'value' => 0,
            'activities' => 0,
            'total' => count($lignes),
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Périodes                                                               */
    /* ---------------------------------------------------------------------- */

    /** `2026-W36`, `2026-09`, `2026`. */
    public static function periodKey(string $period, Carbon $date): string
    {
        return match ($period) {
            // ISO-8601 : la semaine commence le lundi, et l'année ISO peut
            // différer de l'année civile aux alentours du 1er janvier. Utiliser
            // `Y` au lieu de `o` ferait apparaître une « semaine 1 de 2026 » en
            // décembre 2025.
            'week' => $date->format('o-\WW'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y'),
        };
    }

    /**
     * Les bornes d'une clé de période.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(string $period, string $key): array
    {
        return match ($period) {
            'week' => (function () use ($key) {
                [$annee, $semaine] = explode('-W', $key);
                $debut = Carbon::now()->setISODate((int) $annee, (int) $semaine)->startOfWeek(Carbon::MONDAY);

                return [$debut->copy()->startOfDay(), $debut->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()];
            })(),
            'month' => (function () use ($key) {
                $debut = Carbon::createFromFormat('Y-m-d', $key.'-01')->startOfMonth();

                return [$debut->copy()->startOfDay(), $debut->copy()->endOfMonth()->endOfDay()];
            })(),
            default => (function () use ($key) {
                $debut = Carbon::createFromFormat('Y-m-d', $key.'-01-01')->startOfYear();

                return [$debut->copy()->startOfDay(), $debut->copy()->endOfYear()->endOfDay()];
            })(),
        };
    }

    /**
     * Fige un classement. Appelé par `cyclo:snapshot-leaderboards`.
     *
     * Idempotent : refiger une période déjà figée la réécrit à l'identique. Ce
     * n'est pas une contradiction avec « on ne retouche pas » — la commande ne
     * tourne que sur des périodes closes, dont le calcul ne bouge plus que si
     * une synchronisation tardive arrive. Relancer manuellement reste possible,
     * et c'est un acte délibéré.
     *
     * @return int Nombre de lignes figées.
     */
    public function freeze(string $period, string $key, ChallengeMetric $metric, ?string $sport): int
    {
        [$debut, $fin] = self::bounds($period, $key);

        if ($fin->isFuture()) {
            // On ne fige pas une période en cours : elle n'est pas finie.
            return 0;
        }

        $lignes = $this->compute($metric, $sport, $debut, $fin);
        $maintenant = now();

        DB::transaction(function () use ($period, $key, $metric, $sport, $lignes, $maintenant): void {
            LeaderboardSnapshot::query()
                ->where('period', $period)
                ->where('period_key', $key)
                ->where('metric', $metric->value)
                ->where('sport', $sport)
                ->delete();

            foreach ($lignes as $ligne) {
                LeaderboardSnapshot::create([
                    'period' => $period,
                    'period_key' => $key,
                    'metric' => $metric->value,
                    'sport' => $sport,
                    'member_id' => $ligne['member_id'],
                    'rank' => $ligne['rank'],
                    'value' => $ligne['value'],
                    'activities' => $ligne['activities'],
                    'captured_at' => $maintenant,
                ]);
            }
        });

        return count($lignes);
    }
}
