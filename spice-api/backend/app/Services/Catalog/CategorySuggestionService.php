<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;

/**
 * Suggests a category and sub-category for an item from its name.
 *
 * Two sources, combined:
 *  1. CategoryTaxonomy — what the words mean ("kurta" → Clothing › Ethnic
 *     Wear, "attar" → Perfumes › Attar, "badam" → Dry Fruits › Almonds).
 *  2. This store's own catalogue — where items with similar names already
 *     sit, so the store's own way of filing things wins over time.
 *
 * Every suggestion is matched to the store's real categories. A category or
 * sub-category that does not exist yet comes back with exists = false and
 * its name, so the admin screen can offer to create it in one click.
 *
 * Nothing is written here; it only reads.
 */
final class CategorySuggestionService
{
    /** Words that say nothing about what an item is. */
    private const STOPWORDS = [
        'the', 'and', 'with', 'for', 'of', 'in', 'a', 'an', 'pack', 'packet', 'pouch', 'box', 'jar', 'bottle',
        'premium', 'pure', 'natural', 'organic', 'fresh', 'best', 'quality', 'new', 'special', 'super', 'extra',
        'classic', 'gm', 'g', 'kg', 'gram', 'grams', 'ml', 'l', 'ltr', 'litre', 'liter', 'pcs', 'pc', 'piece',
        'pieces', 'size', 'free', 'combo', 'set', 'big', 'small', 'medium', 'large', 'xl', 'xxl', 'xs', 's', 'm',
    ];

    /** @var array<string, mixed>|null Category tree, read once per request. */
    private ?array $treeCache = null;

    /** @var array<string, array<string, mixed>> Results by name, for files that repeat a name. */
    private array $memo = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{name: string, suggestions: array<int, array<string, mixed>>}
     */
    public function suggest(string $name, int $limit = 3): array
    {
        $name = trim($name);
        $text = self::normalise($name);

        if (strlen($text) < 2) {
            return ['name' => $name, 'suggestions' => []];
        }

        $memoKey = $text . '|' . $limit;

        if (isset($this->memo[$memoKey])) {
            return ['name' => $name] + $this->memo[$memoKey];
        }

        $tree = $this->treeCache ??= $this->tree();
        $candidates = [];

        foreach ($this->fromTaxonomy($text) as $hit) {
            $this->addCandidate($candidates, $tree, $hit);
        }

        foreach ($this->fromCatalogue($text, $tree) as $hit) {
            $this->addCandidate($candidates, $tree, $hit);
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];

        foreach (array_slice($candidates, 0, max(1, $limit)) as $c) {
            $out[] = [
                'label' => $c['sub'] !== null ? $c['top']['name'] . ' › ' . $c['sub']['name'] : $c['top']['name'],
                'category' => $c['top'],
                'sub_category' => $c['sub'],
                // A top-level category with sub-categories needs one picked.
                'needs_sub_category' => $c['sub'] === null && $c['top_has_children'],
                'exists' => $c['top']['uuid'] !== null && ($c['sub'] === null || $c['sub']['uuid'] !== null),
                'leaf_uuid' => $c['sub'] !== null ? $c['sub']['uuid'] : ($c['top_has_children'] ? null : $c['top']['uuid']),
                'confidence' => $c['score'] >= 3 ? 'high' : ($c['score'] >= 1.5 ? 'medium' : 'low'),
                'reasons' => array_values(array_unique($c['reasons'])),
            ];
        }

        $this->memo[$memoKey] = ['suggestions' => $out];

        return ['name' => $name, 'suggestions' => $out];
    }

