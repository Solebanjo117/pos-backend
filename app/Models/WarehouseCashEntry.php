<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseCashEntry extends Model
{
    protected $fillable = ['warehouse_id', 'source_type', 'source_id', 'source_line_key', 'amount', 'occurred_at', 'created_by', 'reason', 'reporting_kind', 'reporting_reference', 'reporting_method', 'reporting_document_url', 'reporting_session_id', 'reporting_business_datetime'];

    protected $casts = ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
}
