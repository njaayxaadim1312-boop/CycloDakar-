<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventParticipant>
 */
class EventParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'member_id' => Member::factory(),
            'registration_status' => RegistrationStatus::Registered,
            'attendance_status' => AttendanceStatus::Unknown,
            'registered_at' => now(),
        ];
    }

    public function waitlisted(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'registration_status' => RegistrationStatus::Waitlist,
            'queue_position' => $position,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'registration_status' => RegistrationStatus::Cancelled,
            'queue_position' => null,
        ]);
    }
}
