<?php

namespace App\Services;

class ProductCodeNormalizer
{
    public function normalize(?string $code): string
    {
        if ($code === null) {
            return '';
        }

        return preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim($code))) ?? '';
    }
}
