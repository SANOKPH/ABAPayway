<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TranIdService
{
    private const PREFIX = 'PW';

    private const MAX_ATTEMPTS = 10;

    /**
     * Generate a server-side, unique transaction id, e.g. PW202609210001.
     *
     * Uniqueness is guaranteed against the database: if the generated id
     * collides, another one is generated.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $tranId = $this->make();

            if (DB::table('transactions')->where('tran_id', $tranId)->doesntExist()) {
                return $tranId;
            }
        }

        return $this->makeWithRandomSuffix();
    }

    private function make(): string
    {
        return self::PREFIX
            .now()->format('Ymd')
            .str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function makeWithRandomSuffix(): string
    {
        return self::PREFIX
            .now()->format('Ymd')
            .bin2hex(random_bytes(3));
    }
}
