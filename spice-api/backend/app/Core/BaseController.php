<?php

declare(strict_types=1);

namespace App\Core;

abstract class BaseController
{
    /**
     * Pagination arguments shared by every list endpoint.
     *
     * @return array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string}
     */
    protected function paginationParams(
        Request $request,
        string $defaultSort = 'created_date',
        int $maxPerPage = 100,
    ): array {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, $maxPerPage));
        $direction = strtoupper((string) $request->query('direction', 'DESC'));
        $search = $request->query('search');

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'sort' => (string) $request->query('sort', $defaultSort),
            'direction' => $direction === 'ASC' ? 'ASC' : 'DESC',
            'search' => is_string($search) && $search !== '' ? $search : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function paginated(array $items, int $total, array $params, string $message = ''): Response
    {
        return Response::success($items, $message, 200, [
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'total' => $total,
            'total_pages' => $params['per_page'] > 0 ? (int) ceil($total / $params['per_page']) : 0,
        ]);
    }
}
