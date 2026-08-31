<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client du service Node.js (rendu vidéo, temps réel).
 *
 * Laravel est la source de vérité ; Node est un exécutant. Ce client est le
 * SEUL point du backend qui parle à Node, ce qui garantit que la signature
 * HMAC est appliquée partout de la même façon.
 *
 * Contrat de signature (miroir de services/src/signature.js) :
 *
 *   base      = "{timestamp}.{corps JSON brut}"
 *   signature = HMAC-SHA256(base, secret) en hexadécimal
 *
 *   X-Cyclo-Timestamp: <epoch secondes>
 *   X-Cyclo-Signature: <hex>
 *
 * L'horodatage fait partie de la base signée : sans lui, une requête
 * interceptée pourrait être rejouée indéfiniment.
 */
final class NodeServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $secret,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('cyclo.node_service.url'),
            secret: config('cyclo.node_service.secret'),
            timeout: (int) config('cyclo.node_service.timeout_s'),
        );
    }

    /**
     * Le service est-il configuré ? Sans secret partagé, aucun appel n'est
     * possible : mieux vaut le détecter au démarrage qu'au premier rendu.
     */
    public function isConfigured(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * Sonde d'intégration : vérifie que Node répond ET que le secret partagé
     * est identique des deux côtés. Utilisée par `php artisan cyclo:doctor`.
     *
     * @return array{ok: bool, message: string, detail?: mixed}
     */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'NODE_SERVICE_SECRET absent de backend/.env.',
            ];
        }

        try {
            $response = $this->post('/internal/ping', ['from' => 'laravel']);
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'message' => "Service Node injoignable sur {$this->baseUrl} — lancez « npm run dev » dans services/.",
            ];
        }

        if ($response->status() === 401) {
            return [
                'ok' => false,
                'message' => 'Secret partagé incorrect : NODE_SERVICE_SECRET (backend) et SERVICE_SECRET (services) diffèrent.',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => "Réponse inattendue du service Node (HTTP {$response->status()}).",
            ];
        }

        return [
            'ok' => true,
            'message' => 'Service Node joignable et secret partagé valide.',
            'detail' => $response->json('data'),
        ];
    }

    /**
     * PHASE 15 — Demande un rendu vidéo.
     *
     * Le job Laravel appellera cette méthode ; Node répond immédiatement puis
     * rappelle /api/v1/internal/video-jobs/{uuid}/complete quand le MP4 est prêt.
     *
     * @param  array<string, mixed>  $payload
     */
    public function requestVideoRender(array $payload): Response
    {
        return $this->post('/render', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'NODE_SERVICE_SECRET n\'est pas configuré : impossible de signer la requête.'
            );
        }

        // Le corps est sérialisé UNE seule fois : c'est exactement cette chaîne
        // d'octets qui est signée, puis envoyée. Re-sérialiser invaliderait la
        // signature (ordre des clés, échappement unicode).
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $this->secret);

        return Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Cyclo-Timestamp' => (string) $timestamp,
                'X-Cyclo-Signature' => $signature,
            ])
            ->withBody($body, 'application/json')
            ->post($this->baseUrl.$path);
    }
}
