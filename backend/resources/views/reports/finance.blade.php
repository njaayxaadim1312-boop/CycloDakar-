{{--
    Rapport financier — mise en page PDF.

    C'EST UNE PIÈCE D'ASSEMBLÉE GÉNÉRALE, PAS UNE PAGE WEB.

    Trois conséquences sur la mise en page :

    - **Tout est en noir sur blanc.** Ces rapports s'impriment, souvent en
      photocopie. Une couleur de marque qui sort grise et illisible ne sert
      personne ; le sens passe par le gras et l'alignement.
    - **Les montants sont alignés à droite, en tabulaire.** C'est ce qui permet
      de comparer deux lignes d'un coup d'œil, et de repérer un ordre de
      grandeur qui détonne.
    - **Les totaux se recomposent visiblement** : ouverture, recettes,
      dépenses, clôture. Quelqu'un doit pouvoir vérifier l'addition à la main,
      parce qu'en assemblée, quelqu'un le fera.

    DomPDF ne prend qu'un sous-ensemble de CSS : pas de flexbox, pas de grid.
    Les tableaux ne sont donc pas un raccourci, ce sont les seuls outils de
    mise en page disponibles ici.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport financier — {{ $rapport['period']['label'] }}</title>
    <style>
        @page { margin: 22mm 16mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #000;
        }

        h1 { font-size: 16pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 8mm 0 2mm; border-bottom: 0.4mm solid #000; padding-bottom: 1mm; }

        .entete { margin-bottom: 6mm; }
        .discret { color: #444; font-size: 9pt; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1.4mm 1.5mm; text-align: left; vertical-align: top; }
        th { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.2mm; border-bottom: 0.3mm solid #000; }
        tbody tr { border-bottom: 0.15mm solid #ccc; }

        /* Les montants : à droite, chiffres de largeur fixe. C'est ce qui rend
           une colonne comparable d'une ligne à l'autre. */
        .montant { text-align: right; white-space: nowrap; }

        .synthese td { padding: 1.8mm 1.5mm; }
        .synthese .libelle { width: 60%; }
        .total { font-weight: bold; border-top: 0.4mm solid #000; }

        .pied {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #444;
            text-align: center;
        }
    </style>
</head>
<body>

@php
    /** Un format unique pour tout le document : l'espace insécable évite qu'un
        montant se coupe en fin de ligne. */
    $fcfa = fn (int $montant) => number_format($montant, 0, ',', "\u{00A0}").' FCFA';
@endphp

<div class="entete">
    <h1>Cyclo Dakar — rapport financier</h1>
    <p class="discret">
        {{ $rapport['period']['label'] }}
        · {{ $rapport['account']['name'] }}
        · édité le {{ now()->format('d/m/Y à H:i') }}
    </p>
</div>

<h2>Synthèse</h2>

<table class="synthese">
    <tr>
        <td class="libelle">Solde d'ouverture</td>
        <td class="montant">{{ $fcfa($rapport['summary']['opening_balance']) }}</td>
    </tr>
    <tr>
        <td class="libelle">Recettes de la période</td>
        <td class="montant">+ {{ $fcfa($rapport['summary']['income']) }}</td>
    </tr>
    <tr>
        <td class="libelle">Dépenses de la période</td>
        <td class="montant">− {{ $fcfa($rapport['summary']['expenses']) }}</td>
    </tr>
    <tr class="total">
        <td class="libelle">Solde de clôture</td>
        <td class="montant">{{ $fcfa($rapport['summary']['closing_balance']) }}</td>
    </tr>
</table>

{{-- L'engagé est présenté À PART, hors du calcul du solde : une dépense en
     attente n'a aucune ligne au grand livre. L'inclure ferait un rapport faux
     (docs/finance.md, règle I4). --}}
@if ($rapport['summary']['committed_today'] > 0)
    <p class="discret" style="margin-top: 3mm;">
        À la date d'édition, {{ $fcfa($rapport['summary']['committed_today']) }} de dépenses
        sont engagées mais pas encore approuvées : elles ne figurent dans aucun
        chiffre ci-dessus, et rien n'est encore sorti de la caisse.
    </p>
@endif

@if (count($rapport['by_category']['income']) > 0)
    <h2>Recettes par poste</h2>
    <table>
        <thead>
            <tr>
                <th>Poste</th>
                <th class="montant">Opérations</th>
                <th class="montant">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rapport['by_category']['income'] as $poste)
                <tr>
                    <td>{{ $poste['name'] }}</td>
                    <td class="montant">{{ $poste['operations'] }}</td>
                    <td class="montant">{{ $fcfa($poste['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($rapport['by_category']['expenses']) > 0)
    <h2>Dépenses par poste</h2>
    <table>
        <thead>
            <tr>
                <th>Poste</th>
                <th class="montant">Opérations</th>
                <th class="montant">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rapport['by_category']['expenses'] as $poste)
                <tr>
                    <td>{{ $poste['name'] }}</td>
                    <td class="montant">{{ $poste['operations'] }}</td>
                    <td class="montant">{{ $fcfa($poste['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>Collectes</h2>

{{-- Hors période, et le rapport le dit : une créance n'appartient pas à un
     mois, elle existe tant qu'elle n'est pas réglée. --}}
<p class="discret">Situation à la date d'édition, toutes collectes confondues.</p>

<table class="synthese">
    <tr>
        <td class="libelle">Attendu des membres</td>
        <td class="montant">{{ $fcfa($rapport['participations']['expected']) }}</td>
    </tr>
    <tr>
        <td class="libelle">Encaissé</td>
        <td class="montant">{{ $fcfa($rapport['participations']['collected']) }}</td>
    </tr>
    <tr class="total">
        <td class="libelle">Reste à percevoir</td>
        <td class="montant">{{ $fcfa($rapport['participations']['remaining']) }}</td>
    </tr>
</table>

<h2>Journal des opérations</h2>

@if (count($rapport['entries']) === 0)
    <p class="discret">Aucune opération sur cette période.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Opération</th>
                <th class="montant">Entrée</th>
                <th class="montant">Sortie</th>
                <th class="montant">Solde</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rapport['entries'] as $ecriture)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($ecriture['date'])->format('d/m') }}</td>
                    <td>
                        {{ $ecriture['label'] }}
                        <br><span class="discret">{{ $ecriture['category'] }} · {{ $ecriture['author'] }}</span>
                        @if ($ecriture['reason'])
                            {{-- Le motif d'une contre-passation : c'est
                                 exactement ce qu'on demandera d'expliquer. --}}
                            <br><span class="discret">Motif : {{ $ecriture['reason'] }}</span>
                        @endif
                    </td>
                    <td class="montant">{{ $ecriture['income'] > 0 ? $fcfa($ecriture['income']) : '' }}</td>
                    <td class="montant">{{ $ecriture['expense'] > 0 ? $fcfa($ecriture['expense']) : '' }}</td>
                    <td class="montant">{{ $fcfa($ecriture['balance_after']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="discret" style="margin-top: 4mm;">
        La colonne « Solde » est le solde de la caisse au moment où l'écriture a
        été passée. Une opération saisie après coup pour une date antérieure y
        apparaît donc à sa date, mais avec le solde qu'elle a réellement produit.
    </p>
@endif

<div class="pied">
    Cyclo Dakar · {{ $rapport['period']['label'] }} · document généré automatiquement
</div>

</body>
</html>
