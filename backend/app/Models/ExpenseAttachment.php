<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un justificatif de dépense.
 *
 * Le fichier vit sur le disque PRIVÉ et n'est jamais servi depuis `public/` :
 * une facture porte un fournisseur, un montant, parfois un numéro de compte.
 * Le téléchargement passe par une route contrôlée, qui vérifie le rôle.
 */
final class ExpenseAttachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $attachment->uuid ??= (string) Str::uuid();
        });

        // Le fichier suit la ligne : une pièce jointe supprimée en base sans
        // son fichier laisserait des octets orphelins que plus rien ne
        // désigne, et que personne ne penserait à nettoyer.
        static::deleted(function (self $attachment): void {
            Storage::disk(config('cyclo.uploads.private_disk'))->delete($attachment->path);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Une image s'affiche en aperçu, un PDF s'ouvre. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
