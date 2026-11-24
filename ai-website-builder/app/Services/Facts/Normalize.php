<?php

namespace App\Services\Facts;

use Illuminate\Support\Str;

/** Decides when two values for the same field are "the same thing written differently". */
class Normalize
{
    public static function key(string $field, string $value): string
    {
        $value = trim($value);

        return match ($field) {
            'phone', 'whatsapp' => self::phoneDigits($value),
            'email' => Str::lower($value),
            'website', 'social_link' => self::url($value),
            default => self::words($value),
        };
    }

    public static function same(string $field, string $a, string $b): bool
    {
        $ka = self::key($field, $a);
        $kb = self::key($field, $b);
        if ($ka === $kb) {
            return true;
        }
        if ($ka === '' || $kb === '') {
            return false;
        }
        // Item names ("Haircut (men)" vs "Haircut (women)") must match exactly: a near miss is a different item.
        if (in_array($field, ['address', 'about', 'highlight', 'category', 'hours'], true)) {
            if (str_contains($ka, $kb) || str_contains($kb, $ka)) {
                return $field !== 'hours';
            }
            similar_text($ka, $kb, $percent);

            return $percent >= ($field === 'hours' ? 95 : 88);
        }

        return false;
    }

    /** Last 10 digits, so "+91 98200 12345" and "098200-12345" match. */
    public static function phoneDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public static function url(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('#^https?://(www\.|m\.)?#', '', $value);

        return rtrim(preg_replace('/[?#].*$/', '', $value), '/');
    }

    public static function words(string $value): string
    {
        // Unicode-aware so price lists in Hindi, Marathi, etc. still match their evidence.
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /** Price as comparable number text: "Rs. 1,200/-" -> "1200". */
    public static function price(?string $value): string
    {
        if (! $value) {
            return '';
        }
        preg_match_all('/\d+(?:[.,]\d+)*/', $value, $m);

        return implode('-', array_map(fn ($n) => rtrim(rtrim(str_replace(',', '', $n), '0'), '.') ?: '0', $m[0]));
    }
}
