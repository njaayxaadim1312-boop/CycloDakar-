<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un jeton de notification push, rattaché à un appareil.
 *
 * L'unicité porte sur le JETON seul, pas sur le couple (utilisateur, jeton) :
 * un appareil prêté ou revendu doit changer de propriétaire, pas être
 * dupliqué. Sans cela, l'ancien utilisateur continuerait de recevoir sur un
 * téléphone qui n'est plus le sien.
 */
final class PushToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Enregistre ou réattribue un jeton.
     *
     * `updateOrCreate` sur le jeton : si l'appareil appartenait à quelqu'un
     * d'autre, il change de main. C'est exactement ce qu'on veut quand un
     * téléphone est prêté — l'ancien propriétaire cesse de recevoir.
     */
    public static function register(
        User $user,
        string $token,
        ?string $deviceName = null,
        ?string $platform = null,
    ): self {
        return self::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'platform' => $platform,
                'last_used_at' => now(),
            ],
        );
    }
}
