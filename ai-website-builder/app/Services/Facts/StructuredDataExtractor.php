<?php

namespace App\Services\Facts;

use App\Models\Source;

/**
 * Pulls facts that are machine-readable on the page (schema.org JSON-LD, tel:/mailto:/wa.me links).
 * Needs no AI, so it also works when the AI is down.
 *
 * @return array<int, array{key: string, value: string, price: ?string, evidence: string}>
 */
class StructuredDataExtractor
{
    public function extract(Source $source): array
    {
        $text = (string) $source->content;
        $facts = [];

        // JSON-LD first: it usually has the nicely formatted spelling ("+91 98200 12345").
        if (preg_match_all('/^Structured data: (.+)$/m', $text, $m)) {
            foreach ($m[1] as $json) {
                $data = json_decode($json, true);
                foreach ($this->businessNodes($data) as $node) {
                    $facts = array_merge($facts, $this->fromNode($node));
                }
            }
        }

        if (preg_match_all('/^Link: tel:([+\d\s().-]{6,})$/m', $text, $m)) {
            foreach ($m[1] as $phone) {
                $facts[] = ['key' => 'phone', 'value' => trim($phone), 'price' => null, 'evidence' => 'tel:'.trim($phone)];
            }
        }
        if (preg_match_all('/^Link: mailto:([^?\s]+)/m', $text, $m)) {
            foreach ($m[1] as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $facts[] = ['key' => 'email', 'value' => $email, 'price' => null, 'evidence' => 'mailto:'.$email];
                }
            }
        }
        if (preg_match_all('#^Link: \S*wa\.me/(\d{8,15})#m', $text, $m)) {
            foreach ($m[1] as $wa) {
                $facts[] = ['key' => 'whatsapp', 'value' => '+'.$wa, 'price' => null, 'evidence' => 'wa.me/'.$wa];
            }
        }

        // Places API results are stored as "Field: value" lines by the ingestor.
        if ($source->kind === 'google_business' && ($source->meta['place'] ?? null)) {
            $place = $source->meta['place'];
            foreach (['phone' => 'phone', 'address' => 'address', 'website' => 'website', 'hours' => 'hours', 'category' => 'category'] as $field => $key) {
                if (! empty($place[$field])) {
                    $facts[] = ['key' => $key, 'value' => (string) $place[$field], 'price' => null, 'evidence' => (string) $place[$field]];
                }
            }
        }

        return $facts;
    }

    private function businessNodes(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $nodes = [];
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $n) {
                $nodes = array_merge($nodes, $this->businessNodes($n));
            }
        } elseif (array_is_list($data)) {
            foreach ($data as $n) {
                $nodes = array_merge($nodes, $this->businessNodes($n));
            }
        } elseif (isset($data['@type']) && (isset($data['telephone']) || isset($data['address']) || isset($data['openingHours']))) {
            $nodes[] = $data;
        }

        return $nodes;
    }

    private function fromNode(array $node): array
    {
        $facts = [];
        $add = function (string $key, $value) use (&$facts) {
            if (is_string($value) && trim($value) !== '') {
                $facts[] = ['key' => $key, 'value' => trim($value), 'price' => null, 'evidence' => trim($value)];
            }
        };

        $add('phone', $node['telephone'] ?? null);
        $add('email', isset($node['email']) ? preg_replace('/^mailto:/', '', (string) $node['email']) : null);
        $address = $node['address'] ?? null;
        if (is_array($address)) {
            $address = implode(', ', array_filter([
                $address['streetAddress'] ?? null, $address['addressLocality'] ?? null,
                $address['addressRegion'] ?? null, $address['postalCode'] ?? null,
            ], fn ($v) => is_string($v) && $v !== ''));
        }
        $add('address', $address);
        $hours = $node['openingHours'] ?? null;
        $add('hours', is_array($hours) ? implode('; ', array_filter($hours, 'is_string')) : $hours);
        foreach ((array) ($node['sameAs'] ?? []) as $link) {
            $add('social_link', $link);
        }

        return $facts;
    }
}
