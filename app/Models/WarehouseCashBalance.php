<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseCashBalance extends Model
{
    protected $fillable = ['warehouse_id', 'opening_balance', 'cutoff_at', 'starting_entry_id', 'confirmed_by', 'reason'];

    protected $casts = ['opening_balance' => 'decimal:2', 'cutoff_at' => 'datetime'];
}
