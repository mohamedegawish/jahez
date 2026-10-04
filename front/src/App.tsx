import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthProvider';
import { RequireAuth, RequireRole } from './auth/guards';
import { AppLayout } from './layouts/AppLayout';
import { PublicLayout } from './layouts/PublicLayout';

// Auth Page
import { LoginPage } from './pages/auth/LoginPage';
import { ProviderRegister } from './pages/auth/ProviderRegister';
import { FactoryRegister } from './pages/auth/FactoryRegister';
import { ForgotPassword } from './pages/auth/ForgotPassword';
import { ResetPassword } from './pages/auth/ResetPassword';
import { Forbidden } from './pages/Forbidden';

// Admin Portal Pages
import { AdminDashboard } from './pages/admin/AdminDashboard';
import { FactoriesManagement } from './pages/admin/FactoriesManagement';
import { ProvidersManagement } from './pages/admin/ProvidersManagement';
import { ProviderApprovals } from './pages/admin/ProviderApprovals';
import { ProviderApprovalDetail } from './pages/admin/ProviderApprovalDetail';
import { FactoryApprovals } from './pages/admin/FactoryApprovals';
import { FactoryApprovalDetail } from './pages/admin/FactoryApprovalDetail';
import { ChangeRequests } from './pages/admin/ChangeRequests';
import { AdminRequests } from './pages/admin/AdminRequests';
import { ServicesManagement } from './pages/admin/ServicesManagement';
import { AdsManagement } from './pages/admin/AdsManagement';
import { ContractsManagement } from './pages/admin/ContractsManagement';
import { FinancialsReports } from './pages/admin/FinancialsReports';
import { UsersRoles } from './pages/admin/UsersRoles';
import { AuditLogs } from './pages/admin/AuditLogs';
import { ReadinessAdministration } from './pages/admin/ReadinessAdministration';
import { FinancialSettings } from './pages/admin/FinancialSettings';

// Provider Portal Pages
import { ProviderDashboard } from './pages/provider/ProviderDashboard';
import { ProviderServices } from './pages/provider/ProviderServices';
import { ProviderRequests } from './pages/provider/ProviderRequests';
import { NegotiationWorkspace } from './pages/provider/NegotiationWorkspace';
import { ProviderContracts } from './pages/provider/ProviderContracts';
import { ProviderInvoices } from './pages/provider/ProviderInvoices';
import { ProviderRequestDetail } from './pages/provider/ProviderRequestDetail';
import { ProviderReports } from './pages/provider/ProviderReports';
import { ProviderSettings } from './pages/provider/ProviderSettings';
// Factory Portal Pages
import { FactoryDashboard } from './pages/factory/FactoryDashboard';
import { DigitalReadinessAssessment } from './pages/factory/DigitalReadinessAssessment';
import { ServiceCatalog } from './pages/factory/ServiceCatalog';
import { ServiceDetails } from './pages/factory/ServiceDetails';
import { ProviderDirectory } from './pages/factory/ProviderDirectory';
import { FactoryRequests } from './pages/factory/FactoryRequests';
import { FactoryRequestDetail } from './pages/factory/FactoryRequestDetail';
import { FactoryContracts } from './pages/factory/FactoryContracts';
import { FactoryInvoices } from './pages/factory/FactoryInvoices';
import { FactoryServices } from './pages/factory/FactoryServices';
import { FactorySettings } from './pages/factory/FactorySettings';
import { NotificationsPage } from './pages/NotificationsPage';

// Home Page
import { LandingHome } from './pages/home/LandingHome';
import { AdDetails } from './pages/ads/AdDetails';

const RootRedirect: React.FC = () => {
  return <Navigate to="/" replace />;
};

