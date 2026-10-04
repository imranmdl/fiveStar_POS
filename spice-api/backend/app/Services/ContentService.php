<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Str;
use App\Helpers\Uuid;

/**
 * CMS pages, blog posts and FAQ entries.
 *
 * Straightforward content management, with two rules worth stating.
 *
 * A SYSTEM PAGE CANNOT BE DELETED. Shipping policy, returns, privacy and terms
 * are referenced from checkout and, in a dispute, have to be reproducible as
 * they stood on the day of the order. They can be edited and they can be
 * archived; they cannot be removed by an accidental click.
 *
 * DRAFTS ARE INVISIBLE TO THE PUBLIC, INCLUDING BY DIRECT LINK. A half-written
 * policy page is exactly the sort of thing that gets shared before it is ready.
 */
final class ContentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    // -----------------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function publishedPages(): array
    {
        return $this->db->select(
            "SELECT `slug`, `title`, `excerpt`, `display_order`, `published_date`
               FROM `cms_pages`
              WHERE `status` = 'published' AND `is_deleted` = 0
              ORDER BY `display_order`, `title`"
        );
    }

    /** @return array<string, mixed> */
    public function page(string $slug, bool $isStaff = false): array
    {
        $sql = 'SELECT * FROM `cms_pages` WHERE `slug` = :slug AND `is_deleted` = 0';

        if (!$isStaff) {
            $sql .= " AND `status` = 'published'";
        }

        $page = $this->db->selectOne($sql, ['slug' => $slug]);

        if ($page === null) {
            throw new NotFoundException('That page does not exist.');
        }

        return [
            'slug' => $page['slug'],
            'title' => $page['title'],
            'body' => $page['body'],
            'excerpt' => $page['excerpt'],
            'meta_title' => $page['meta_title'] ?? $page['title'],
            'meta_description' => $page['meta_description'],
            'status' => $page['status'],
            'is_system_page' => (bool) $page['is_system_page'],
            'published_date' => $page['published_date'],
            'updated_date' => $page['updated_date'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function savePage(Request $request, ?string $slug, array $data): array
    {
        $existing = $slug === null
            ? null
            : $this->db->selectOne(
                'SELECT * FROM `cms_pages` WHERE `slug` = :slug AND `is_deleted` = 0',
                ['slug' => $slug]
            );

        $targetSlug = $data['slug'] ?? $slug ?? Str::slug((string) $data['title']);

        if ($existing === null) {
            $clash = $this->db->selectOne(
                'SELECT `id` FROM `cms_pages` WHERE `slug` = :slug',
                ['slug' => $targetSlug]
            );

            if ($clash !== null) {
                throw new HttpException('A page with that address already exists.', 409);
            }

            $id = (int) $this->db->insert(
                'INSERT INTO `cms_pages`
                     (`uuid`, `slug`, `title`, `body`, `excerpt`, `meta_title`, `meta_description`,
                      `status`, `published_date`, `display_order`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :slug, :title, :body, :excerpt, :meta_title, :meta_description,
                         :status, :published_date, :display_order,
                         :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'slug' => $targetSlug,
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'excerpt' => $data['excerpt'] ?? null,
                    'meta_title' => $data['meta_title'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                    'status' => $data['status'] ?? 'draft',
                    'published_date' => ($data['status'] ?? 'draft') === 'published'
                        ? date('Y-m-d H:i:s')
                        : null,
                    'display_order' => (int) ($data['display_order'] ?? 0),
                    'created_by' => $request->authUserId(),
                ]
            );

            return ['page' => $this->page($targetSlug, true), 'created' => true];
        }

        $this->db->execute(
            'UPDATE `cms_pages`
                SET `title` = :title, `body` = :body, `excerpt` = :excerpt,
                    `meta_title` = :meta_title, `meta_description` = :meta_description,
                    `status` = :status,
                    `published_date` = CASE WHEN :status_check = \'published\'
                                            THEN COALESCE(`published_date`, NOW())
                                            ELSE `published_date` END,
                    `display_order` = :display_order,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'title' => $data['title'] ?? $existing['title'],
                'body' => $data['body'] ?? $existing['body'],
                'excerpt' => $data['excerpt'] ?? $existing['excerpt'],
                'meta_title' => $data['meta_title'] ?? $existing['meta_title'],
                'meta_description' => $data['meta_description'] ?? $existing['meta_description'],
                'status' => $data['status'] ?? $existing['status'],
                'status_check' => $data['status'] ?? $existing['status'],
                'display_order' => (int) ($data['display_order'] ?? $existing['display_order']),
                'actor' => $request->authUserId(),
                'id' => (int) $existing['id'],
            ]
        );

        $this->audit->log(
            entityName: 'cms_pages',
            entityId: (int) $existing['id'],
            action: 'update',
            newValues: ['slug' => $existing['slug'], 'status' => $data['status'] ?? $existing['status']],
            request: $request,
        );

        return ['page' => $this->page((string) $existing['slug'], true), 'created' => false];
    }

    public function deletePage(Request $request, string $slug): array
    {
        $page = $this->db->selectOne(
            'SELECT * FROM `cms_pages` WHERE `slug` = :slug AND `is_deleted` = 0',
            ['slug' => $slug]
        );

        if ($page === null) {
            throw new NotFoundException('That page does not exist.');
        }

        if ((int) $page['is_system_page'] === 1) {
            throw new HttpException(
                'Policy pages cannot be deleted. Archive it instead — a returns policy has to '
                . 'stay reproducible as it stood on the day of a disputed order.',
                409
            );
        }

        $this->db->execute(
            'UPDATE `cms_pages` SET `is_deleted` = 1, `deleted_by` = :actor, `deleted_date` = NOW(),
                    `version` = `version` + 1
              WHERE `id` = :id',
            ['actor' => $request->authUserId(), 'id' => (int) $page['id']]
        );

        return ['deleted' => true];
    }

    // -----------------------------------------------------------------------
    // Blog
    // -----------------------------------------------------------------------

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function posts(array $params, ?string $category, ?string $search, bool $isStaff = false): array
    {
        $where = ['`is_deleted` = 0'];
        $bindings = [];

        if (!$isStaff) {
            $where[] = "`status` = 'published'";
            $where[] = '`published_date` <= NOW()';
        }

        if ($category !== null) {
            $where[] = '`category` = :category';
            $bindings['category'] = $category;
        }

        if ($search !== null && $search !== '') {
            $where[] = 'MATCH(`title`, `excerpt`, `body`) AGAINST (:search IN NATURAL LANGUAGE MODE)';
            $bindings['search'] = $search;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `blog_posts` WHERE {$whereSql}", $bindings);

        $items = $total === 0 ? [] : $this->db->select(
            sprintf(
                'SELECT `uuid`, `slug`, `title`, `excerpt`, `cover_image_path`, `category`, `tags`,
                        `author_name`, `status`, `published_date`, `reading_minutes`, `view_count`
                   FROM `blog_posts` WHERE %s
                  ORDER BY `published_date` DESC, `created_date` DESC
                  LIMIT %d OFFSET %d',
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'slug' => $row['slug'],
                'title' => $row['title'],
                'excerpt' => $row['excerpt'],
                'cover_image' => $row['cover_image_path'],
                'category' => $row['category'],
                'tags' => json_decode((string) $row['tags'], true) ?? [],
                'author' => $row['author_name'],
                'status' => $row['status'],
                'published_date' => $row['published_date'],
                'reading_minutes' => $row['reading_minutes'] === null ? null : (int) $row['reading_minutes'],
            ], $items),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function post(string $slug, bool $isStaff = false): array
    {
        $sql = 'SELECT * FROM `blog_posts` WHERE `slug` = :slug AND `is_deleted` = 0';

        if (!$isStaff) {
            $sql .= " AND `status` = 'published' AND `published_date` <= NOW()";
        }

        $post = $this->db->selectOne($sql, ['slug' => $slug]);

        if ($post === null) {
            throw new NotFoundException('That article does not exist.');
        }

        if (!$isStaff) {
            // Fire and forget. A view counter is not worth failing a page load
            // for, and it is not worth a lock either.
            $this->db->execute(
                'UPDATE `blog_posts` SET `view_count` = `view_count` + 1 WHERE `id` = :id',
                ['id' => (int) $post['id']]
            );
        }

        return [
            'slug' => $post['slug'],
            'title' => $post['title'],
            'excerpt' => $post['excerpt'],
            'body' => $post['body'],
            'cover_image' => $post['cover_image_path'],
            'category' => $post['category'],
            'tags' => json_decode((string) $post['tags'], true) ?? [],
            'author' => $post['author_name'],
            'status' => $post['status'],
            'published_date' => $post['published_date'],
            'reading_minutes' => $post['reading_minutes'] === null ? null : (int) $post['reading_minutes'],
            'meta_title' => $post['meta_title'] ?? $post['title'],
            'meta_description' => $post['meta_description'] ?? $post['excerpt'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function savePost(Request $request, ?string $slug, array $data): array
    {
        $existing = $slug === null
            ? null
            : $this->db->selectOne(
                'SELECT * FROM `blog_posts` WHERE `slug` = :slug AND `is_deleted` = 0',
                ['slug' => $slug]
            );

        $body = (string) ($data['body'] ?? $existing['body'] ?? '');

        // Roughly 200 words a minute, rounded up. A reading estimate is a
        // courtesy, not a measurement.
        $readingMinutes = max(1, (int) ceil(str_word_count(strip_tags($body)) / 200));

        if ($existing === null) {
            $targetSlug = $data['slug'] ?? Str::slug((string) $data['title']);

            if ($this->db->selectOne('SELECT `id` FROM `blog_posts` WHERE `slug` = :slug', ['slug' => $targetSlug]) !== null) {
                throw new HttpException('An article with that address already exists.', 409);
            }

            $author = $this->db->selectOne(
                'SELECT `full_name` FROM `users` WHERE `id` = :id',
                ['id' => $request->authUserId()]
            );

            $this->db->insert(
                'INSERT INTO `blog_posts`
                     (`uuid`, `slug`, `title`, `excerpt`, `body`, `cover_image_path`, `category`,
                      `tags`, `author_user_id`, `author_name`, `meta_title`, `meta_description`,
                      `status`, `published_date`, `reading_minutes`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :slug, :title, :excerpt, :body, :cover, :category,
                         :tags, :author_id, :author_name, :meta_title, :meta_description,
                         :status, :published_date, :reading_minutes,
                         :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'slug' => $targetSlug,
                    'title' => $data['title'],
                    'excerpt' => $data['excerpt'] ?? null,
                    'body' => $body,
                    'cover' => $data['cover_image_path'] ?? null,
                    'category' => $data['category'] ?? null,
                    'tags' => json_encode($data['tags'] ?? []),
                    'author_id' => $request->authUserId(),
                    // Snapshot: the author may leave the business.
                    'author_name' => $author['full_name'] ?? 'Team',
                    'meta_title' => $data['meta_title'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                    'status' => $data['status'] ?? 'draft',
                    'published_date' => ($data['status'] ?? 'draft') === 'published'
                        ? date('Y-m-d H:i:s')
                        : null,
                    'reading_minutes' => $readingMinutes,
                    'created_by' => $request->authUserId(),
                ]
            );

            return ['post' => $this->post($targetSlug, true), 'created' => true];
        }

        $this->db->execute(
            'UPDATE `blog_posts`
                SET `title` = :title, `excerpt` = :excerpt, `body` = :body,
                    `cover_image_path` = :cover, `category` = :category, `tags` = :tags,
                    `meta_title` = :meta_title, `meta_description` = :meta_description,
                    `status` = :status,
                    `published_date` = CASE WHEN :status_check = \'published\'
                                            THEN COALESCE(`published_date`, NOW())
                                            ELSE `published_date` END,
                    `reading_minutes` = :reading_minutes,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'title' => $data['title'] ?? $existing['title'],
                'excerpt' => $data['excerpt'] ?? $existing['excerpt'],
                'body' => $body,
                'cover' => $data['cover_image_path'] ?? $existing['cover_image_path'],
                'category' => $data['category'] ?? $existing['category'],
                'tags' => json_encode($data['tags'] ?? (json_decode((string) $existing['tags'], true) ?? [])),
                'meta_title' => $data['meta_title'] ?? $existing['meta_title'],
                'meta_description' => $data['meta_description'] ?? $existing['meta_description'],
                'status' => $data['status'] ?? $existing['status'],
                'status_check' => $data['status'] ?? $existing['status'],
                'reading_minutes' => $readingMinutes,
                'actor' => $request->authUserId(),
                'id' => (int) $existing['id'],
            ]
        );

        return ['post' => $this->post((string) $existing['slug'], true), 'created' => false];
    }

    // -----------------------------------------------------------------------
    // FAQ
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function faq(?string $group = null, ?string $search = null): array
    {
        $where = ["`status` = 'published'", '`is_deleted` = 0'];
        $bindings = [];

        if ($group !== null) {
            $where[] = '`group_code` = :group_code';
            $bindings['group_code'] = $group;
        }

        if ($search !== null && $search !== '') {
            $where[] = 'MATCH(`question`, `answer`) AGAINST (:search IN NATURAL LANGUAGE MODE)';
            $bindings['search'] = $search;
        }

        $rows = $this->db->select(
            sprintf(
                'SELECT `uuid`, `group_code`, `question`, `answer`, `display_order`, `helpful_count`
                   FROM `faq_entries` WHERE %s
                  ORDER BY `group_code`, `display_order`, `id`',
                implode(' AND ', $where)
            ),
            $bindings
        );

        // Grouped for display: a flat list of thirty questions is unreadable.
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['group_code']][] = [
                'uuid' => $row['uuid'],
                'question' => $row['question'],
                'answer' => $row['answer'],
                'helpful_count' => (int) $row['helpful_count'],
            ];
        }

        return [
            'groups' => array_map(
                static fn (string $code, array $entries): array => [
                    'code' => $code,
                    'label' => ucfirst(str_replace('_', ' ', $code)),
                    'entries' => $entries,
                ],
                array_keys($grouped),
                $grouped
            ),
            'total' => count($rows),
        ];
    }

    public function markFaqHelpful(string $uuid): array
    {
        $updated = $this->db->execute(
            'UPDATE `faq_entries` SET `helpful_count` = `helpful_count` + 1,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        );

        if ($updated === 0) {
            throw new NotFoundException('That entry does not exist.');
        }

        return ['recorded' => true];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function saveFaq(Request $request, ?string $uuid, array $data): array
    {
        if ($uuid === null) {
            $newUuid = Uuid::v4();

            $this->db->insert(
                'INSERT INTO `faq_entries`
                     (`uuid`, `group_code`, `question`, `answer`, `display_order`, `status`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :group_code, :question, :answer, :display_order, :status,
                         :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => $newUuid,
                    'group_code' => $data['group_code'] ?? 'general',
                    'question' => $data['question'],
                    'answer' => $data['answer'],
                    'display_order' => (int) ($data['display_order'] ?? 0),
                    'status' => $data['status'] ?? 'published',
                    'created_by' => $request->authUserId(),
                ]
            );

            return ['uuid' => $newUuid, 'created' => true];
        }

        $updated = $this->db->execute(
            'UPDATE `faq_entries`
                SET `group_code` = COALESCE(:group_code, `group_code`),
                    `question` = COALESCE(:question, `question`),
                    `answer` = COALESCE(:answer, `answer`),
                    `display_order` = COALESCE(:display_order, `display_order`),
                    `status` = COALESCE(:status, `status`),
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `uuid` = :uuid AND `is_deleted` = 0',
            [
                'group_code' => $data['group_code'] ?? null,
                'question' => $data['question'] ?? null,
                'answer' => $data['answer'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : null,
                'status' => $data['status'] ?? null,
                'actor' => $request->authUserId(),
                'uuid' => $uuid,
            ]
        );

        if ($updated === 0) {
            throw new NotFoundException('That entry does not exist.');
        }

        return ['uuid' => $uuid, 'created' => false];
    }
}
