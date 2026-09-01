<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatus;
use App\Support\PhoneNumber;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fiche club d'un membre.
 *
 * Distinction importante avec `User` :
 *  - `User` est une identité TECHNIQUE : identifiants, mot de passe, rôle, session ;
 *  - `Member` est une identité CLUB : matricule, photo, adhésion, QR Code.
 *
 * Un membre peut exister SANS compte (`user_id` nul) : tous les membres du club
 * n'ont pas de smartphone, et ils doivent pourtant figurer dans l'effectif et
 * dans les collectes.
 *
 * `matricule`, `qr_token` et `status` ne sont pas assignables en masse : ce
 * sont des attributs que le serveur décide, pas des champs de formulaire.
 */
#[Fillable([
    'first_name',
    'last_name',
    'phone',
    'email',
    'birth_date',
    'gender',
    'joined_at',
    'emergency_contact_name',
    'emergency_contact_phone',
    'notes',

    // Objectifs hebdomadaires : le membre les fixe lui-meme, ce sont bien
    // des champs de formulaire.
    'weekly_distance_goal_m',
    'weekly_moving_time_goal_s',
    'weekly_activities_goal',
])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_at' => 'date',
            'qr_rotated_at' => 'datetime',
            'status' => MemberStatus::class,
            'weekly_distance_goal_m' => 'integer',
            'weekly_moving_time_goal_s' => 'integer',
            'weekly_activities_goal' => 'integer',
        ];
    }

    /**
     * Les URL exposent l'uuid, jamais l'auto-incrément : sinon on pourrait
     * énumérer les fiches et connaître l'effectif du club.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            $member->uuid ??= (string) Str::uuid();
            $member->qr_token ??= self::generateQrToken();
            $member->joined_at ??= now()->toDateString();
        });
    }

    /* ---------------------------------------------------------------------- */
    /* Relations                                                              */
    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ---------------------------------------------------------------------- */
    /* QR Code                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * Jeton opaque de 43 caractères (32 octets aléatoires en base64 URL).
     *
     * Il ne contient AUCUNE donnée personnelle : ni nom, ni téléphone, ni
     * matricule. Photographié par un tiers, il ne révèle rien ; il sert
     * uniquement à interroger le serveur, qui décide alors quoi renvoyer et
     * à qui. Voir docs/security.md §7.
     */
    /**
     * Objectifs hebdomadaires, tels que l'API les expose.
     *
     * Unites SI, comme partout ailleurs. Ils servent de reference aux anneaux
     * d'activite : sans objectif, un anneau ne pourrait qu'inventer une
     * echelle, ce que le projet s'interdit.
     *
     * @return array{distance_m: int, moving_time_s: int, activities: int}
     */
    public function weeklyGoals(): array
    {
        return [
            'distance_m' => (int) $this->weekly_distance_goal_m,
            'moving_time_s' => (int) $this->weekly_moving_time_goal_s,
            'activities' => (int) $this->weekly_activities_goal,
        ];
    }

    public static function generateQrToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Révoque le QR Code actuel et en émet un nouveau.
     * À utiliser si un membre pense que son QR a été copié.
     */
    public function rotateQrToken(): void
    {
        $this->forceFill([
            'qr_token' => self::generateQrToken(),
            'qr_rotated_at' => now(),
        ])->save();
    }

    /* ---------------------------------------------------------------------- */
    /* Attributs calculés                                                     */
    /* ---------------------------------------------------------------------- */

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Initiales pour l'avatar par défaut : « AN ». */
    public function initials(): string
    {
        $first = mb_substr($this->first_name, 0, 1);
        $last = mb_substr($this->last_name, 0, 1);

        return mb_strtoupper($first.$last);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path === null
            ? null
            : Storage::disk(config('cyclo.uploads.public_disk'))->url($this->photo_path);
    }

    public function formattedPhone(): ?string
    {
        return PhoneNumber::format($this->phone);
    }

    /** Ancienneté en années révolues. */
    public function seniorityYears(): int
    {
        return (int) $this->joined_at?->diffInYears(now());
    }

    /* ---------------------------------------------------------------------- */
    /* Filtres                                                                */
    /* ---------------------------------------------------------------------- */

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active);
    }

    /**
     * Recherche intelligente — le cœur du besoin exprimé par le club.
     *
     * Le collecteur ne doit plus écrire les noms à la main : il tape trois
     * lettres et sélectionne. La recherche couvre donc, en une seule saisie :
     *   - le prénom, le nom, ou les deux dans n'importe quel ordre ;
     *   - le matricule (« CD-000042 » ou simplement « 42 ») ;
     *   - le téléphone, sous n'importe quelle mise en forme ;
     *   - l'adresse email.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        // Le téléphone est normalisé AVANT comparaison : sinon « 77 123 45 67 »
        // ne trouverait jamais « 771234567 » stocké en base.
        $phone = PhoneNumber::normalize($term);

        // « 42 » doit retrouver CD-000042 : on complète à la longueur du
        // matricule quand la saisie n'est que des chiffres.
        $matricule = null;
        if (ctype_digit($term)) {
            $padding = (int) config('cyclo.matricule.padding', 6);
            $matricule = config('cyclo.matricule.prefix')
                .config('cyclo.matricule.separator')
                .str_pad($term, $padding, '0', STR_PAD_LEFT);
        }

        $query->where(function (Builder $q) use ($like, $phone, $matricule, $term): void {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('matricule', 'like', $like)
                ->orWhere('email', 'like', $like)
                // « Awa Ndiaye » saisi en entier, dans un sens ou dans l'autre.
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$like]);

            if ($phone !== null) {
                $q->orWhere('phone', $phone);
            } elseif (ctype_digit($term)) {
                // Recherche partielle sur le numéro (« 1234 »).
                $q->orWhere('phone', 'like', '%'.$term.'%');
            }

            if ($matricule !== null) {
                $q->orWhere('matricule', $matricule);
            }
        });
    }
}
