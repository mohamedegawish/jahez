import React from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { ApiErrorState } from '../../components/ui/ApiErrorState';

/**
 * Former global negotiation page (`/provider/negotiations`, `/factory/negotiations`). Negotiation now
 * lives inside each request's details, so old links (`?thread=ID`) are forwarded there.
 */
export const NegotiationWorkspace: React.FC = () => {
  const { user } = useAuth();
  const [params] = useSearchParams();
  const threadId = Number.parseInt(params.get('thread') ?? '', 10) || null;
  const isProvider = user?.role === 'provider_member';

  const thread = useApiQuery((signal) => api.providerRequests.get(threadId as number, signal), [threadId], {
    enabled: threadId !== null && !isProvider,
  });

  if (threadId === null) return <Navigate to={isProvider ? '/provider/requests' : '/factory/requests'} replace />;
  if (isProvider) return <Navigate to={`/provider/requests/${threadId}`} replace />;
  if (thread.status === 'error') return <ApiErrorState error={thread.error} onRetry={thread.refetch} />;
  if (thread.status === 'loading') return <CardSkeleton />;

  const requestId = thread.data?.service_request?.id;
  return <Navigate to={requestId ? `/factory/requests/${requestId}?thread=${threadId}` : '/factory/requests'} replace />;
};
