<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription d'un membre à un événement, et sa présence réelle.
 *
 * Les deux notions vivent sur la même ligne parce qu'elles répondent à la même
 * question posée à deux moments : « qui vient ? » avant la sortie, « qui est
 * venu ? » après. Les séparer obligerait à recouper deux tables pour la seule
 * chose que le bureau demande vraiment — l'écart entre les deux.
 *
 * @property int $id
 * @property int $event_id
 * @property int $member_id
 * @property RegistrationStatus $registration_status
 * @property AttendanceStatus $attendance_status
 * @property int|null $queue_position
 */
final class EventParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\EventParticipantFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'registration_status' => RegistrationStatus::class,
            'attendance_status' => AttendanceStatus::class,
            'registered_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'queue_position' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
