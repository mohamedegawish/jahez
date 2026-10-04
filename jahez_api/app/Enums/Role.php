<?php

namespace App\Enums;

/**
 * Account roles (ADR-006). IMC administrators act platform-wide through named
 * permissions; factory and provider members act only inside their own organization,
 * which the policies decide from the user's organization link, never from request input.
 */
enum Role: string
{
    case ImcAdmin = 'imc_admin';
    case FactoryMember = 'factory_member';
    case ProviderMember = 'provider_member';

    /**
     * Platform-wide permissions granted to the role. The permissions
     * Permission::grantedIndividually() lists are never among them (ADR-023).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::ImcAdmin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => ! in_array($permission, Permission::grantedIndividually(), true),
            )),
            self::FactoryMember, self::ProviderMember => [],
        };
    }
}
