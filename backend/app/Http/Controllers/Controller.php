<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Contrôleur de base.
 *
 * `AuthorizesRequests` fournit `$this->authorize()`, qui délègue aux Policies.
 * Règle du projet : aucune vérification de rôle écrite à la main dans un
 * contrôleur — tout passe par une Policy, pour qu'une règle soit écrite une
 * seule fois et testable isolément (voir docs/security.md §2).
 */
abstract class Controller
{
    use AuthorizesRequests;
}
