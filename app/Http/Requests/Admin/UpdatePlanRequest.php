<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentGatewayName;
use App\Models\Plan;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlanRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'paystack_plan_code' => $this->filled('paystack_plan_code') ? trim((string) $this->input('paystack_plan_code')) : null,
            'flutterwave_plan_id' => $this->filled('flutterwave_plan_id') ? trim((string) $this->input('flutterwave_plan_id')) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $plan = $this->plan();

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'max_students' => ['required', 'integer', 'min:1', 'max:10'],
            'paystack_plan_code' => ['nullable', 'string', 'max:100', Rule::unique('plans')->ignore($plan)],
            'flutterwave_plan_id' => ['nullable', 'string', 'max:100', Rule::unique('plans')->ignore($plan)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paystack_plan_code.unique' => 'Another plan already uses that Paystack plan.',
            'flutterwave_plan_id.unique' => 'Another plan already uses that Flutterwave plan.',
        ];
    }

    /**
     * Check any newly linked plan against the gateway: same amount, naira, monthly and still active.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $plan = $this->plan();

            if ($plan->slug === 'standard' && (int) $this->input('max_students') !== 1) {
                $validator->errors()->add('max_students', 'The Standard plan covers one child.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ([
                'paystack_plan_code' => PaymentGatewayName::Paystack,
                'flutterwave_plan_id' => PaymentGatewayName::Flutterwave,
            ] as $field => $gateway) {
                $code = $this->input($field);

                if ($code === null || $code === $plan->codeFor($gateway)) {
                    continue;
                }

                $problem = $this->problemWith($plan, $gateway, $code);

                if ($problem !== null) {
                    $validator->errors()->add($field, $problem);
                }
            }
        }];
    }

    private function problemWith(Plan $plan, PaymentGatewayName $gateway, string $code): ?string
    {
        try {
            $remote = app(PaymentGatewayManager::class)->gateway($gateway)->fetchPlan($code);
        } catch (GatewayException $exception) {
            return $exception->getMessage();
        }

        return match (true) {
            $remote->currency !== 'NGN' => "That plan isn't in naira.",
            $remote->interval !== 'monthly' => "That plan doesn't renew monthly.",
            ! $remote->active => "That plan is archived in {$gateway->label()}.",
            $remote->amount !== $plan->amount => 'That plan is '.Plan::naira($remote->amount)." a month in {$gateway->label()}, but this plan is {$plan->formattedAmount()}.",
            default => null,
        };
    }

    private function plan(): Plan
    {
        return $this->route('plan');
    }
}
