<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Réception d'un lot de points GPS.
 *
 * La validation reste **volontairement permissive** sur la qualité des points :
 * un point imprécis ou aberrant n'est pas une erreur de requête, c'est une
 * réalité du GPS. Le rejeter en 422 ferait échouer tout le lot — donc perdre
 * les points valides qui l'accompagnent — pour un problème que le filtre sait
 * traiter proprement, point par point.
 *
 * On valide donc la FORME (structure, bornes physiques, types) et on laisse la
 * CRÉDIBILITÉ au `GpsFilter`.
 */
final class StorePointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 500 points par lot au maximum : au-delà, la requête devient
            // lourde sur un réseau mobile et un échec coûte cher à rejouer.
            'points' => ['required', 'array', 'min:1', 'max:500'],

            'points.*.seq' => ['required', 'integer', 'min:0'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.recorded_at' => ['required', 'date'],

            // Bornes physiques larges : le point de la fosse des Mariannes au
            // sommet de l'Everest. Ce qui sort de là est une erreur de format.
            'points.*.altitude_m' => ['nullable', 'numeric', 'between:-500,9000'],
            'points.*.speed_mps' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'points.*.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'points.*.heading_deg' => ['nullable', 'numeric', 'between:0,360'],
            'points.*.is_paused' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.required' => 'Aucun point à enregistrer.',
            'points.max' => 'Un lot ne peut pas dépasser 500 points.',
            'points.*.seq.required' => "Chaque point doit porter sa position dans la trace.",
            'points.*.recorded_at.required' => 'Chaque point doit être horodaté.',
        ];
    }
}
