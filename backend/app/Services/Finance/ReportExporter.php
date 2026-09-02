<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Met un rapport financier en PDF, en Excel ou en CSV.
 *
 * TROIS FORMATS, TROIS USAGES DIFFÉRENTS — ce n'est pas de la redondance.
 *
 * Le **PDF** se signe et se classe : c'est la pièce qu'on distribue en
 * assemblée générale et qui ne se retouche pas.
 *
 * L'**Excel** se retravaille : le trésorier y ajoute une colonne, trie, fait
 * ses propres totaux. Les montants y sont de vrais NOMBRES, pas du texte —
 * sans quoi la première somme dans un tableur donnerait zéro.
 *
 * Le **CSV** s'importe ailleurs : dans un logiciel de comptabilité, dans un
 * autre tableur, dans un script. C'est le format qu'on regrette de ne pas
 * avoir le jour où il faut sortir des données d'une application.
 *
 * DEUX DÉTAILS D'ENCODAGE QUI DÉCIDENT DE TOUT POUR LE CSV
 *
 * La **BOM UTF-8** en tête : sans elle, Excel lit le fichier en Windows-1252 et
 * « Ravitaillement » devient « Ravitaillement ». C'est trois octets, et c'est
 * la différence entre un fichier utilisable et un fichier qu'on renvoie.
 *
 * Le **point-virgule** comme séparateur : sur un Windows configuré en
 * français, la virgule est le séparateur décimal, et Excel attend donc le
 * point-virgule. Un CSV à virgules s'y ouvre en une seule colonne.
 */
