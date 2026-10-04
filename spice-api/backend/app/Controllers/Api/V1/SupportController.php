<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\SupportService;

/** Support tickets. */
final class SupportController extends BaseController
{
    private const STATUSES = ['open', 'awaiting_customer', 'in_progress', 'resolved', 'closed'];
    private const CATEGORIES = ['order', 'delivery', 'payment', 'refund', 'product', 'account', 'wholesale', 'other'];

    public function __construct(private readonly SupportService $support)
    {
    }

    /** POST /api/v1/support/tickets */
    public function open(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'subject' => 'required|string|min:5|max:200',
            'message' => 'required|string|min:10|max:5000',
            'category' => 'nullable|in:order,delivery,payment,refund,product,account,wholesale,other',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'order_uuid' => 'nullable|uuid',
            'contact_name' => 'required|string|min:2|max:120',
            'contact_mobile' => 'required|mobile_in',
            'contact_email' => 'nullable|email',
        ]);

        return Response::created($this->support->open($request, $data), 'Ticket raised');
    }

    /** GET /api/v1/support/tickets */
    public function mine(Request $request): Response
    {
        return Response::success(['tickets' => $this->support->mine($request)], 'Your tickets loaded');
    }

    /** GET /api/v1/support/tickets/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->support->show($request, (string) $request->routeParam('uuid'), isStaff: false),
            'Ticket loaded'
        );
    }

    /** POST /api/v1/support/tickets/{uuid}/reply */
    public function reply(Request $request): Response
    {
        $data = Validator::make($request->all(), ['body' => 'required|string|min:2|max:5000']);

        return Response::success(
            $this->support->reply($request, (string) $request->routeParam('uuid'), $data['body'], false, false),
            'Reply sent'
        );
    }

    /** POST /api/v1/support/tickets/{uuid}/rate */
    public function rate(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'rating' => 'required|int|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        return Response::success(
            $this->support->rate($request, (string) $request->routeParam('uuid'), (int) $data['rating'], $data['comment'] ?? null),
            'Thank you for the feedback'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/support/tickets */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $request->query('status');
        $category = $request->query('category');

        if (is_string($status) && $status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new HttpException('Unknown ticket status: ' . $status, 422);
        }

        if (is_string($category) && $category !== '' && !in_array($category, self::CATEGORIES, true)) {
            throw new HttpException('Unknown ticket category: ' . $category, 422);
        }

        $result = $this->support->listForStaff(
            $params,
            is_string($status) && $status !== '' ? $status : null,
            is_string($category) && $category !== '' ? $category : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Tickets loaded');
    }

    /** GET /api/v1/admin/support/tickets/{uuid} */
    public function adminShow(Request $request): Response
    {
        return Response::success(
            $this->support->show($request, (string) $request->routeParam('uuid'), isStaff: true),
            'Ticket loaded'
        );
    }

    /** POST /api/v1/admin/support/tickets/{uuid}/reply */
    public function adminReply(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'body' => 'required|string|min:2|max:5000',
            'internal_note' => 'nullable|boolean',
        ]);

        return Response::success(
            $this->support->reply(
                $request,
                (string) $request->routeParam('uuid'),
                $data['body'],
                (bool) ($data['internal_note'] ?? false),
                true
            ),
            'Reply added'
        );
    }

    /** POST /api/v1/admin/support/tickets/{uuid}/assign */
    public function assign(Request $request): Response
    {
        $data = Validator::make($request->all(), ['assignee_uuid' => 'required|uuid']);

        return Response::success(
            $this->support->assign($request, (string) $request->routeParam('uuid'), $data['assignee_uuid']),
            'Ticket assigned'
        );
    }

    /** GET /api/v1/admin/support/staff — who a ticket can be assigned to. */
    public function assignableStaff(Request $request): Response
    {
        return Response::success(['staff' => $this->support->assignableStaff()], 'Staff loaded');
    }

    /** POST /api/v1/admin/support/tickets/{uuid}/resolve */
    public function resolve(Request $request): Response
    {
        $data = Validator::make($request->all(), ['note' => 'required|string|min:5|max:1000']);

        return Response::success(
            $this->support->resolve($request, (string) $request->routeParam('uuid'), $data['note']),
            'Ticket resolved'
        );
    }
}
