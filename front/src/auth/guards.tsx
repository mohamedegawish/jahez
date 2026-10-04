import React from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import type { Role } from '../api/types';
import { ApiErrorState } from '../components/ui/ApiErrorState';
import { Button } from '../components/ui/Button';
import { useAuth } from './authContext';

const FullPageSpinner: React.FC = () => (
  <div className="min-h-screen flex items-center justify-center bg-[#F7F9FC]" role="status" aria-label="جارٍ التحقق من الجلسة">
    <span className="w-8 h-8 border-[3px] border-[#5146A5] border-t-transparent rounded-full animate-spin" />
  </div>
);

/**
 * Everything under it needs a valid session. The session is verified with the API (GET /me), so a
 * forged or stale localStorage entry never reveals a protected page. Visitors are sent to /login
 * and come back to the page they asked for.
 */
export const RequireAuth: React.FC = () => {
  const { status, error, retry } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <FullPageSpinner />;
  if (status === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#F7F9FC] p-6" dir="rtl">
        <ApiErrorState error={error} onRetry={retry} className="max-w-lg w-full bg-white" />
      </div>
    );
  }
  if (status === 'anonymous') {
    return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />;
  }
  return <Outlet />;
};

/**
 * Role gate for a portal. This only decides what the UI renders: every API call is authorized
 * again by the server (policies), which stays the real security boundary.
 */
export const RequireRole: React.FC<{ roles: Role[] }> = ({ roles }) => {
  const { user } = useAuth();
  if (!user || !roles.includes(user.role)) return <Navigate to="/forbidden" replace />;
  return <Outlet />;
};

/** Shown to an authenticated user who opened a portal that is not theirs. */
export const AccessDenied: React.FC<{ onHome: () => void }> = ({ onHome }) => (
  <div className="max-w-lg mx-auto text-center space-y-4 py-16">
    <h2 className="text-xl font-bold text-[#172033]">لا تملك صلاحية الوصول إلى هذه الصفحة</h2>
    <p className="text-sm text-[#667085] leading-relaxed">
      هذه الصفحة تخص بوابة أخرى من المنظومة. الصلاحيات تحددها بيانات حسابك لدى الخادم ولا يمكن تغييرها من الواجهة.
    </p>
    <Button variant="primary" onClick={onHome}>
      الذهاب إلى بوابتي
    </Button>
  </div>
);