final class ReportExporter
{
    /** @param  array<string, mixed>  $rapport */
    public function csv(array $rapport): StreamedResponse
    {
        $nom = $this->filename($rapport, 'csv');

        return response()->streamDownload(function () use ($rapport): void {
            $sortie = fopen('php://output', 'wb');

            // La BOM UTF-8. Voir l'en-tête de la classe : sans elle, les
            // accents sont illisibles dans Excel.
            fwrite($sortie, "\xEF\xBB\xBF");

            $ligne = function (array $champs) use ($sortie): void {
                // `;` et non `,` : séparateur attendu par un Excel français.
                fputcsv($sortie, $champs, ';', '"', '\\');
            };

            $ligne(['Cyclo Dakar — rapport financier']);
            $ligne(['Période', $rapport['period']['label']]);
            $ligne(['Édité le', now()->format('d/m/Y H:i')]);
            $ligne([]);

            $ligne(['Solde d\'ouverture', $rapport['summary']['opening_balance']]);
            $ligne(['Recettes', $rapport['summary']['income']]);
            $ligne(['Dépenses', $rapport['summary']['expenses']]);
            $ligne(['Résultat', $rapport['summary']['net']]);
            $ligne(['Solde de clôture', $rapport['summary']['closing_balance']]);
            $ligne([]);

            $ligne(['Date', 'Opération', 'Poste', 'Entrée', 'Sortie', 'Solde', 'Auteur']);

            foreach ($rapport['entries'] as $ecriture) {
                $ligne([
                    $this->jour($ecriture['date']),
                    $ecriture['label'],
                    $ecriture['category'],
                    // Les cases vides plutôt que des zéros : une colonne de
                    // zéros se somme aussi bien, mais se lit beaucoup moins.
                    $ecriture['income'] === 0 ? '' : $ecriture['income'],
                    $ecriture['expense'] === 0 ? '' : $ecriture['expense'],
                    $ecriture['balance_after'],
                    $ecriture['author'],
                ]);
            }

            fclose($sortie);
        }, $nom, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param  array<string, mixed>  $rapport */
    public function xlsx(array $rapport): StreamedResponse
    {
        $nom = $this->filename($rapport, 'xlsx');

        $classeur = new Spreadsheet;
        $classeur->getProperties()
            ->setCreator('Cyclo Dakar')
            ->setTitle('Rapport financier — '.$rapport['period']['label']);

        $this->feuilleSynthese($classeur, $rapport);
        $this->feuilleOperations($classeur, $rapport);

        $classeur->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        }, $nom, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param  array<string, mixed>  $rapport */
    public function pdf(array $rapport): \Illuminate\Http\Response
    {
        $pdf = Pdf::loadView('reports.finance', ['rapport' => $rapport])
            // Portrait : un journal de caisse se lit en colonne, et le paysage
            // obligerait à tourner la feuille en assemblée.
            ->setPaper('a4', 'portrait');

        return $pdf->download($this->filename($rapport, 'pdf'));
    }

    /* ---------------------------------------------------------------------- */

    /** @param  array<string, mixed>  $rapport */
    private function feuilleSynthese(Spreadsheet $classeur, array $rapport): void
    {
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Synthèse');

        $feuille->setCellValue('A1', 'Cyclo Dakar — rapport financier');
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $feuille->setCellValue('A2', 'Période');
        $feuille->setCellValue('B2', $rapport['period']['label']);
        $feuille->setCellValue('A3', 'Édité le');
        $feuille->setCellValue('B3', now()->format('d/m/Y H:i'));

        $lignes = [
            ['Solde d\'ouverture', $rapport['summary']['opening_balance']],
            ['Recettes', $rapport['summary']['income']],
            ['Dépenses', $rapport['summary']['expenses']],
            ['Résultat', $rapport['summary']['net']],
            ['Solde de clôture', $rapport['summary']['closing_balance']],
        ];

        $numero = 5;

        foreach ($lignes as [$libelle, $montant]) {
            $feuille->setCellValue("A{$numero}", $libelle);
            // Un NOMBRE, pas une chaîne : sinon la première somme faite dans le
            // tableur renverrait zéro, et le trésorier conclurait à un bug.
            $feuille->setCellValue("B{$numero}", $montant);
            $feuille->getStyle("B{$numero}")->getNumberFormat()->setFormatCode('# ##0 "FCFA"');
            $numero++;
        }

        $feuille->getStyle('A5:A9')->getFont()->setBold(true);

        $numero += 2;
        $feuille->setCellValue("A{$numero}", 'Recettes par poste');
        $feuille->getStyle("A{$numero}")->getFont()->setBold(true);
        $numero++;

        foreach ($rapport['by_category']['income'] as $poste) {
            $feuille->setCellValue("A{$numero}", $poste['name']);
            $feuille->setCellValue("B{$numero}", $poste['amount']);
            $feuille->getStyle("B{$numero}")->getNumberFormat()->setFormatCode('# ##0');
            $numero++;
        }

        $numero++;
        $feuille->setCellValue("A{$numero}", 'Dépenses par poste');
        $feuille->getStyle("A{$numero}")->getFont()->setBold(true);
        $numero++;

        foreach ($rapport['by_category']['expenses'] as $poste) {
            $feuille->setCellValue("A{$numero}", $poste['name']);
            $feuille->setCellValue("B{$numero}", $poste['amount']);
            $feuille->getStyle("B{$numero}")->getNumberFormat()->setFormatCode('# ##0');
            $numero++;
        }

        $numero += 2;
        $feuille->setCellValue("A{$numero}", 'Collectes (hors période, situation du jour)');
        $feuille->getStyle("A{$numero}")->getFont()->setBold(true);
        $numero++;

        foreach ([
            ['Attendu', $rapport['participations']['expected']],
            ['Encaissé', $rapport['participations']['collected']],
            ['Reste à percevoir', $rapport['participations']['remaining']],
        ] as [$libelle, $montant]) {
            $feuille->setCellValue("A{$numero}", $libelle);
            $feuille->setCellValue("B{$numero}", $montant);
            $feuille->getStyle("B{$numero}")->getNumberFormat()->setFormatCode('# ##0');
            $numero++;
        }

        $feuille->getColumnDimension('A')->setWidth(42);
        $feuille->getColumnDimension('B')->setWidth(18);
    }

    /** @param  array<string, mixed>  $rapport */
    private function feuilleOperations(Spreadsheet $classeur, array $rapport): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle('Opérations');

        $entetes = ['Date', 'Opération', 'Poste', 'Entrée', 'Sortie', 'Solde', 'Auteur'];
        $feuille->fromArray($entetes, null, 'A1');

        $feuille->getStyle('A1:G1')->getFont()->setBold(true);
        $feuille->getStyle('A1:G1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5F5F5');

        $numero = 2;

        foreach ($rapport['entries'] as $ecriture) {
            $feuille->setCellValue("A{$numero}", $this->jour($ecriture['date']));
            $feuille->setCellValue("B{$numero}", $ecriture['label']);
            $feuille->setCellValue("C{$numero}", $ecriture['category']);

            if ($ecriture['income'] > 0) {
                $feuille->setCellValue("D{$numero}", $ecriture['income']);
            }

            if ($ecriture['expense'] > 0) {
                $feuille->setCellValue("E{$numero}", $ecriture['expense']);
            }

            $feuille->setCellValue("F{$numero}", $ecriture['balance_after']);
            $feuille->setCellValue("G{$numero}", $ecriture['author']);
            $numero++;
        }

        if ($numero > 2) {
            $derniere = $numero - 1;
            $feuille->getStyle("D2:F{$derniere}")->getNumberFormat()->setFormatCode('# ##0');
            $feuille->getStyle("D2:F{$derniere}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Le tableur fige l'en-tête : sur trois cents lignes, on ne sait
            // plus quelle colonne on lit sans cela.
            $feuille->freezePane('A2');
        }

        foreach (['A' => 12, 'B' => 40, 'C' => 22, 'D' => 14, 'E' => 14, 'F' => 16, 'G' => 20] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }
    }

    /** @param  array<string, mixed>  $rapport */
    private function filename(array $rapport, string $extension): string
    {
        return sprintf(
            'cyclo-dakar-%s-%s.%s',
            $rapport['period']['from'],
            $rapport['period']['to'],
            $extension,
        );
    }

    private function jour(string $date): string
    {
        return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
    }
}