    /**
     * Taxonomy matches, best first: [{top, item_type, sub|null, score, reason}].
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromTaxonomy(string $text): array
    {
        $hits = [];

        foreach (CategoryTaxonomy::all() as $entry) {
            [$topScore, $topWords] = self::score($text, $entry['keywords']);

            if ($topScore <= 0) {
                continue;
            }

            $bestSub = null;
            $bestSubScore = 0.0;
            $subWords = [];

            foreach ($entry['subs'] as $subName => $keywords) {
                [$s, $w] = self::score($text, $keywords);

                if ($s > $bestSubScore) {
                    $bestSub = $subName;
                    $bestSubScore = $s;
                    $subWords = $w;
                }
            }

            // Clothing / footwear: "men's kurta" → Ethnic Wear (what it is);
            // "men's shirt" → Men (who it is for).
            if ($bestSub === null && !empty($entry['audience'])) {
                foreach (CategoryTaxonomy::AUDIENCE as $subName => $keywords) {
                    [$s, $w] = self::score($text, $keywords);

                    if ($s > $bestSubScore) {
                        $bestSub = $subName;
                        $bestSubScore = $s;
                        $subWords = $w;
                    }
                }
            }

            $hits[] = [
                'top' => $entry['name'],
                'aliases' => $entry['aliases'],
                'item_type' => $entry['item_type'],
                'sub' => $bestSub,
                'score' => $topScore + $bestSubScore,
                'reason' => 'name has "' . implode('", "', array_unique(array_merge($topWords, $subWords))) . '"',
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, 3);
    }

    /**
     * Where items with similar names already sit in this store.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromCatalogue(string $text, array $tree): array
    {
        $tokens = array_values(array_filter(
            array_unique(explode(' ', $text)),
            static fn (string $t): bool => strlen($t) >= 3 && !in_array($t, self::STOPWORDS, true) && !ctype_digit($t)
        ));

        if ($tokens === []) {
            return [];
        }

        $tokens = array_slice($tokens, 0, 6);
        $where = [];
        $bindings = [];

        foreach ($tokens as $i => $token) {
            $where[] = 'LOWER(p.`name`) LIKE :t' . $i;
            $bindings['t' . $i] = '%' . $token . '%';
        }

        $rows = $this->db->select(
            'SELECT p.`name`, p.`category_id` FROM `products` p
              WHERE p.`is_deleted` = 0 AND (' . implode(' OR ', $where) . ')
              LIMIT 300',
            $bindings
        );

        $byCategory = [];

        foreach ($rows as $row) {
            $theirs = explode(' ', self::normalise((string) $row['name']));
            $shared = array_values(array_intersect($tokens, $theirs));

            if ($shared === []) {
                continue;
            }

            $id = (int) $row['category_id'];
            $byCategory[$id]['score'] = ($byCategory[$id]['score'] ?? 0) + count($shared) / max(1, count($tokens));
            $byCategory[$id]['count'] = ($byCategory[$id]['count'] ?? 0) + 1;
            $byCategory[$id]['example'] ??= (string) $row['name'];
        }

        $hits = [];

        foreach ($byCategory as $categoryId => $info) {
            $node = $tree['by_id'][$categoryId] ?? null;

            if ($node === null) {
                continue;
            }

            $parent = $node['parent_id'] !== null ? ($tree['by_id'][$node['parent_id']] ?? null) : null;

            $hits[] = [
                'top' => $parent !== null ? $parent['name'] : $node['name'],
                'aliases' => [],
                'item_type' => null,
                'sub' => $parent !== null ? $node['name'] : null,
                // Capped so a big catalogue can't drown out the words.
                'score' => min(3.0, $info['score'] * 1.5),
                'reason' => sprintf(
                    'like "%s"%s',
                    $info['example'],
                    $info['count'] > 1 ? sprintf(' and %d other item%s', $info['count'] - 1, $info['count'] > 2 ? 's' : '') : ''
                ),
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, 3);
    }

    /**
     * Resolves a hit onto the real tree and merges it with any candidate for
     * the same place (agreement between the two sources adds up).
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed>             $tree
     * @param array<string, mixed>             $hit
     */
    private function addCandidate(array &$candidates, array $tree, array $hit): void
    {
        $top = $this->findTop($tree, (string) $hit['top'], $hit['aliases']);
        $topHasChildren = $top !== null && ($tree['children'][$top['id']] ?? []) !== [];
        $sub = null;

        if ($hit['sub'] !== null) {
            $subNode = $top !== null ? $this->findChild($tree, (int) $top['id'], (string) $hit['sub']) : null;
            $sub = [
                'uuid' => $subNode['uuid'] ?? null,
                'slug' => $subNode['slug'] ?? null,
                'name' => $subNode['name'] ?? (string) $hit['sub'],
            ];
        } elseif ($top !== null && !$topHasChildren) {
            $sub = null;
        }

        $topView = [
            'uuid' => $top['uuid'] ?? null,
            'slug' => $top['slug'] ?? null,
            'name' => $top['name'] ?? (string) $hit['top'],
            'item_type' => $top !== null ? ($top['item_type'] ?? $hit['item_type'] ?? 'general') : ($hit['item_type'] ?? 'general'),
        ];

        $key = strtolower($topView['name'] . '|' . ($sub['name'] ?? ''));

        foreach ($candidates as &$existing) {
            if ($existing['key'] === $key) {
                $existing['score'] += $hit['score'];
                $existing['reasons'][] = $hit['reason'];

                return;
            }

            // Same category, one side without a sub-category: keep the
            // specific one and add the support.
            if (strtolower($existing['top']['name']) === strtolower($topView['name'])
                && ($existing['sub'] === null || $sub === null)) {
                $existing['score'] += $hit['score'] * 0.5;
                $existing['reasons'][] = $hit['reason'];

                if ($existing['sub'] === null && $sub !== null) {
                    $existing['sub'] = $sub;
                    $existing['key'] = $key;
                }

                return;
            }
        }
        unset($existing);

        $candidates[] = [
            'key' => $key,
            'top' => $topView,
            'top_has_children' => $top === null ? $sub === null : $topHasChildren,
            'sub' => $sub,
            'score' => (float) $hit['score'],
            'reasons' => [$hit['reason']],
        ];
    }

