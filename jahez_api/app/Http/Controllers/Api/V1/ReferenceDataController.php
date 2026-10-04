<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EvaluationCriterionResource;
use App\Http\Resources\V1\MaturityTierResource;
use App\Http\Resources\V1\PathwayResource;
use App\Http\Resources\V1\SectorResource;
use App\Models\EvaluationCriterion;
use App\Models\MaturityTier;
use App\Models\Pathway;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only reference data from the source document (ADR-010), for any signed-in
 * account: the lists clients need to fill in sectors, sizes, tiers and evaluations.
 * The text is the seeded source text. Bounded lists, so not paginated.
 */
class ReferenceDataController extends Controller
{
    public function sectors(): AnonymousResourceCollection
    {
        return SectorResource::collection(Sector::query()->orderBy('sort_order')->get());
    }

    /**
     * The company sizes a factory may declare (OQ-04 interim: a configurable list).
     */
    public function factorySizes(): JsonResponse
    {
        $sizes = [];
        foreach ((array) config('jahez.factories.sizes') as $code => $nameAr) {
            $sizes[] = ['code' => $code, 'name_ar' => $nameAr];
        }

        return response()->json(['data' => $sizes]);
    }

    public function maturityTiers(): AnonymousResourceCollection
    {
        return MaturityTierResource::collection(
            MaturityTier::query()->with('pathwayLevel.pathway')->orderBy('sort_order')->get()
        );
    }

    /**
     * Both pathways with their levels, each level's scope items and its provider
     * requirements (DOC §3).
     */
    public function pathways(): AnonymousResourceCollection
    {
        return PathwayResource::collection(
            Pathway::query()
                ->with(['levels.scopeItems', 'levels.providerRequirements'])
                ->orderBy('sort_order')
                ->get()
        );
    }

    /**
     * The current version of the provider evaluation matrix (DOC §6). The weights are the
     * source's; the scale and pass mark are not approved (OQ-13).
     */
    public function evaluationCriteria(): AnonymousResourceCollection
    {
        return EvaluationCriterionResource::collection(
            EvaluationCriterion::query()
                ->where('version', (int) EvaluationCriterion::query()->max('version'))
                ->orderBy('sort_order')
                ->get()
        );
    }
}
