<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Oublie l'utilisateur mis en cache par le garde d'authentification.
     *
     * En production, chaque requête HTTP part d'une application neuve : le
     * garde Sanctum relit le jeton en base à chaque fois, et un jeton révoqué
     * renvoie donc bien 401.
     *
     * Dans un test, en revanche, plusieurs appels se succèdent DANS LA MÊME
     * instance d'application. `RequestGuard::user()` conserve l'utilisateur
     * résolu au premier appel : une requête faite après une révocation
     * réussirait alors à tort, non parce que le code est faux mais parce que
     * le garde n'a jamais relu la base.
     *
     * À appeler entre deux requêtes dès qu'un test révoque un jeton ou
     * désactive un compte, pour se replacer dans les conditions réelles.
     */
    protected function forgetAuthenticatedUser(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
