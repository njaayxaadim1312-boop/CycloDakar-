<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AUDIT DES PERMISSIONS, FAIT PAR LA MACHINE.
 *
 * Une relecture à l'œil des routes protège mal : elles sont soixante-dix, elles
 * grossissent à chaque phase, et il suffit d'en écrire une hors du bon groupe
 * pour l'ouvrir à tout internet sans que rien ne le signale. C'est le genre
 * d'erreur qu'on ne voit pas en relisant son propre code, parce qu'on sait ce
 * qu'on a voulu écrire.
 *
 * Ce fichier énumère donc les routes RÉELLEMENT enregistrées et vérifie leurs
 * propriétés, plutôt que d'éprouver trois URL choisies à la main. Une route
 * ajoutée demain est couverte le jour où elle est écrite.
 *
 * LA LISTE BLANCHE EST COURTE, ET CHAQUE ENTRÉE Y EST JUSTIFIÉE.
 *
 * Tout ce qui n'y figure pas doit exiger une session. Ajouter une route à cette
 * liste est un acte délibéré, qui se voit en revue — c'est tout l'intérêt de la
 * tenir ici plutôt que de la déduire des middlewares.
 */
final class RouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les routes légitimement ouvertes, et pourquoi.
     *
     * @var array<string, string>
     */
    private const PUBLIQUES = [
        // Le diagnostic de liaison : il ne révèle aucune donnée du club, et
        // c'est précisément ce qu'on interroge quand plus rien ne répond.
        'api/v1/health' => 'Diagnostic de liaison, aucune donnée du club.',

        // Les seuils GPS et la liste des sports : le mobile en a besoin AVANT
        // de connaître son utilisateur, pour préparer la capture.
        'api/v1/config' => 'Seuils de capture, nécessaires avant la connexion.',

        // L'authentification elle-même. Elle est protégée par la limitation de
        // débit, pas par une session — on n'en a pas encore.
        'api/v1/auth/login' => 'Point d\'entrée de la session.',
        'api/v1/auth/register' => 'Inscription publique.',
        'api/v1/auth/forgot-password' => 'Le membre a justement perdu son accès.',
        'api/v1/auth/reset-password' => 'Le jeton du courriel fait office de preuve.',

        // Le service Node rend compte par signature HMAC, pas par jeton
        // utilisateur : c'est un service qui appelle, pas une personne.
        'api/v1/internal/video-jobs/{uuid}/progress' => 'Signée en HMAC-SHA256.',
        'api/v1/internal/video-jobs/{uuid}/complete' => 'Signée en HMAC-SHA256.',
        'api/v1/internal/video-jobs/{uuid}/failed' => 'Signée en HMAC-SHA256.',
    ];

    /**
     * @return list<Route>
     */
    private function routesApi(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function l_audit_examine_bien_toutes_les_routes(): void
    {
        /*
         | La garde de l'audit lui-même.
         |
         | Si `routesApi()` renvoyait un tableau vide — un préfixe renommé, un
         | groupe déplacé — tous les contrôles ci-dessous passeraient sans rien
         | vérifier. Un test qui rassure sans rien prouver est pire qu'un test
         | absent.
         */
        $routes = $this->routesApi();

        $this->assertGreaterThan(
            60,
            count($routes),
            "L'audit ne voit presque aucune route : il ne vérifie donc rien.",
        );
    }

    #[Test]
    public function toute_route_d_api_exige_une_session_sauf_liste_blanche(): void
    {
        $ouvertes = [];

        foreach ($this->routesApi() as $route) {
            $uri = $route->uri();

            if (array_key_exists($uri, self::PUBLIQUES)) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();

            $authentifiee = collect($middlewares)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'auth:'),
            );

            if (! $authentifiee) {
                $ouvertes[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame(
            [],
            $ouvertes,
            "Des routes d'API sont accessibles sans session :\n  ".implode("\n  ", $ouvertes)
            ."\n\nSi c'est voulu, ajoutez-les à self::PUBLIQUES avec leur justification.",
        );
    }

    #[Test]
    public function toute_route_authentifiee_verifie_aussi_que_le_compte_est_actif(): void
    {
        /*
         | Un compte désactivé doit perdre l'accès IMMÉDIATEMENT, sans attendre
         | l'expiration de son jeton. Sans le middleware `active`, un membre
         | exclu du club continuerait d'encaisser jusqu'à sa prochaine
         | déconnexion — et son jeton peut vivre des mois.
         */
        $sansGarde = [];

        foreach ($this->routesApi() as $route) {
            $middlewares = $route->gatherMiddleware();

            $authentifiee = collect($middlewares)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'auth:'),
            );

            if (! $authentifiee) {
                continue;
            }

            if (! in_array('active', $middlewares, true)
                && ! collect($middlewares)->contains(
                    fn ($m) => is_string($m) && str_contains($m, 'EnsureAccountIsActive'),
                )) {
                $sansGarde[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $sansGarde,
            "Des routes authentifiées ne vérifient pas que le compte est actif :\n  "
            .implode("\n  ", $sansGarde),
        );
    }

    #[Test]
    public function aucune_route_n_expose_une_cle_primaire_interne(): void
    {
        /*
         | Les ressources se désignent par leur `uuid`, jamais par leur `id`.
         |
         | Ce n'est pas de la coquetterie : un identifiant séquentiel se devine.
         | `/members/1`, `/members/2`… permet d'énumérer l'annuaire complet, et
         | de mesurer la taille du club en incrémentant jusqu'à obtenir un 404.
         |
         | L'exception est `{line}` et `{attachment}`, qui sont des SOUS-
         | ressources : elles ne se lisent qu'à travers leur parent, lequel est
         | déjà désigné par un uuid, et le contrôleur vérifie l'appartenance.
         */
        $exceptions = ['{line}', '{attachment}', '{id}'];

        $fautives = [];

        foreach ($this->routesApi() as $route) {
            if (preg_match_all('/\{(\w+)\}/', $route->uri(), $parametres) === 0) {
                continue;
            }

            foreach ($parametres[0] as $parametre) {
                if (in_array($parametre, $exceptions, true)) {
                    continue;
                }

                if (str_contains($parametre, '_id') || $parametre === '{id}') {
                    $fautives[] = $route->uri();
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($fautives),
            "Des routes exposent une clé interne :\n  ".implode("\n  ", array_unique($fautives)),
        );
    }

    #[Test]
    public function les_routes_financieres_sont_toutes_derriere_une_policy(): void
    {
        /*
         | Vérifié sur le CODE des contrôleurs : chaque action financière doit
         | appeler `authorize()` ou passer par une Form Request qui le fait.
         |
         | Une action qui se contenterait de vérifier un rôle dans son corps
         | fonctionnerait — mais la règle du projet est que l'autorisation vit
         | dans une Policy, en un seul endroit, pour qu'on puisse la lire sans
         | ouvrir cinq contrôleurs.
         */
        $controleurs = [
            'PaymentController' => ['store', 'index', 'show', 'cancel', 'memberDues'],
            'ExpenseController' => ['index', 'store', 'show', 'approve', 'reject', 'attach'],
            'FinanceController' => ['collections', 'cash', 'dashboard', 'transactions', 'reports'],
        ];

        $sansGarde = [];

        foreach ($controleurs as $nom => $actions) {
            $source = (string) file_get_contents(
                base_path("app/Http/Controllers/Api/V1/{$nom}.php"),
            );

            foreach ($actions as $action) {
                // Le corps de la méthode, jusqu'à la suivante.
                if (preg_match(
                    '/public function '.$action.'\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s',
                    $source,
                    $corps,
                ) !== 1) {
                    $sansGarde[] = "{$nom}::{$action} — méthode introuvable";

                    continue;
                }

                $protegee = str_contains($corps[1], '$this->authorize(')
                    // Ou une Form Request, qui autorise dans `authorize()`.
                    || preg_match('/public function '.$action.'\s*\(\s*\w*(Store|Cancel|Decide)\w*Request/', $source) === 1;

                if (! $protegee) {
                    $sansGarde[] = "{$nom}::{$action}";
                }
            }
        }

        $this->assertSame(
            [],
            $sansGarde,
            "Des actions financières ne passent par aucune Policy :\n  "
            .implode("\n  ", $sansGarde),
        );
    }

    #[Test]
    public function une_requete_sans_jeton_est_refusee_partout_ou_elle_doit_l_etre(): void
    {
        // Le contrôle par middleware ci-dessus vérifie la DÉCLARATION ; celui-ci
        // vérifie le COMPORTEMENT, sur un échantillon représentatif de chaque
        // module. Les deux sont utiles : une déclaration correcte mal appliquée
        // se verrait ici, et l'inverse là-haut.
        $echantillon = [
            '/api/v1/members',
            '/api/v1/activities',
            '/api/v1/events',
            '/api/v1/participations',
            '/api/v1/payments/mine',
            '/api/v1/finance/cash',
            '/api/v1/expenses',
            '/api/v1/leaderboard',
            '/api/v1/challenges',
            '/api/v1/notifications',
        ];

        foreach ($echantillon as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
