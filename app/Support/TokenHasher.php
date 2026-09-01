<?php

namespace App\Support;

final class TokenHasher
{
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function generate(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(24));
    }
}
