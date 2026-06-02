<?php

namespace App\Http\Requests;

use App\Models\CustomerVehicle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'nullable|exists:customers,id',
            'customer_vehicle_id' => ['nullable', 'exists:customer_vehicles,id', $this->vehicleBelongsToCustomer()],
            'assigned_to' => 'nullable|exists:users,id',
            'vehicle_description' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'labour_cost' => 'nullable|numeric|min:0',
            'estimated_delivery_at' => 'nullable|date',
        ];
    }

    private function vehicleBelongsToCustomer(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $customerId = $this->input('customer_id');

            if (! $value || ! $customerId) {
                return;
            }

            $belongs = CustomerVehicle::query()
                ->whereKey($value)
                ->where('customer_id', $customerId)
                ->exists();

            if (! $belongs) {
                $fail('El carro seleccionado no pertenece al cliente.');
            }
        };
    }
}
