<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Mot de passe oublié et réinitialisation.
 *
 * Le lien de réinitialisation part par courriel. Un membre inscrit avec son
 * seul numéro de téléphone ne peut donc pas se dépanner tout seul : on le lui
 * dit clairement plutôt que de faire semblant. L'envoi par SMS est une
 * évolution identifiée (voir docs/security.md).
 */
final class PasswordResetController extends Controller
{
    /**
     * Demande de réinitialisation.
     *
     * La réponse est TOUJOURS la même, que le compte existe ou non : sinon
     * l'API deviendrait un moyen de vérifier qui est membre du club.
     * Seul le cas « compte trouvé mais sans adresse email » fait exception :
     * l'information est nécessaire, et elle n'est renvoyée qu'à quelqu'un qui
     * connaissait déjà l'identifiant.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $generic = 'Si un compte correspond à cet identifiant, un lien de réinitialisation vient d\'être envoyé.';

        $user = User::findByLogin($request->string('login')->toString());

        if ($user === null) {
            return ApiResponse::ok(['message' => $generic]);
        }

        if ($user->email === null) {
            return ApiResponse::error(
                message: "Aucune adresse email n'est associée à ce compte. Contactez un responsable du club pour réinitialiser votre mot de passe.",
                status: 422,
                code: 'NO_EMAIL_ON_ACCOUNT',
            );
        }

        Password::sendResetLink(['email' => $user->email]);

        return ApiResponse::ok(['message' => $generic]);
    }

    /**
     * Réinitialisation effective.
     *
     * Après changement, TOUS les jetons sont révoqués : si la demande fait
     * suite à une compromission, l'intrus doit perdre l'accès immédiatement.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::findByLogin($request->string('login')->toString());

        if ($user?->email === null) {
            return ApiResponse::error(
                message: 'Ce lien de réinitialisation n\'est plus valide.',
                status: 422,
                code: 'INVALID_RESET_TOKEN',
            );
        }

        $status = Password::reset(
            [
                'email' => $user->email,
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
                'token' => $request->string('token')->toString(),
            ],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            return ApiResponse::error(
                message: 'Ce lien de réinitialisation est invalide ou a expiré.',
                status: 422,
                code: 'INVALID_RESET_TOKEN',
            );
        }

        return ApiResponse::ok([
            'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.',
        ]);
    }
}
