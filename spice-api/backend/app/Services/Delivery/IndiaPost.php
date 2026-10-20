<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Core\Exceptions\HttpException;
use App\Repositories\SettingRepository;

/**
 * India Post (post office) delivery — the alternative to Shiprocket.
 *
 * Staff pack the order, book it at the post office counter, and enter the
 * article number (EA123456789IN) on the order; the customer tracks it with
 * that number. The delivery charge at checkout comes from the rate card kept
 * here (Payments → India Post), by parcel weight:
 *
 *   slabs:          [{up_to_grams: 500, charge: 60}, {up_to_grams: 1000, charge: 90}, …]
 *   extra_per_500g: charge for each further 500 g (or part) above the last slab
 *   min_days/max_days: delivery estimate shown to customers
 *   tracking_url:   link customers open to track; "{awb}" is replaced by the
 *                   article number when the page accepts it in the link
 *
 * The free-delivery threshold and the delivery GST rate are the shop's usual
 * ones, and pincodes marked not serviceable stay not serviceable.
 *
 * The starting rates are placeholders — set your post office's Speed Post /
 * parcel tariff before going live.
 */
final class IndiaPost
{
    public const DRIVER = 'india_post';
    public const CARRIER_NAME = 'India Post';
    private const SETTING_KEY = 'india_post_settings';

    /** India Post article numbers: 2 letters, 9 digits, 2 letters (EA123456789IN). */
    public const ARTICLE_PATTERN = '/^[A-Z]{2}\d{9}[A-Z]{2}$/';

    public const DEFAULTS = [
        'slabs' => [
            ['up_to_grams' => 500, 'charge' => 60.0],
            ['up_to_grams' => 1000, 'charge' => 90.0],
            ['up_to_grams' => 1500, 'charge' => 120.0],
            ['up_to_grams' => 2000, 'charge' => 150.0],
        ],
        'extra_per_500g' => 30.0,
        'min_days' => 3,
        'max_days' => 7,
        'tracking_url' => 'https://www.indiapost.gov.in/',
        'is_placeholder' => true,
    ];

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function isActive(): bool
    {
        return $this->settings->value('delivery_driver') === self::DRIVER;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $raw = $this->settings->value(self::SETTING_KEY);
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($stored)) {
            return self::DEFAULTS;
        }

        try {
            return self::normalise($stored) + ['is_placeholder' => false];
        } catch (HttpException) {
            return self::DEFAULTS;
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> The saved rate card
     */
    public function save(array $input, ?int $actorId): array
    {
        $clean = self::normalise($input);
        $this->settings->put(self::SETTING_KEY, (string) json_encode($clean), $actorId);

        return $clean + ['is_placeholder' => false];
    }

    /** Charge in rupees for a parcel of this weight. */
    public function charge(int $weightGrams): float
    {
        $config = $this->config();
        $slabs = $config['slabs'];

        foreach ($slabs as $slab) {
            if ($weightGrams <= $slab['up_to_grams']) {
                return (float) $slab['charge'];
            }
        }

        $last = $slabs[count($slabs) - 1];
        $extraSteps = (int) ceil(($weightGrams - $last['up_to_grams']) / 500);

        return round((float) $last['charge'] + $extraSteps * (float) $config['extra_per_500g'], 2);
    }

    /** Link a customer opens to track this article. */
    public function trackingUrl(string $awb): string
    {
        $template = (string) $this->config()['tracking_url'];

        return str_replace('{awb}', rawurlencode($awb), $template);
    }

    public static function isIndiaPost(string $carrierName): bool
    {
        $name = strtolower(trim($carrierName));

        return in_array($name, ['india post', 'indiapost', 'speed post', 'speedpost', 'post office', 'india post (speed post)'], true);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function normalise(array $input): array
    {
        $errors = [];
        $slabs = [];
        $previous = 0;

        foreach ((array) ($input['slabs'] ?? []) as $i => $slab) {
            $grams = (int) ($slab['up_to_grams'] ?? 0);
            $charge = round((float) ($slab['charge'] ?? -1), 2);

            if ($grams <= $previous) {
                $errors['slabs'][] = sprintf('Row %d: weights must go up, each above the one before.', $i + 1);
                continue;
            }
            if ($charge < 0) {
                $errors['slabs'][] = sprintf('Row %d: enter a charge of 0 or more.', $i + 1);
                continue;
            }

            $slabs[] = ['up_to_grams' => $grams, 'charge' => $charge];
            $previous = $grams;
        }

        if ($slabs === [] && !isset($errors['slabs'])) {
            $errors['slabs'][] = 'Add at least one weight band.';
        }

        $extra = round((float) ($input['extra_per_500g'] ?? 0), 2);
        if ($extra < 0) {
            $errors['extra_per_500g'][] = 'Enter 0 or more.';
        }

        $min = (int) ($input['min_days'] ?? 0);
        $max = (int) ($input['max_days'] ?? 0);
        if ($min < 1 || $max < $min || $max > 60) {
            $errors['days'][] = 'Delivery days: from 1 day, and "to" not less than "from".';
        }

        $url = trim((string) ($input['tracking_url'] ?? ''));
        if (preg_match('~^https?://\S+$~i', $url) !== 1) {
            $errors['tracking_url'][] = 'Enter the tracking web address, starting with https://.';
        }

        if ($errors !== []) {
            throw new HttpException('Some India Post settings are not valid.', 422, $errors);
        }

        return [
            'slabs' => $slabs,
            'extra_per_500g' => $extra,
            'min_days' => $min,
            'max_days' => $max,
            'tracking_url' => $url,
        ];
    }
}
