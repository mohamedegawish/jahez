<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FactoryApprovalStatus;
use App\Enums\Permission;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryProfileChangeRequest;
use App\Models\ProviderProfileChangeRequest;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReviewSummaryController extends Controller
{
    /**
     * IMC review queues in one response (ADR-021): providers and factories per approval
     * status, provider listings per review status, and the open legal change requests.
     * It replaces a list request per status in the sidebar badges and the queue tabs,
     * which kept reviewers close to the API rate limit. Every status is present, zero
     * included.
     */
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        abort_unless($user->hasPermission(Permission::ServiceProvidersViewAny) && $user->hasPermission(Permission::FactoriesViewAny), 403);

        $count = fn (array $cases, array $totals): array => collect($cases)
            ->mapWithKeys(fn (\BackedEnum $case): array => [$case->value => (int) ($totals[$case->value] ?? 0)])
            ->all();

        return response()->json(['data' => [
            'providers' => $count(ProviderApprovalStatus::cases(), ServiceProvider::query()->groupBy('approval_status')->selectRaw('approval_status, COUNT(*) AS total')->pluck('total', 'approval_status')->all()),
            'factories' => $count(FactoryApprovalStatus::cases(), Factory::query()->groupBy('approval_status')->selectRaw('approval_status, COUNT(*) AS total')->pluck('total', 'approval_status')->all()),
            'listings' => $count(ServiceListingStatus::cases(), DB::table('catalog_service_service_provider')->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')->all()),
            'change_requests' => [
                'providers' => ProviderProfileChangeRequest::query()->where('is_open', true)->count(),
                'factories' => FactoryProfileChangeRequest::query()->where('is_open', true)->count(),
            ],
        ]]);
    }
}
