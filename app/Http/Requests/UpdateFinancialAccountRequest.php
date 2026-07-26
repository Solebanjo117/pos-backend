<?php

namespace App\Http\Requests;

class UpdateFinancialAccountRequest extends StoreFinancialAccountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'][0] = 'sometimes';
        $rules['type'][0] = 'sometimes';
        $rules['ledger_account_id'][0] = 'sometimes';
        unset($rules['opening_balance']);

        return $rules;
    }
}
