<?php

namespace App\Models;

use App\Enums\PaymentGatewayName;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly plan. The price lives in each gateway's dashboard; `amount` (kobo) must match it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $amount kobo
 * @property string $currency
 * @property string $interval
 * @property int $max_students
 * @property string|null $paystack_plan_code
 * @property string|null $flutterwave_plan_id
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['name', 'slug', 'description', 'amount', 'currency', 'interval', 'max_students', 'paystack_plan_code', 'flutterwave_plan_id', 'is_active', 'sort_order'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'max_students' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The plan's code or id in that gateway's dashboard, if it has been linked.
     */
    public function codeFor(PaymentGatewayName $gateway): ?string
    {
        return match ($gateway) {
            PaymentGatewayName::Paystack => $this->paystack_plan_code,
            PaymentGatewayName::Flutterwave => $this->flutterwave_plan_id,
        };
    }

    public function isLinkedTo(PaymentGatewayName $gateway): bool
    {
        return filled($this->codeFor($gateway));
    }

    public function formattedAmount(): string
    {
        return self::naira($this->amount);
    }

    /**
     * "₦5,000" or "₦4,999.50" for an amount in kobo.
     */
    public static function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, $kobo % 100 === 0 ? 0 : 2);
    }
}
