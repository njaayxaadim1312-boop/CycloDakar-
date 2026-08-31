<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Écriture du journal d'audit.
 *
 * Une seule porte d'entrée pour toutes les traces : sans cela, chaque module
 * inventerait son propre format et le journal deviendrait illisible au moment
 * où on en a réellement besoin — c'est-à-dire quand quelque chose cloche.
 *
 * L'écriture est volontairement directe (pas de file d'attente) : une trace
 * qui arriverait « plus tard » ou se perdrait dans une file en panne ne vaut
 * rien. Elle fait partie de la transaction de l'opération auditée.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        Model $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'ip_address' => Request::ip(),
            // Tronqué : certains agents utilisateurs dépassent 255 caractères
            // et feraient échouer l'insertion — donc échouer l'opération
            // auditée elle-même.
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    /**
     * Trace le changement d'un seul attribut — le cas le plus fréquent
     * (rôle, statut).
     */
    public function logChange(
        string $action,
        Model $entity,
        string $attribute,
        mixed $from,
        mixed $to,
        ?string $reason = null,
    ): void {
        $this->log(
            action: $action,
            entity: $entity,
            old: [$attribute => $from],
            new: [$attribute => $to],
            reason: $reason,
        );
    }
}
