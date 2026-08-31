<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Création et mise à jour des fiches membres.
 *
 * Tout passe par ce service plutôt que par les contrôleurs : la génération du
 * matricule, la normalisation du téléphone et la création de la fiche à
 * l'inscription sont trois choses qui doivent se comporter identiquement,
 * qu'on vienne de l'inscription publique, de l'écran d'administration ou d'un
 * import. Dupliquer cette logique, c'est garantir qu'elle divergera.
 */
final class MemberService
{
    public function __construct(
        private readonly MatriculeGenerator $matricules,
    ) {}

    /**
     * Crée la fiche club d'un compte qui vient d'être créé.
     *
     * Appelée à l'inscription. Le formulaire d'inscription ne demande qu'un
     * nom complet — c'est bien plus rapide à saisir sur un téléphone — et on
     * le découpe ici : premier mot = prénom, reste = nom. Le membre peut
     * corriger ensuite depuis son profil, et un responsable aussi.
     */
    public function createForUser(User $user, ?string $fullName = null): Member
    {
        [$firstName, $lastName] = $this->splitName($fullName ?? $user->name);

        return DB::transaction(function () use ($user, $firstName, $lastName): Member {
            $member = new Member;
            $member->fill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $user->phone,
                'email' => $user->email,
                'joined_at' => now()->toDateString(),
            ]);

            $member->user_id = $user->id;
            $member->matricule = $this->matricules->next();
            $member->status = MemberStatus::Active;
            $member->save();

            return $member;
        });
    }

    /**
     * Crée une fiche depuis l'écran d'administration.
     *
     * Le membre n'a pas forcément de compte : un adhérent sans smartphone doit
     * quand même avoir un matricule et un QR Code pour les collectes.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $photo = null): Member
    {
        return DB::transaction(function () use ($data, $photo): Member {
            $member = new Member;
            // `status` est retiré du fill : il n'est pas assignable en masse
            // (il décide de la place du membre dans l'effectif et dans les
            // collectes) et il est posé explicitement juste après.
            $member->fill($this->normalize(Arr::except($data, ['status'])));

            $member->matricule = $this->matricules->next();
            $member->status = isset($data['status'])
                ? MemberStatus::from((string) $data['status'])
                : MemberStatus::Active;

            $member->save();

            if ($photo !== null) {
                $this->replacePhoto($member, $photo);
            }

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Member $member, array $data, ?UploadedFile $photo = null): Member
    {
        return DB::transaction(function () use ($member, $data, $photo): Member {
            $member->fill($this->normalize(Arr::except($data, ['status'])));

            // Le statut n'est pas assignable en masse : il change la place du
            // membre dans l'effectif et dans les collectes.
            if (isset($data['status'])) {
                $member->status = MemberStatus::from((string) $data['status']);
            }

            $member->save();

            if ($photo !== null) {
                $this->replacePhoto($member, $photo);
            }

            // La fiche club fait autorité sur les coordonnées : on répercute
            // sur le compte de connexion, sinon le membre changerait son
            // numéro dans son profil et ne pourrait plus se connecter avec.
            $this->syncToUser($member);

            return $member;
        });
    }

    /**
     * Remplace la photo, en supprimant l'ancienne.
     *
     * Sans suppression, chaque changement laisserait un fichier orphelin :
     * après deux ans, le disque serait plein de photos que plus rien ne
     * référence.
     */
    public function replacePhoto(Member $member, UploadedFile $photo): void
    {
        $disk = Storage::disk(config('cyclo.uploads.public_disk'));
        $previous = $member->photo_path;

        // Nom régénéré : on n'utilise jamais celui fourni par le client.
        $path = $photo->storeAs(
            'members',
            Str::uuid().'.'.$photo->extension(),
            ['disk' => config('cyclo.uploads.public_disk')],
        );

        $member->forceFill(['photo_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }
    }

    public function removePhoto(Member $member): void
    {
        if ($member->photo_path === null) {
            return;
        }

        Storage::disk(config('cyclo.uploads.public_disk'))->delete($member->photo_path);
        $member->forceFill(['photo_path' => null])->save();
    }

    /**
     * Répercute les coordonnées de la fiche sur le compte de connexion.
     */
    private function syncToUser(Member $member): void
    {
        $user = $member->user;

        if ($user === null) {
            return;
        }

        $user->forceFill([
            'name' => $member->fullName(),
            'phone' => $member->phone ?? $user->phone,
            'email' => $member->email ?? $user->email,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone'] = PhoneNumber::normalize($data['phone']);
        }

        if (array_key_exists('email', $data) && is_string($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email'])) ?: null;
        }

        foreach (['first_name', 'last_name'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Découpe « Awa Ndiaye Fall » en prénom « Awa » et nom « Ndiaye Fall ».
     *
     * C'est une heuristique, pas une vérité : au Sénégal l'usage courant place
     * le prénom en premier. Le membre corrige depuis son profil si besoin.
     *
     * @return array{string, string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return ['Membre', 'Cyclo Dakar'];
        }

        if (count($parts) === 1) {
            // Un seul mot : on ne peut pas inventer de nom de famille.
            return [$parts[0], '—'];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }
}