    /**
     * @param array<string, mixed>  $tree
     * @param array<int, string>    $aliases
     *
     * @return array<string, mixed>|null
     */
    private function findTop(array $tree, string $name, array $aliases): ?array
    {
        $wanted = array_map([self::class, 'key'], array_merge([$name], $aliases));

        foreach ($tree['tops'] as $top) {
            if (in_array(self::key((string) $top['name']), $wanted, true) || in_array(self::key((string) $top['slug']), $wanted, true)) {
                return $top;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>|null
     */
    private function findChild(array $tree, int $parentId, string $name): ?array
    {
        $wanted = self::key($name);
        $children = $tree['children'][$parentId] ?? [];

        foreach ($children as $child) {
            if (self::key((string) $child['name']) === $wanted || self::key((string) $child['slug']) === $wanted) {
                return $child;
            }
        }

        // "Sandals & Slippers" ~ "Sandals"; only for longer names, so "Men"
        // never matches "Women".
        foreach ($children as $child) {
            $have = self::key((string) $child['name']);

            if (strlen($have) >= 5 && strlen($wanted) >= 5 && (str_contains($have, $wanted) || str_contains($wanted, $have))) {
                return $child;
            }
        }

        return null;
    }

    /** @return array{tops: array<int, array<string, mixed>>, children: array<int, array<int, array<string, mixed>>>, by_id: array<int, array<string, mixed>>} */
    private function tree(): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `uuid`, `slug`, `name`, `parent_id`, `item_type`
               FROM `categories` WHERE `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `display_order`, `name`'
        );

        $tree = ['tops' => [], 'children' => [], 'by_id' => []];

        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $tree['by_id'][$row['id']] = $row;

            if ($row['parent_id'] === null) {
                $tree['tops'][] = $row;
            } else {
                $tree['children'][$row['parent_id']][] = $row;
            }
        }

        return $tree;
    }

    /**
     * Weighted keyword hits: a phrase counts once per word, so "body spray"
     * outweighs a lone "spray".
     *
     * @param array<int, string> $keywords
     *
     * @return array{0: float, 1: array<int, string>}
     */
    private static function score(string $text, array $keywords): array
    {
        $haystack = ' ' . $text . ' ';
        $score = 0.0;
        $words = [];

        foreach ($keywords as $keyword) {
            $needle = ' ' . self::normalise($keyword) . ' ';

            if ($needle !== '  ' && str_contains($haystack, $needle)) {
                $score += substr_count(trim($needle), ' ') + 1;
                $words[] = $keyword;
            }
        }

        return [$score, $words];
    }

    /**
     * Lower-case words only, with singular forms added ("shoes" also reads
     * as "shoe") and "t-shirt" / "T Shirt" read alike.
     */
    private static function normalise(string $value): string
    {
        $value = strtolower($value);
        $value = (string) preg_replace("/['’`]s\\b/u", '', $value);
        $value = str_replace(['t-shirt', 't - shirt'], 't shirt', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if ($value === '') {
            return '';
        }

        $extra = [];

        foreach (explode(' ', $value) as $word) {
            if (strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                $extra[] = substr($word, 0, -1);
            }
        }

        return $extra === [] ? $value : $value . ' ' . implode(' ', $extra);
    }

    /** Letters only, for comparing category names ("Dry Fruits" = "dry-fruits"). */
    private static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower(str_replace('&', 'and', $value)));
    }
}