export const App: React.FC = () => {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          {/* Public Auth Routes */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register/provider" element={<ProviderRegister />} />
          <Route path="/register/factory" element={<FactoryRegister />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />

          {/* Public marketing pages: no session and no portal sidebar. */}
          <Route element={<PublicLayout />}>
            <Route path="/" element={<LandingHome />} />
            <Route path="/ads/:id" element={<AdDetails />} />
          </Route>

          {/* Portals: a session is required before the sidebar layout renders. */}
          <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route path="/forbidden" element={<Forbidden />} />
            <Route path="/notifications" element={<NotificationsPage />} />

            {/* Admin Routes (imc_admin) */}
            <Route element={<RequireRole roles={['imc_admin']} />}>
            <Route path="/admin/dashboard" element={<AdminDashboard />} />
            <Route path="/admin/factories" element={<FactoriesManagement />} />
            <Route path="/admin/providers" element={<ProvidersManagement />} />
            <Route path="/admin/approvals" element={<Navigate to="/admin/approvals/providers" replace />} />
            <Route path="/admin/approvals/providers" element={<ProviderApprovals />} />
            <Route path="/admin/approvals/providers/:id" element={<ProviderApprovalDetail />} />
            <Route path="/admin/approvals/factories" element={<FactoryApprovals />} />
            <Route path="/admin/approvals/factories/:id" element={<FactoryApprovalDetail />} />
            <Route path="/admin/change-requests" element={<ChangeRequests />} />
            <Route path="/admin/services" element={<ServicesManagement />} />
            <Route path="/admin/requests" element={<AdminRequests />} />
            <Route path="/admin/ads" element={<AdsManagement />} />
            <Route path="/admin/contracts" element={<ContractsManagement />} />
            <Route path="/admin/reports" element={<FinancialsReports />} />
            <Route path="/admin/financial-settings" element={<FinancialSettings />} />
            <Route path="/admin/users" element={<UsersRoles />} />
            <Route path="/admin/audit-logs" element={<AuditLogs />} />
            <Route path="/admin/readiness" element={<ReadinessAdministration />} />
            </Route>

            {/* Provider Routes (provider_member) */}
            <Route element={<RequireRole roles={['provider_member']} />}>
            <Route path="/provider/dashboard" element={<ProviderDashboard />} />
            <Route path="/provider/profile" element={<Navigate to="/provider/settings" replace />} />
            <Route path="/provider/settings" element={<ProviderSettings />} />
            <Route path="/provider/reports" element={<ProviderReports />} />
            <Route path="/provider/requests/:id" element={<ProviderRequestDetail />} />
            <Route path="/provider/contracts/:id" element={<ProviderContracts />} />
            <Route path="/provider/services" element={<ProviderServices />} />
            <Route path="/provider/requests" element={<ProviderRequests />} />
            <Route path="/provider/negotiations" element={<NegotiationWorkspace />} />
            <Route path="/provider/contracts" element={<ProviderContracts />} />
            <Route path="/provider/invoices" element={<ProviderInvoices />} />
            </Route>

            {/* Factory Routes (factory_member) */}
            <Route element={<RequireRole roles={['factory_member']} />}>
            <Route path="/factory/dashboard" element={<FactoryDashboard />} />
            <Route path="/factory/profile" element={<Navigate to="/factory/settings" replace />} />
            <Route path="/factory/settings" element={<FactorySettings />} />
            <Route path="/factory/services" element={<FactoryServices />} />
            <Route path="/factory/contracts/:id" element={<FactoryContracts />} />
            <Route path="/factory/assessment" element={<DigitalReadinessAssessment />} />
            <Route path="/factory/catalog" element={<ServiceCatalog />} />
            <Route path="/factory/services/:id" element={<ServiceDetails />} />
            <Route path="/factory/providers" element={<ProviderDirectory />} />
            <Route path="/factory/requests" element={<FactoryRequests />} />
            <Route path="/factory/requests/:id" element={<FactoryRequestDetail />} />
            <Route path="/factory/negotiations" element={<NegotiationWorkspace />} />
            <Route path="/factory/contracts" element={<FactoryContracts />} />
            <Route path="/factory/invoices" element={<FactoryInvoices />} />
            </Route>
          </Route>
          </Route>

          {/* Fallback Catch-All */}
          <Route path="*" element={<RootRedirect />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
};

export default App;
