<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CatalogServiceResource;
use App\Http\Resources\V1\ProviderDirectoryResource;
use App\Http\Resources\V1\ReadinessAssessmentResource;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Readiness\ServiceEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * What a factory may use (ADR-025): its readiness level, the services that level and the
 * levels below it make available (ADR-026), and the providers eligible for each. The
 * assessment's score is shown to IMC only. Read by the factory's own members and
 * by IMC administrators building the factory's plan (FactoryPolicy::view); anyone else
 * gets 404. Everything is decided by ServiceEligibility; nothing in the request widens it.
 */
class FactoryServiceEligibilityController extends Controller
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    public function show(Request $request, Factory $factory): JsonResponse
    {
        Gate::authorize('view', $factory);

        $summary = $this->eligibility->summary($factory, ReadinessAssessmentResource::showsScores($request));

        return response()->json(['data' => [
            'factory_id' => $factory->id,
            'status' => $summary['status'],
            'has_sectors' => $summary['has_sectors'],
            'may_send_requests' => $factory->maySendServiceRequests(),
            'readiness' => $summary['readiness'],
            'services' => array_map(fn (array $entry): array => [
                'service' => new CatalogServiceResource($entry['service']),
                'eligible_providers_count' => $entry['eligible_providers_count'],
                'recommended' => $entry['recommended'],
            ], $summary['services']),
        ]]);
    }

    /**
     * The providers eligible for the factory and the service, by name. A service the
     * factory's level does not make available is reported as not found.
     */
    public function providers(Factory $factory, CatalogService $catalogService): AnonymousResourceCollection
    {
        Gate::authorize('view', $factory);

        if (! $this->eligibility->isServiceAvailable($factory, $catalogService)) {
            abort(404);
        }

        $providers = $this->eligibility->providersFor($factory, $catalogService)
            ->withExists(['activeDocuments as has_logo' => fn (Builder $documents) => $documents->where('type', DocumentType::Logo)])
            ->with(['sectors'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ProviderDirectoryResource::collection($providers);
    }
}
