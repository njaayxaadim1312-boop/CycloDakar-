<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PersonalDataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les données personnelles : les emporter, ou les faire effacer.
 *
 * DEUX ROUTES, ET AUCUNE NE PREND D'IDENTIFIANT.
 *
 * On n'exporte et on n'efface que SON PROPRE compte, celui de la session. Un
 * paramètre `user` transformerait l'export RGPD en fuite de l'annuaire complet :
 * ces réponses contiennent les traces GPS, les téléphones et les contacts
 * d'urgence.
 *
 * Même un administrateur ne peut pas déclencher l'effacement d'un autre par
 * ici. Radier un membre est un autre geste, qui passe par l'annuaire, laisse la
 * fiche en place et se trace au journal d'audit.
 *
 * LA SUPPRESSION DEMANDE LE MOT DE PASSE.
 *
 * C'est irréversible, et c'est le seul endroit de l'application où un
 * téléphone laissé déverrouillé sur une table permettrait de détruire un compte
 * en deux appuis. Le mot de passe est la seule chose qu'un passant n'a pas.
 */
final class PersonalDataController extends Controller
{
    public function __construct(
        private readonly PersonalDataService $donnees,
    ) {}

    /**
     * Tout ce que le club détient sur le membre connecté.
     *
     * Téléchargé en fichier plutôt qu'affiché : c'est une archive qu'on garde,
     * pas une page qu'on lit. Le nom porte la date, pour qu'on retrouve la
     * bonne quand on en a demandé plusieurs.
     */
    public function export(Request $request): StreamedResponse
    {
        $contenu = $this->donnees->export($request->user());

        $nom = 'cyclo-dakar-mes-donnees-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($contenu): void {
            echo json_encode(
                $contenu,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }, $nom, [
            'Content-Type' => 'application/json; charset=UTF-8',
            // Une archive de données personnelles ne se met jamais en cache
            // chez un intermédiaire.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Efface le compte.
     *
     * Ce qui appartient au membre part ; ce qui engage la comptabilité du club
     * est ANONYMISÉ. La réponse dit exactement ce qui a été fait, poste par
     * poste : une suppression qui répondrait « c'est fait » sans détailler
     * laisserait le membre se demander si ses traces ont vraiment disparu.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            // Une confirmation écrite, en toutes lettres. Un simple bouton se
            // clique par erreur ; « SUPPRIMER » se tape volontairement.
            'confirmation' => ['required', 'string', 'in:SUPPRIMER'],
        ], [
            'password.required' => 'Votre mot de passe est nécessaire pour confirmer.',
            'confirmation.in' => 'Tapez SUPPRIMER en majuscules pour confirmer.',
        ]);

        $user = $request->user();

        if (! \Illuminate\Support\Facades\Hash::check($request->string('password'), $user->password)) {
            return ApiResponse::error(
                message: 'Mot de passe incorrect.',
                status: 422,
                code: 'INVALID_PASSWORD',
            );
        }

        $bilan = $this->donnees->forget($user);

        return ApiResponse::ok([
            'deleted' => true,
            'details' => $bilan,
            'conserve' => "Les écritures comptables auxquelles vous avez participé "
                .'sont conservées sans votre nom : elles engagent la caisse du club '
                ."et figurent dans des rapports déjà présentés en assemblée.",
        ]);
    }
}
