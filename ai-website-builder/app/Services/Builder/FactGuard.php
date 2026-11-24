<?php

namespace App\Services\Builder;

/**
 * Last check on AI-written copy: any sentence containing a number, e-mail, link or a
 * "marketing claim" word that is not backed by the confirmed facts is removed.
 */
class FactGuard
{
    private const CLAIM_WORDS = [
        'best', 'leading', 'top-rated', 'top rated', 'number one', 'no. 1', '#1', 'award', 'awarded', 'award-winning',
        'certified', 'accredited', 'renowned', 'famous', 'trusted by', 'guarantee', 'guaranteed', 'cheapest',
        'lowest price', 'finest', 'premier', 'world-class', 'world class', 'years of experience', 'experienced',
        'established', 'rated', 'stars', 'voted', 'largest', 'oldest', 'biggest', 'expert', 'experts', 'licensed',
        'organic', 'free delivery', 'home delivery', '24/7', 'open late', 'family-run', 'family run', 'family-owned',
    ];

    private string $corpus;

    /** @var array<string, true> */
    private array $numbers = [];

    public function __construct(string $factCorpus)
    {
        $this->corpus = mb_strtolower($factCorpus);
        preg_match_all('/\d+(?:[.,:]\d+)*/', $this->corpus, $m);
        foreach ($m[0] as $n) {
            $this->numbers[$this->normNumber($n)] = true;
            foreach (preg_split('/[.,:]/', $n) as $piece) {
                $this->numbers[ltrim($piece, '0') ?: '0'] = true;
            }
        }
    }

    /** @return array{0: string, 1: array<int, string>} cleaned text and the sentences removed */
    public function clean(string $text): array
    {
        $removed = [];
        $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($text)) as $sentence) {
            if ($sentence === '') {
                continue;
            }
            if ($this->violation($sentence) !== null) {
                $removed[] = $sentence;
            } else {
                $kept[] = $sentence;
            }
        }

        return [implode(' ', $kept), $removed];
    }

    public function violation(string $sentence): ?string
    {
        $lower = mb_strtolower($sentence);

        preg_match_all('/\d+(?:[.,:]\d+)*/', $lower, $m);
        foreach ($m[0] as $n) {
            if (! isset($this->numbers[$this->normNumber($n)])) {
                return "number $n";
            }
        }
        if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.]+/', $lower, $m)) {
            foreach ($m[0] as $email) {
                if (! str_contains($this->corpus, $email)) {
                    return "email $email";
                }
            }
        }
        if (preg_match_all('#(https?://|www\.)\S+#', $lower, $m)) {
            foreach ($m[0] as $url) {
                if (! str_contains($this->corpus, rtrim(preg_replace('#^https?://(www\.)?#', '', $url), '/.,'))) {
                    return "link $url";
                }
            }
        }
        foreach (self::CLAIM_WORDS as $word) {
            if (preg_match('/(?<![\w-])'.preg_quote($word, '/').'(?![\w-])/u', $lower) && ! str_contains($this->corpus, $word)) {
                return "claim \"$word\"";
            }
        }

        return null;
    }

    private function normNumber(string $n): string
    {
        $n = str_replace(',', '', $n);

        return ltrim($n, '0') ?: '0';
    }
}
