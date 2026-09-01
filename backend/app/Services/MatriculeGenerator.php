<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Génération du matricule club : CD-000001, CD-000002, …
 *
 * Le problème à résoudre est la concurrence. Deux inscriptions simultanées
 * qui liraient toutes les deux « dernier matricule = CD-000041 » attribueraient
 * CD-000042 au même moment, et l'une des deux échouerait sur la contrainte
 * d'unicité — au pire moment, celui de l'inscription d'un membre.
 *
 * Deux protections, l'une pour la logique métier, l'autre pour la vérité :
 *
 *  1. la lecture du dernier matricule se fait sous verrou d'écriture
 *     (`SELECT ... FOR UPDATE`), dans la transaction de l'appelant ;
 *  2. la contrainte `UNIQUE` en base reste le dernier rempart, et une
 *     collision improbable est réessayée plutôt que remontée à l'utilisateur.
 *
 * Un matricule n'est JAMAIS réattribué, même après le départ d'un membre :
 * les paiements et activités anciens y font référence dans les archives papier
 * du club.
 */
final class MatriculeGenerator
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Réserve le prochain matricule disponible.
     *
     * À appeler DANS une transaction : le verrou posé n'a de sens que jusqu'au
     * commit. Sans transaction englobante, il serait relâché immédiatement et
     * ne protégerait rien.
     */
    public function next(): string
    {
        $prefix = (string) config('cyclo.matricule.prefix', 'CD');
        $separator = (string) config('cyclo.matricule.separator', '-');
        $padding = (int) config('cyclo.matricule.padding', 6);

        $sequence = $this->nextSequence($prefix, $separator);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->build($prefix, $separator, $padding, $sequence);

            // Les membres supprimés (soft delete) comptent : leur matricule
            // reste pris.
            $taken = Member::withTrashed()->where('matricule', $candidate)->exists();

            if (! $taken) {
                return $candidate;
            }

            // On AVANCE. Réessayer le même numéro serait vain : rien n'a
            // changé entre deux tours, et la boucle échouerait cinq fois pour
            // rien. Le cas se produit dès qu'un matricule a été attribué à la
            // main ou importé au-dessus du dernier créé.
            $sequence++;
        }

        throw new RuntimeException(
            'Impossible de générer un matricule unique après '.self::MAX_ATTEMPTS.' tentatives.'
        );
    }

    /**
     * Numéro suivant, lu sous verrou d'écriture.
     */
    private function nextSequence(string $prefix, string $separator): int
    {
        $pattern = $prefix.$separator.'%';

        // `lockForUpdate` sérialise les inscriptions simultanées : la seconde
        // attend que la première ait écrit son membre avant de lire.
        //
        // Le tri porte sur le MATRICULE, pas sur l'identifiant : le dernier
        // membre créé n'a pas forcément le plus grand numéro — un import ou
        // une reprise de l'historique papier attribue des matricules dans un
        // ordre quelconque. Trier par `id` renverrait alors un numéro déjà
        // pris. La longueur passe avant la valeur pour rester juste au-delà
        // du remplissage prévu ('CD-1000000' est supérieur à 'CD-999999',
        // alors qu'il lui est lexicographiquement inférieur).
        $last = Member::withTrashed()
            ->where('matricule', 'like', $pattern)
            ->orderByRaw('LENGTH(matricule) DESC, matricule DESC')
            ->lockForUpdate()
            ->value('matricule');

        if ($last === null) {
            return 1;
        }

        $digits = preg_replace('/\D+/', '', substr($last, strlen($prefix.$separator)));

        return ((int) $digits) + 1;
    }

    private function build(string $prefix, string $separator, int $padding, int $sequence): string
    {
        return $prefix.$separator.str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }

    /**
     * Variante autonome, qui ouvre sa propre transaction.
     * Pratique pour un import ou une commande d'administration.
     */
    public function nextInOwnTransaction(): string
    {
        return DB::transaction(fn (): string => $this->next());
    }
}
