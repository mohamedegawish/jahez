import { api } from '../api';
import type { Factory, ServiceProvider } from '../api';
import { useAuth } from '../auth/authContext';
import { useApiQuery, type ApiQuery } from './useApiQuery';

/**
 * The signed-in user's own organization comes from `GET /me` (`organization`), never from the UI.
 * These hooks load that organization's record; ids are integers exactly as the API returns them.
 */
export function useMyFactoryId(): number | null {
  const { user } = useAuth();
  return user?.organization?.type === 'factory' ? user.organization.id : null;
}

export function useMyProviderId(): number | null {
  const { user } = useAuth();
  return user?.organization?.type === 'service_provider' ? user.organization.id : null;
}

export function useMyFactory(): ApiQuery<Factory> & { factoryId: number | null } {
  const factoryId = useMyFactoryId();
  const query = useApiQuery((signal) => api.factories.get(factoryId as number, signal), [factoryId], {
    enabled: factoryId !== null,
  });
  return { ...query, factoryId };
}

export function useMyProvider(): ApiQuery<ServiceProvider> & { providerId: number | null } {
  const providerId = useMyProviderId();
  const query = useApiQuery((signal) => api.serviceProviders.get(providerId as number, signal), [providerId], {
    enabled: providerId !== null,
  });
  return { ...query, providerId };
}
