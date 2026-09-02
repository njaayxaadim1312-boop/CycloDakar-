<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Une dépense.
 *
 * Tant qu'elle est `PENDING`, elle n'a **aucune** ligne au grand livre et le
 * solde ne bouge pas (règle I4). C'est un engagement, pas un mouvement.
 *
 * Le montant est figé à la création : une dépense dont le montant change après
 * approbation ne correspondrait plus à l'écriture qu'elle a produite, et le
 * journal deviendrait inexplicable. Corriger suppose de refuser et de ressaisir.
 *
 * @property int $amount
 * @property ExpenseStatus $status
 */
final class Expense extends Model
{
    /** @use HasFactory<\Database\Factories\ExpenseFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'amount' => 'integer',
            'spent_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            $expense->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $expense): void {
            // Le montant et la catégorie sont figés dès l'approbation : les
            // changer laisserait une écriture au grand livre qui ne
            // correspondrait plus à sa pièce.
            if ($expense->getOriginal('status') !== ExpenseStatus::Pending->value) {
                foreach (['amount', 'transaction_category_id'] as $gele) {
                    if ($expense->isDirty($gele)) {
                        throw new \LogicException(
                            "Une dépense décidée est figée : « {$gele} » ne se modifie plus. "
                            .'Refusez-la et saisissez-en une nouvelle.'
                        );
                    }
                }
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<TransactionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<FinancialTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    /** @return HasMany<ExpenseAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    /* ---------------------------------------------------------------------- */

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Pending);
    }

    /** @param  Builder<self>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Approved);
    }
}
