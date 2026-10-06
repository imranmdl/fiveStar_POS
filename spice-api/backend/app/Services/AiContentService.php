<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;

/**
 * Drafts product copy for the admin product editor ("Write with AI").
 *
 * With ANTHROPIC_API_KEY set, asks the Claude API for a short description
 * (the one-liner on product cards) and a full description written from the
 * details the admin has already entered. Without a key it fills a plain
 * template from the same details, so the button always does something.
 *
 * Nothing is saved here: the text goes back into the form for the admin to
 * read, edit and save. Facts the admin did not give (certifications, health
 * benefits, origin) are kept out of the prompt's allowed claims.
 */
class AiContentService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function aiAvailable(): bool
    {
        return trim((string) $this->config->get('ai.anthropic.api_key', '')) !== '';
    }

    /**
     * @param array{name:string, category?:?string, brand?:?string, origin?:?string, packs?:array<int,string>,
     *              is_organic?:bool, is_vegetarian?:bool, shelf_life_days?:?int, notes?:?string, store_name?:?string} $product
     *
     * @return array{short_description:string, description:string, source:string}
     */
    public function productDescription(array $product): array
    {
        $product['name'] = trim($product['name']);

        if ($product['name'] === '') {
            throw new HttpException('Enter the product name first.', 422, ['name' => ['The product name is required.']]);
        }

        if (!$this->aiAvailable()) {
            return $this->template($product) + ['source' => 'template'];
        }

        $text = $this->ask($this->systemPrompt(), $this->userPrompt($product));
        $parsed = $this->parse($text);

        return [
            'short_description' => $this->clip($parsed['short_description'], 320),
            'description' => $this->clip($parsed['description'], 4000),
            'source' => 'ai',
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You write product copy for an Indian online shop selling spices, dry fruits and groceries.
Write in clear, warm Indian English for home cooks. Plain text only — no markdown, no emojis, no prices, no offers.
Use only the facts provided. Do not invent certifications (organic, FSSAI, GI tag), awards, origins, health or medical benefits, or ingredients.
Mention "organic" only if the input says it is organic. Avoid claims like "cures", "boosts immunity", "best in India".
You may describe typical culinary uses, aroma, flavour and storage tips that are common knowledge for this kind of product.
Reply with JSON only, in exactly this shape:
{"short_description": "one sentence, at most 150 characters, for the product card", "description": "2 short paragraphs (60-140 words in total): what it is and how to use it, then how to store it"}
TXT;
    }

    /** @param array<string, mixed> $p */
    private function userPrompt(array $p): string
    {
        $lines = ['Product name: ' . $p['name']];

        foreach ([
            'category' => 'Category',
            'brand' => 'Brand',
            'origin' => 'Origin',
            'notes' => 'Extra details from the shop',
        ] as $key => $label) {
            if (!empty($p[$key])) {
                $lines[] = $label . ': ' . trim((string) $p[$key]);
            }
        }

        if (!empty($p['packs'])) {
            $lines[] = 'Pack sizes: ' . implode(', ', array_slice(array_map('strval', $p['packs']), 0, 8));
        }
        $lines[] = 'Organic: ' . (!empty($p['is_organic']) ? 'yes' : 'no / not stated');
        if (isset($p['is_vegetarian'])) {
            $lines[] = 'Vegetarian: ' . ($p['is_vegetarian'] ? 'yes' : 'no');
        }
        if (!empty($p['shelf_life_days'])) {
            $lines[] = 'Shelf life: ' . (int) $p['shelf_life_days'] . ' days';
        }
        if (!empty($p['store_name'])) {
            $lines[] = 'Shop name: ' . $p['store_name'];
        }

        return implode("\n", $lines);
    }

    /** Calls the Claude Messages API; protected so tests can stand in for it. */
    protected function ask(string $system, string $user): string
    {
        $base = rtrim((string) $this->config->get('ai.anthropic.base_url', 'https://api.anthropic.com'), '/');
        $timeout = max(5, (int) $this->config->get('ai.anthropic.timeout_seconds', 30));
        $body = json_encode([
            'model' => (string) $this->config->get('ai.anthropic.model', 'claude-haiku-4-5-20251001'),
            'max_tokens' => (int) $this->config->get('ai.anthropic.max_tokens', 900),
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_UNICODE);

        $handle = curl_init($base . '/v1/messages');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . (string) $this->config->get('ai.anthropic.api_key'),
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $data = is_string($raw) ? json_decode($raw, true) : null;

        if ($raw === false || $status !== 200 || !is_array($data)) {
            $this->logger->warning('AI request failed', [
                'status' => $status,
                'curl_error' => $error === '' ? null : $error,
                'error' => is_array($data) ? ($data['error']['message'] ?? null) : null,
            ], 'ai');

            $message = match (true) {
                $status === 401 => 'The AI key (ANTHROPIC_API_KEY) was not accepted. Check it on the server.',
                $status === 429 => 'The AI service is busy. Try again in a minute.',
                default => 'The AI service could not be reached. Try again, or write the description yourself.',
            };

            throw new HttpException($message, 502);
        }

        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }
        }

        if (($data['stop_reason'] ?? '') === 'refusal' || trim($text) === '') {
            throw new HttpException('The AI could not write a description for this product. Please write it yourself.', 502);
        }

        return $text;
    }

    /** @return array{short_description:string, description:string} */
    private function parse(string $text): array
    {
        $json = trim($text);
        // Tolerate a ```json fence or a sentence around the object.
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = ($start !== false && $end !== false) ? json_decode(substr($json, $start, $end - $start + 1), true) : null;

        if (!is_array($data) || trim((string) ($data['short_description'] ?? '')) === '' || trim((string) ($data['description'] ?? '')) === '') {
            $this->logger->warning('AI reply was not the expected JSON', ['reply' => mb_substr($text, 0, 500)], 'ai');
            throw new HttpException('The AI reply could not be read. Please try again.', 502);
        }

        return [
            'short_description' => trim((string) $data['short_description']),
            'description' => trim((string) $data['description']),
        ];
    }

    /**
     * No-key fallback built only from what the admin entered.
     *
     * @param array<string, mixed> $p
     *
     * @return array{short_description:string, description:string}
     */
    private function template(array $p): array
    {
        $name = $p['name'];
        $category = trim((string) ($p['category'] ?? ''));
        $organic = !empty($p['is_organic']) ? 'Organic ' : '';
        $origin = trim((string) ($p['origin'] ?? ''));
        $packs = array_values(array_filter(array_map('strval', (array) ($p['packs'] ?? []))));

        $short = $organic !== '' && stripos($name, 'organic') === false ? $organic . $name : $name;
        $short .= $origin !== '' ? ' from ' . $origin : '';
        $short .= $category !== '' ? ' — quality ' . mb_strtolower($category) . ' for everyday cooking.' : ', carefully packed for everyday cooking.';

        $parts = [];
        $parts[] = sprintf(
            '%s%s%s.',
            $name,
            !empty($p['brand']) ? ' by ' . $p['brand'] : '',
            $origin !== '' ? ', sourced from ' . $origin : ''
        );
        if ($packs !== []) {
            $parts[] = 'Available in ' . implode(', ', array_slice($packs, 0, 6)) . '.';
        }
        if (!empty($p['notes'])) {
            $parts[] = trim((string) $p['notes']);
        }
        $storage = 'Store in an airtight container in a cool, dry place, away from direct sunlight, for the best flavour.';
        if (!empty($p['shelf_life_days'])) {
            $days = (int) $p['shelf_life_days'];
            $storage .= $days >= 60
                ? sprintf(' Shelf life: about %d months from packing.', (int) round($days / 30))
                : sprintf(' Shelf life: %d days from packing.', $days);
        }

        return [
            'short_description' => $this->clip($short, 320),
            'description' => implode(' ', $parts) . "\n\n" . $storage,
        ];
    }

    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace("/[ \t]+/", ' ', $text) ?? $text);

        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
