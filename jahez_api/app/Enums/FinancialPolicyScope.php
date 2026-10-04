<?php

namespace App\Enums;

/**
 * What a financial policy applies to (ADR-023). When policies of several scopes apply to
 * the same agreement, the most specific one wins: a service provider, then a catalog
 * service, then a sector, then the global policy (PROPOSED precedence, OQ-47).
 */
enum FinancialPolicyScope: string
{
    case Global = 'global';
    case Sector = 'sector';
    case CatalogService = 'catalog_service';
    case ServiceProvider = 'service_provider';

    /**
     * Higher wins.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::ServiceProvider => 4,
            self::CatalogService => 3,
            self::Sector => 2,
            self::Global => 1,
        };
    }

    public function key(?int $scopeId): string
    {
        return $this === self::Global ? 'global' : "{$this->value}:{$scopeId}";
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Global => 'كل الخدمات',
            self::Sector => 'قطاع',
            self::CatalogService => 'خدمة',
            self::ServiceProvider => 'مزود خدمة',
        };
    }
}
