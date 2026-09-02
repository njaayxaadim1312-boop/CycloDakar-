<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\ParticipationMember;
use App\Notifications\EventReminder;
use App\Notifications\ParticipationDue;
use Illuminate\Console\Command;

/**
 * Les rappels du club : la sortie de demain, la cotisation à régler.
 *
 * LA RÈGLE QUI GOUVERNE CETTE COMMANDE : ON NE RELANCE PAS DEUX FOIS.
 *
 * Un rappel utile devient du harcèlement à la troisième répétition, et c'est
 * particulièrement vrai quand il parle d'argent : quelqu'un qui n'a pas payé
 * ne l'a peut-être pas fait parce qu'il ne pouvait pas. Une notification
 * quotidienne sur une dette fait partir un membre bien plus sûrement qu'elle ne
 * le fait payer.
 *
 * Chaque rappel n'est donc envoyé qu'à UN moment précis :
 *
 *  - la sortie, la veille. C'est le soir qu'on prépare son vélo ;
 *  - la cotisation, à trois jours de l'échéance. Assez tôt pour s'organiser,
 *    assez tard pour que ce soit un rappel et non une relance.
 *
 * La commande tourne chaque jour et ne fait rien la plupart du temps. C'est
 * normal, et c'est même le but : une commande qui trouverait toujours quelque
 * chose à envoyer enverrait trop.
 *
 * ELLE NE NOTIFIE QUE LES INSCRITS pour les sorties. Rappeler une sortie à
 * quelqu'un qui ne s'y est pas inscrit n'est pas un rappel, c'est de la
 * publicité — et c'est ainsi qu'on fait couper les notifications.
 */
final class SendRemindersCommand extends Command
{
    protected $signature = 'cyclo:reminders
        {--dry-run : Affiche ce qui serait envoyé, sans rien envoyer}';

    protected $description = 'Envoie les rappels du jour : sorties de demain, cotisations à échéance.';

    public function handle(): int
    {
        $simulation = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Rappels');

        if ($simulation) {
            $this->line('  <fg=gray>Simulation : rien ne sera envoyé.</>');
        }

        $sorties = $this->remindEvents($simulation);
        $cotisations = $this->remindDues($simulation);

        $this->newLine();
        $this->line("  <fg=green>✔</> {$sorties} rappel(s) de sortie, {$cotisations} rappel(s) de cotisation.");
        $this->newLine();

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------------- */

    /** La sortie de demain, aux inscrits confirmés. */
    private function remindEvents(bool $simulation): int
    {
        $demain = now()->addDay();

        $sorties = Event::query()
            ->where('status', EventStatus::Published)
            ->whereBetween('starts_at', [$demain->copy()->startOfDay(), $demain->copy()->endOfDay()])
            ->with(['participants.member.user'])
            ->get();

        $envoyes = 0;

        foreach ($sorties as $sortie) {
            foreach ($sortie->participants as $inscription) {
                // Une liste d'attente n'est pas une inscription : rappeler une
                // sortie à quelqu'un qui n'y a pas sa place serait cruel.
                if ($inscription->registration_status !== RegistrationStatus::Registered) {
                    continue;
                }

                $destinataire = $inscription->member?->user;

                if ($destinataire === null) {
                    continue;
                }

                if (! $simulation) {
                    $destinataire->notify(new EventReminder($sortie));
                }

                $envoyes++;
            }

            $this->line("     {$sortie->title} — ".$sortie->participants->count().' inscrit(s)');
        }

        return $envoyes;
    }

    /**
     * Les cotisations qui arrivent à échéance, à ceux qui doivent encore.
     *
     * Trois jours avant, une seule fois. Le message dit le montant, la date et
     * le collecteur — factuel, sans reproche.
     */
    private function remindDues(bool $simulation): int
    {
        $echeance = now()->addDays(3)->toDateString();

        $dettes = ParticipationMember::query()
            ->whereIn('status', [
                ParticipationMemberStatus::Unpaid,
                ParticipationMemberStatus::Partial,
            ])
            ->whereHas('participation', fn ($q) => $q
                ->where('status', ParticipationStatus::Open)
                ->whereDate('due_on', $echeance))
            ->with(['participation', 'collector', 'member.user'])
            ->get();

        $envoyes = 0;

        foreach ($dettes as $dette) {
            $destinataire = $dette->member?->user;

            // Un adhérent sans smartphone n'a personne à prévenir : c'est son
            // collecteur qui passera le voir, et c'est très bien ainsi.
            if ($destinataire === null || $dette->remaining() <= 0) {
                continue;
            }

            if (! $simulation) {
                $destinataire->notify(new ParticipationDue($dette));
            }

            $envoyes++;
        }

        if ($dettes->isNotEmpty()) {
            $this->line("     Échéance du {$echeance} : {$envoyes} membre(s) à relancer");
        }

        return $envoyes;
    }
}
