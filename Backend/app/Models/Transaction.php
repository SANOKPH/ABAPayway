<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const FINAL_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const CURRENCY_KHR = 'KHR';

    protected $fillable = [
        'tran_id',
        'name',
        'account_number',
        'amount',
        'currency',
        'status',
        'payway_response_code',
        'payway_apv',
    ];

    protected $casts = [
        'amount' => 'string',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }
}
