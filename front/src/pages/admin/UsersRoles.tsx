import React, { useState } from 'react';
import { CheckCircle2, Mail, Pencil, UserPlus } from 'lucide-react';
import { api } from '../../api';
import type { User } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useListParams } from '../../hooks/useListParams';
import { formatDate } from '../../lib/format';
import { roleLabel } from '../../lib/labels';
import { UserCreateModal, UserRenameModal } from '../../components/admin/UserFormModal';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { ConfirmModal } from '../../components/ui/ConfirmModal';
import { EmptyState } from '../../components/ui/EmptyState';
import { TableSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { UnavailableNotice } from '../../components/ui/UnavailableNotice';

const ROLE_VARIANT = { imc_admin: 'purple', factory_member: 'blue', provider_member: 'success' } as const;

type Dialog = { kind: 'create' } | { kind: 'rename'; user: User } | { kind: 'toggle'; user: User } | null;

/** Platform accounts: invite, rename, deactivate/reactivate. Role and organization never change after creation. */
export const UsersRoles: React.FC = () => {
  const { user: me } = useAuth();
  const list = useListParams([] as const);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const query = useApiQuery((signal) => api.users.list({ page: list.page, per_page: list.perPage, signal }), [list.page, list.perPage]);

  const done = (message: string) => {
    setDialog(null);
    setNotice(message);
    query.refetch();
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">حسابات المستخدمين</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">إنشاء حسابات المنشآت والمزودين وإدارة الحسابات الإدارية، وإيقاف الحسابات وإعادة تفعيلها.</p>
        </div>
        <Button variant="primary" size="sm" icon={UserPlus} data-action="invite" onClick={() => setDialog({ kind: 'create' })}>
          دعوة مستخدم جديد
        </Button>
      </div>

      {notice && (
        <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center justify-between">
          <span className="flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4" />
            {notice}
          </span>
          <button onClick={() => setNotice(null)} className="hover:underline cursor-pointer">إغلاق</button>
        </div>
      )}

      <UnavailableNotice kind="decision" title="الأدوار الإدارية التفصيلية" decisionNeeded="OQ-21">
        للمركز حاليًا دور إداري واحد بكل الصلاحيات. أدوار اللجان (مراجع، محاسب، مقيّم...) لم تُعتمد بعد، لذلك لا تُعرض ولا يمكن تعيينها.
      </UnavailableNotice>

      <QueryBoundary
        query={query}
        loading={<TableSkeleton rows={6} cols={5} />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : (
            <EmptyState title="لا توجد حسابات" description="أنشئ أول حساب." />
          )
        }
      >
        {(page) => (
          <div className="jahez-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-right border-collapse text-xs">
                <thead>
                  <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                    <th className="py-3 px-4">المستخدم</th>
                    <th className="py-3 px-4">الدور</th>
                    <th className="py-3 px-4">الجهة</th>
                    <th className="py-3 px-4">أُنشئ في</th>
                    <th className="py-3 px-4">الحالة</th>
                    <th className="py-3 px-4 text-center">الإجراءات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EAF0]">
                  {page.data.map((u) => (
                    <tr key={u.id} data-user-id={u.id} className="hover:bg-[#F7F9FC]">
                      <td className="py-3.5 px-4">
                        <div className="font-bold text-[#172033] text-sm">{u.name}</div>
                        <div className="text-[#667085] text-[11px] flex items-center gap-1 mt-0.5" dir="ltr">
                          <Mail className="w-3 h-3 text-[#98A2B3]" />
                          {u.email}
                        </div>
                      </td>
                      <td className="py-3.5 px-4">
                        <Badge variant={ROLE_VARIANT[u.role]}>{roleLabel(u.role)}</Badge>
                      </td>
                      <td className="py-3.5 px-4 text-[#667085]">{u.organization ? `${u.organization.name} (#${u.organization.id})` : '—'}</td>
                      <td className="py-3.5 px-4 text-[#667085]">{formatDate(u.created_at)}</td>
                      <td className="py-3.5 px-4">
                        <Badge variant={u.is_active ? 'success' : 'neutral'} size="sm">
                          {u.is_active ? 'نشط' : 'موقوف'}
                        </Badge>
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="flex items-center justify-center gap-2">
                          <Button variant="ghost" size="sm" icon={Pencil} data-action="rename" onClick={() => setDialog({ kind: 'rename', user: u })}>
                            تعديل
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            data-action="toggle-active"
                            disabled={u.id === me?.id && u.is_active}
                            title={u.id === me?.id && u.is_active ? 'لا يمكنك إيقاف حسابك الحالي' : undefined}
                            onClick={() => setDialog({ kind: 'toggle', user: u })}
                          >
                            {u.is_active ? 'إيقاف' : 'إعادة تفعيل'}
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
              <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
            </div>
          </div>
        )}
      </QueryBoundary>

      {dialog?.kind === 'create' && (
        <UserCreateModal onClose={() => setDialog(null)} onCreated={(created) => done(`تم إنشاء حساب ${created.name} وإرسال دعوة تعيين كلمة المرور إلى ${created.email}.`)} />
      )}
      {dialog?.kind === 'rename' && <UserRenameModal user={dialog.user} onClose={() => setDialog(null)} onSaved={(saved) => done(`تم تعديل اسم الحساب إلى ${saved.name}.`)} />}
      {dialog?.kind === 'toggle' && (
        <ConfirmModal
          title={dialog.user.is_active ? 'إيقاف الحساب' : 'إعادة تفعيل الحساب'}
          subtitle={`${dialog.user.name} — ${dialog.user.email}`}
          description={
            dialog.user.is_active
              ? 'يُنهي الإيقاف جميع جلسات المستخدم الحالية فورًا ويمنعه من الدخول إلى أن يُعاد تفعيله. لا يمكن إيقاف حسابك الحالي ولا آخر مدير نشط.'
              : 'يستطيع المستخدم الدخول مجددًا بكلمة مروره الحالية.'
          }
          confirmLabel={dialog.user.is_active ? 'تأكيد الإيقاف' : 'تأكيد إعادة التفعيل'}
          variant={dialog.user.is_active ? 'danger' : 'primary'}
          onConfirm={() => api.users.update(dialog.user.id, { is_active: !dialog.user.is_active })}
          onDone={(updated) => done(updated.is_active ? 'تمت إعادة تفعيل الحساب.' : 'تم إيقاف الحساب وإنهاء جلساته.')}
          onClose={() => setDialog(null)}
        />
      )}
    </div>
  );
};
