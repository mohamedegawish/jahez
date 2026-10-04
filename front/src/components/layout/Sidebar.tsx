import React, { useMemo } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Factory, 
  Building2, 
  CheckCircle2, 
  Layers, 
  FileText, 
  BarChart3, 
  Users, 
  ClipboardCheck, 
  Compass, 
  Send, 
  Receipt, 
  X,
  ChevronRight,
  ChevronLeft,
  Home,
  UserPlus,
  LogIn,
  Megaphone,
  ScrollText,
  Settings,
  ShieldCheck,
  FilePen,
  Bell,
  Landmark
} from 'lucide-react';
import logoUrl from '../../../logo.jpeg';
import { api } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
  isCollapsed: boolean;
  onToggleCollapse: () => void;
}

interface NavItem {
  label: string;
  path: string;
  icon: any;
  badge?: number;
  highlight?: boolean;
  onClick?: () => void;
}

interface NavSection {
  title: string;
  items: NavItem[];
}

/** Work waiting for the signed-in user, from the API's own list totals. */
interface Badges {
  approvals: number;
  factoryApprovals?: number;
  listings?: number;
  changes?: number;
  inbox: number;
}

export const Sidebar: React.FC<SidebarProps> = ({
  isOpen,
  onClose,
  isCollapsed,
  onToggleCollapse
}) => {
  const { user, status } = useAuth();
  const location = useLocation();
  // Real server status (GET /health is public). Replaces the hard-coded uptime figure.
  // Work waiting for this user, from the API's own totals: pending provider reviews (IMC) and unanswered requests (provider).
  // Re-read when the user navigates (throttled below), so a badge clears after the work is done.
  const role = user?.role;
  // Re-read on navigation, at most once per 30 seconds, so clicking through pages does not
  // spend the API rate limit on badge counts.
  // oxlint-disable-next-line react-hooks/exhaustive-deps
  const refreshWindow = useMemo(() => Math.floor(Date.now() / 30_000), [location.pathname]);
  const badges = useApiQuery<Badges>(
    async (signal) => {
      if (role === 'imc_admin') {
        const summary = await api.reviewSummary(signal);
        return {
          approvals: summary.providers.pending,
          factoryApprovals: summary.factories.pending,
          listings: summary.listings.pending,
          changes: summary.change_requests.providers + summary.change_requests.factories,
          inbox: 0,
        };
      }
      if (role === 'provider_member') {
        // Requests awaiting a response, plus threads with unread messages.
        const [pending, unread] = await Promise.all([
          api.providerRequests.list({ per_page: 1, filter: { status: 'pending' }, signal }),
          api.providerRequests.list({ per_page: 1, filter: { unread: true }, signal }),
        ]);
        return { approvals: 0, inbox: pending.meta.total + unread.meta.total };
      }
      if (role === 'factory_member') {
        return { approvals: 0, inbox: (await api.providerRequests.list({ per_page: 1, filter: { unread: true }, signal })).meta.total };
      }
      return { approvals: 0, inbox: 0 };
    },
    [role, refreshWindow],
    { enabled: role !== undefined },
  );
  const pendingApprovals = badges.data?.approvals || undefined;
  const pendingFactoryApprovals = badges.data?.factoryApprovals || undefined;
  const pendingListings = badges.data?.listings || undefined;
  const pendingChanges = badges.data?.changes || undefined;
  const pendingInbox = badges.data?.inbox || undefined;
  const health = useApiQuery((signal) => api.health(signal), []);
  const serverUp = health.status === 'success' && health.data?.data.status === 'ok';
  const serverDown = health.status === 'error' || (health.status === 'success' && !serverUp);

  // Sections every visitor sees at the top. Portals are not listed: a signed-in user has exactly
  // one portal, decided by the server, and an anonymous visitor signs in first. IMC administrators
  // have no «الصفحة الرئيسية» item (owner brief, Phase 3): their sidebar starts at the dashboard.
  const getSharedSections = (): NavSection[] => user?.role === 'imc_admin' ? [] : [
    {
      title: user ? 'الصفحة الرئيسية' : 'الصفحة الرئيسية والتسجيل',
      items: user || status === 'loading'
        ? [{ label: 'الصفحة الرئيسية', path: '/', icon: Home }]
        : [
            { label: 'الصفحة الرئيسية', path: '/', icon: Home },
            { label: 'تسجيل الدخول', path: '/login', icon: LogIn },
            { label: 'تسجيل منشأة صناعية', path: '/register/factory', icon: UserPlus },
            { label: 'تسجيل مزود خدمة', path: '/register/provider', icon: UserPlus }
          ]
    }
  ];

  // Navigation configuration based on current portal role
  const getRoleSections = (): NavSection[] => {
    switch (user?.role) {
      case 'imc_admin':
        return [
          {
            title: 'نظرة عامة والتحليلات',
            items: [
              { label: 'لوحة القيادة والمؤشرات', path: '/admin/dashboard', icon: LayoutDashboard }
            ]
          },
          {
            title: 'المراجعة والاعتماد',
            items: [
              { label: 'اعتماد مزودي الخدمات', path: '/admin/approvals/providers', icon: CheckCircle2, badge: pendingApprovals },
              { label: 'اعتماد المنشآت الصناعية', path: '/admin/approvals/factories', icon: ShieldCheck, badge: pendingFactoryApprovals },
              { label: 'الخدمات وقوائم المزودين', path: '/admin/services', icon: Layers, badge: pendingListings },
              { label: 'تعديلات البيانات الحساسة', path: '/admin/change-requests', icon: FilePen, badge: pendingChanges }
            ]
          },
          {
            title: 'إدارة المنظومة الصناعية',
            items: [
              { label: 'المصانع المسجلة', path: '/admin/factories', icon: Factory },
              { label: 'مزودو الخدمات', path: '/admin/providers', icon: Building2 },
              { label: 'إدارة تقييم الجاهزية الرقمية', path: '/admin/readiness', icon: ClipboardCheck },
              { label: 'طلبات الخدمة والتفاوض', path: '/admin/requests', icon: Send },
              { label: 'الإعلانات والحملات الترويجية', path: '/admin/ads', icon: Megaphone }
            ]
          },
          {
            title: 'الحوكمة والماليات',
            items: [
              { label: 'الاتفاقيات والعقود', path: '/admin/contracts', icon: FileText },
              { label: 'الماليات والفواتير', path: '/admin/reports', icon: BarChart3 },
              { label: 'الإعدادات المالية والتعاقدية', path: '/admin/financial-settings', icon: Landmark },
              { label: 'حسابات المستخدمين', path: '/admin/users', icon: Users },
              { label: 'الإشعارات', path: '/notifications', icon: Bell },
              { label: 'سجل التدقيق', path: '/admin/audit-logs', icon: ScrollText }
            ]
          }
        ];

      case 'provider_member':
        return [
          {
            title: 'بوابة مزود الخدمة',
            items: [
              { label: 'لوحة التحكم', path: '/provider/dashboard', icon: LayoutDashboard },
              { label: 'خدماتي', path: '/provider/services', icon: Layers },
              { label: 'طلبات المصانع', path: '/provider/requests', icon: Send, badge: pendingInbox },
              { label: 'العقود', path: '/provider/contracts', icon: FileText },
              { label: 'الفواتير', path: '/provider/invoices', icon: Receipt },
              { label: 'التقارير', path: '/provider/reports', icon: BarChart3 },
              { label: 'الإعدادات', path: '/provider/settings', icon: Settings }
            ]
          }
        ];

      case 'factory_member':
        return [
          {
            title: 'بوابة المنشأة الصناعية',
            items: [
              { label: 'لوحة التحكم', path: '/factory/dashboard', icon: LayoutDashboard },
              { label: 'تقييم الجاهزية الرقمية', path: '/factory/assessment', icon: ClipboardCheck },
              { label: 'الخدمات', path: '/factory/services', icon: Compass },
              { label: 'خدماتي', path: '/factory/requests', icon: Layers, badge: pendingInbox },
              { label: 'العقود', path: '/factory/contracts', icon: FileText },
              { label: 'الفواتير', path: '/factory/invoices', icon: Receipt },
              { label: 'الإعدادات', path: '/factory/settings', icon: Settings }
            ]
          }
        ];

      default:
        return []; // anonymous visitor: only the shared sections
    }
  };

  const navSections: NavSection[] = [...getSharedSections(), ...getRoleSections()];

  return (
    <>
      {/* Mobile Backdrop */}
      {isOpen && (
        <div 
          className="fixed inset-0 z-40 bg-[#172033]/40 backdrop-blur-xs lg:hidden"
          onClick={onClose}
        />
      )}

      {/* Sidebar Container */}
      <aside
        className={`fixed top-0 bottom-0 right-0 z-40 bg-white border-l border-[#E6EAF0] flex flex-col transition-all duration-300 ease-in-out shadow-lg lg:shadow-none ${
          isOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'
        } ${isCollapsed ? 'lg:w-20' : 'lg:w-64'} w-72`}
      >
        {/* Top Branding Section */}
        <div className="h-16 px-4 flex items-center justify-between border-b border-[#E6EAF0] shrink-0">
          <div className="flex items-center gap-2 min-w-0">
            <img src={logoUrl} alt="JAHEZ" className={isCollapsed ? 'h-6 w-auto max-w-full object-contain' : 'h-8 w-auto object-contain'} />

            {/* Brand name beside the logo: جاهز + JAHEZ */}
            {!isCollapsed && (
              <div className="leading-none min-w-0">
                <div className="text-lg font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-l from-[#5146A5] to-[#6EC8FF]">
                  جاهز
                </div>
                <div className="mt-1 text-[9px] font-extrabold tracking-[0.22em] text-[#667085]">
                  JAHEZ
                </div>
              </div>
            )}
          </div>

          {/* Close on mobile */}
          <button
            onClick={onClose}
            className="lg:hidden p-1.5 rounded-lg text-[#667085] hover:bg-[#F1F4F9] cursor-pointer"
          >
            <X className="w-5 h-5" />
          </button>

          {/* Collapse toggle on desktop */}
          <button
            onClick={onToggleCollapse}
            className="hidden lg:flex p-1.5 rounded-lg text-[#667085] hover:bg-[#F1F4F9] hover:text-[#172033] transition-colors cursor-pointer"
            title={isCollapsed ? 'توسيع القائمة' : 'طي القائمة'}
          >
            {isCollapsed ? <ChevronLeft className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
          </button>
        </div>

        {/* Portal Indicator Pill */}
        <div className={`px-4 py-2.5 bg-[#F7F9FC] border-b border-[#E6EAF0] shrink-0 ${isCollapsed ? 'text-center' : ''}`}>
          {!isCollapsed ? (
            <span className="text-[11px] font-bold text-[#667085]">
              {user?.role === 'imc_admin' ? 'الإشراف الحكومي والحوكمة' :
               user?.role === 'provider_member' ? 'لوحة الشريك التكنولوجي' :
               user?.role === 'factory_member' ? 'بوابة المنشأة الصناعية' : 'منظومة جاهز'}
            </span>
          ) : null}
        </div>

        {/* Scrollable Navigation Items */}
        <div className="flex-1 overflow-y-auto px-3 py-4 space-y-6">
          {navSections.map((section, idx) => (
            <div key={idx} className="space-y-1">
              {!isCollapsed && (
                <div className={`px-3 mb-2 text-[10px] font-bold uppercase tracking-wider ${
                  idx === 0 ? 'text-[#5146A5]' : 'text-[#98A2B3]'
                }`}>
                  {section.title}
                </div>
              )}
              {section.items.map((item) => {
                const Icon = item.icon;
                const isActive = location.pathname === item.path || location.pathname.startsWith(`${item.path}/`);

                return (
                  <NavLink
                    key={item.path}
                    to={item.path}
                    onClick={() => {
                      item.onClick?.();
                      if (window.innerWidth < 1024) onClose();
                    }}
                    title={isCollapsed ? item.label : undefined}
                    className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold transition-all group relative cursor-pointer ${
                      isActive
                        ? 'bg-[#EEEAFE] text-[#5146A5] font-bold shadow-xs'
                        : 'text-[#667085] hover:bg-[#F7F9FC] hover:text-[#172033]'
                    } ${item.highlight && !isActive ? 'border border-[#6EC8FF]/40 bg-[#DFF3FF]/20 text-[#0A6EB0]' : ''}`}
                  >
                    <Icon className={`w-4 h-4 shrink-0 transition-transform group-hover:scale-110 ${
                      isActive ? 'text-[#5146A5]' : 'text-[#667085]'
                    }`} />

                    {!isCollapsed && (
                      <span className="truncate flex-1 text-right">{item.label}</span>
                    )}

                    {item.badge !== undefined && !isCollapsed && (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#E45B6A] text-white">
                        {item.badge}
                      </span>
                    )}

                    {/* Active vertical indicator bar */}
                    {isActive && (
                      <span className="absolute right-0 top-2 bottom-2 w-1 bg-[#5146A5] rounded-l-full" />
                    )}
                  </NavLink>
                );
              })}
            </div>
          ))}
        </div>

        {/* Bottom System Status Widget */}
        <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC] shrink-0">
          {!isCollapsed ? (
            <div className="p-2.5 rounded-xl bg-white border border-[#E6EAF0] text-xs">
              <div className="flex items-center justify-between mb-1">
                <span className="font-bold text-[#172033] text-[11px]">جاهزية المنظومة</span>
                <span className={`text-[10px] font-bold ${serverUp ? 'text-[#35B779]' : serverDown ? 'text-[#E45B6A]' : 'text-[#98A2B3]'}`}>
                  {serverUp ? 'الخادم متصل' : serverDown ? 'الخادم غير متاح' : 'جارٍ الفحص...'}
                </span>
              </div>
              <p className="text-[10px] text-[#667085] leading-relaxed">
                معتمدة من وزارة التجارة والصناعة ومركز تحديث الصناعة IMC
              </p>
            </div>
          ) : (
            <div className="w-8 h-8 mx-auto rounded-lg bg-[#E7F8EE] text-[#1D7E4C] flex items-center justify-center text-xs font-bold" title={serverUp ? 'الخادم متصل' : serverDown ? 'الخادم غير متاح' : 'جارٍ الفحص'}>
              {serverUp ? '✓' : serverDown ? '!' : '…'}
            </div>
          )}
        </div>
      </aside>
    </>
  );
};
