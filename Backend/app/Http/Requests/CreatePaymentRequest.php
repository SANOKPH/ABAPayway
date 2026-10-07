<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'regex:/^\d{6,50}$/'],
            'amount' => ['required', 'numeric', 'decimal:0', 'min:100', 'max:50000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'name.max' => 'Full name must not exceed 100 characters.',
            'account_number.required' => 'Account number is required.',
            'account_number.regex' => 'Account number must contain between 6 and 50 digits.',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.decimal' => 'KHR amount must be a whole number.',
            'amount.min' => 'Amount must be at least 100 KHR.',
            'amount.max' => 'Amount must not exceed 50,000,000 KHR.',
        ];
    }
}
