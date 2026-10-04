import React from 'react';
import { useNavigate } from 'react-router-dom';
import { AccessDenied } from '../auth/guards';
import { portalFor, useAuth } from '../auth/authContext';

/** Reached when an authenticated user opens a portal that does not belong to their role. */
export const Forbidden: React.FC = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  return <AccessDenied onHome={() => navigate(user ? portalFor(user.role) : '/login', { replace: true })} />;
};
