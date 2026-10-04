<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ContentService;

/** CMS pages, blog and FAQ. */
final class ContentController extends BaseController
{
    public function __construct(private readonly ContentService $content)
    {
    }

    /** GET /api/v1/content/pages */
    public function pages(Request $request): Response
    {
        return Response::success(['pages' => $this->content->publishedPages()], 'Pages loaded');
    }

    /** GET /api/v1/content/pages/{slug} */
    public function page(Request $request): Response
    {
        return Response::success(
            ['page' => $this->content->page((string) $request->routeParam('slug'))],
            'Page loaded'
        );
    }

    /** GET /api/v1/content/posts */
    public function posts(Request $request): Response
    {
        $params = $this->paginationParams($request, 'published_date', 50);
        $category = $request->query('category');
        $search = $request->query('q');

        $result = $this->content->posts(
            $params,
            is_string($category) && $category !== '' ? $category : null,
            is_string($search) ? $search : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Articles loaded');
    }

    /** GET /api/v1/content/posts/{slug} */
    public function post(Request $request): Response
    {
        return Response::success(
            ['post' => $this->content->post((string) $request->routeParam('slug'))],
            'Article loaded'
        );
    }

    /** GET /api/v1/content/faq */
    public function faq(Request $request): Response
    {
        $group = $request->query('group');
        $search = $request->query('q');

        return Response::success(
            $this->content->faq(
                is_string($group) && $group !== '' ? $group : null,
                is_string($search) ? $search : null
            ),
            'FAQ loaded'
        );
    }

    /** POST /api/v1/content/faq/{uuid}/helpful */
    public function faqHelpful(Request $request): Response
    {
        return Response::success(
            $this->content->markFaqHelpful((string) $request->routeParam('uuid')),
            'Thank you'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** POST /api/v1/admin/content/pages */
    public function savePage(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'slug' => 'nullable|slug|max:160',
            'title' => 'required|string|min:2|max:200',
            'body' => 'required|string|min:10',
            'excerpt' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:320',
            'status' => 'nullable|in:draft,published,archived',
            'display_order' => 'nullable|int|min:0|max:9999',
        ]);

        return Response::created($this->content->savePage($request, null, $data), 'Page saved');
    }

    /** PATCH /api/v1/admin/content/pages/{slug} */
    public function updatePage(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'nullable|string|min:2|max:200',
            'body' => 'nullable|string|min:10',
            'excerpt' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:320',
            'status' => 'nullable|in:draft,published,archived',
            'display_order' => 'nullable|int|min:0|max:9999',
        ]);

        return Response::success(
            $this->content->savePage($request, (string) $request->routeParam('slug'), $data),
            'Page updated'
        );
    }

    /** DELETE /api/v1/admin/content/pages/{slug} */
    public function deletePage(Request $request): Response
    {
        return Response::success(
            $this->content->deletePage($request, (string) $request->routeParam('slug')),
            'Page removed'
        );
    }

    /** GET /api/v1/admin/content/pages/{slug} */
    public function adminPage(Request $request): Response
    {
        return Response::success(
            ['page' => $this->content->page((string) $request->routeParam('slug'), isStaff: true)],
            'Page loaded'
        );
    }

    /** POST /api/v1/admin/content/posts */
    public function savePost(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'slug' => 'nullable|slug|max:160',
            'title' => 'required|string|min:2|max:200',
            'body' => 'required|string|min:10',
            'excerpt' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:60',
            'tags' => 'nullable|array|max:20',
            'cover_image_path' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:320',
            'status' => 'nullable|in:draft,published,archived',
        ]);

        return Response::created($this->content->savePost($request, null, $data), 'Article saved');
    }

    /** PATCH /api/v1/admin/content/posts/{slug} */
    public function updatePost(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'nullable|string|min:2|max:200',
            'body' => 'nullable|string|min:10',
            'excerpt' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:60',
            'tags' => 'nullable|array|max:20',
            'cover_image_path' => 'nullable|string|max:500',
            'status' => 'nullable|in:draft,published,archived',
        ]);

        return Response::success(
            $this->content->savePost($request, (string) $request->routeParam('slug'), $data),
            'Article updated'
        );
    }

    /** POST /api/v1/admin/content/faq */
    public function saveFaq(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'group_code' => 'nullable|string|max:60',
            'question' => 'required|string|min:5|max:300',
            'answer' => 'required|string|min:5',
            'display_order' => 'nullable|int|min:0|max:9999',
            'status' => 'nullable|in:draft,published',
        ]);

        return Response::created($this->content->saveFaq($request, null, $data), 'FAQ entry saved');
    }

    /** PATCH /api/v1/admin/content/faq/{uuid} */
    public function updateFaq(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'group_code' => 'nullable|string|max:60',
            'question' => 'nullable|string|min:5|max:300',
            'answer' => 'nullable|string|min:5',
            'display_order' => 'nullable|int|min:0|max:9999',
            'status' => 'nullable|in:draft,published',
        ]);

        return Response::success(
            $this->content->saveFaq($request, (string) $request->routeParam('uuid'), $data),
            'FAQ entry updated'
        );
    }
}
