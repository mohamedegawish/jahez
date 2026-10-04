<?php

namespace App\Billing;

use App\Models\Agreement;
use App\Models\Factory;

/**
 * What a policy is resolved for (ADR-023): the catalog service, the factory's sectors
 * and the service provider of an agreement, read from the stored records, never from
 * the client.
 */
final readonly class PolicyContext
{
    /**
     * @param  list<int>  $sectorIds
     */
    public function __construct(
        public ?int $catalogServiceId = null,
        public array $sectorIds = [],
        public ?int $serviceProviderId = null,
    ) {}

    public static function forAgreement(Agreement $agreement): self
    {
        $sectorIds = Factory::query()->whereKey($agreement->factory_id)->first()?->sectors()->pluck('sectors.id')->map(fn (mixed $id): int => (int) $id)->all() ?? [];

        return new self($agreement->catalog_service_id, array_values($sectorIds), $agreement->service_provider_id);
    }
}
