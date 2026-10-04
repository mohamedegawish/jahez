<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAuditLogsRequest;
use App\Http\Resources\V1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    /**
     * Newest entries first, cursor-paginated (stable for an append-only table). The
     * page links keep the filters and page size of the request.
     */
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        $entries = AuditLog::query()
            ->with('actor')
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->when($request->filled('actor_user_id'), fn ($query) => $query->where('actor_user_id', $request->integer('actor_user_id')))
            ->when($request->filled('subject_type'), fn ($query) => $query
                ->where('subject_type', $request->string('subject_type')->toString())
                ->where('subject_id', $request->integer('subject_id')))
            ->when($request->fromTime(), fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($request->toTime(), fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return AuditLogResource::collection($entries);
    }
}
