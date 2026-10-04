<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProviderRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListNegotiationMessagesRequest;
use App\Http\Requests\Api\V1\StoreNegotiationMessageRequest;
use App\Http\Resources\V1\NegotiationMessageResource;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\ProviderRequestRead;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Private negotiation messages between a factory and one provider (ADR-015). Messages
 * are conversation, not formal offers. Their content is never written to the audit log.
 */
class NegotiationMessageController extends Controller
{
    /**
     * Oldest first, paginated.
     */
    public function index(ListNegotiationMessagesRequest $request, ProviderRequest $providerRequest): AnonymousResourceCollection
    {
        $messages = $providerRequest->messages()
            ->with('author')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return NegotiationMessageResource::collection($messages);
    }

    /**
     * Messages can be sent only while the negotiation is open (status `accepted`) and IMC
     * has not suspended the provider. Only the thread is locked: a message changes
     * nothing on the parent request.
     */
    public function store(StoreNegotiationMessageRequest $request, ProviderRequest $providerRequest, #[CurrentUser] User $user): JsonResponse
    {
        $message = DB::transaction(function () use ($request, $providerRequest, $user): ProviderRequestMessage {
            $locked = ProviderRequest::lockThread($providerRequest->id);

            if ($locked->status !== ProviderRequestStatus::Accepted) {
                throw new ConflictHttpException("Messages can be sent only while the negotiation is open; this provider request is {$locked->status->value}.");
            }

            $locked->ensureProviderApproved();

            $message = new ProviderRequestMessage;
            $message->provider_request_id = $locked->id;
            $message->author_user_id = $user->id;
            $message->author_side = (string) $locked->sideOf($user);
            $message->body = $request->string('body')->toString();
            $message->save();

            ProviderRequestRead::markRead($locked->id, $user, $message->id);
            MarketplaceNotifications::messagePosted($locked, $message);

            return $message;
        });

        return (new NegotiationMessageResource($message->load('author')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
