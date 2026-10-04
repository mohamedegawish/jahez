import React from 'react';
import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import type { Factory } from '../../api';

const TEXT: Record<Factory['approval']['status'], string> = {
  pending: 'ملف منشأتكم قيد المراجعة لدى مركز تحديث الصناعة. يمكنكم استكمال البيانات وإجراء تقييم الجاهزية الآن، وإرسال طلبات الخدمة بعد الاعتماد.',
  changes_requested: 'طلب المركز استكمال بيانات منشأتكم أو تصويبها. حدّثوا البيانات ثم اطلبوا مراجعة جديدة من الإعدادات.',
  rejected: 'رُفض اعتماد ملف منشأتكم. راجعوا السبب في الإعدادات، ويمكنكم التصويب وطلب مراجعة جديدة.',
  suspended: 'اعتماد منشأتكم موقوف حاليًا، فلا يمكن إرسال طلبات خدمة جديدة حتى إعادة الاعتماد.',
  approved: '',
};

/**
 * Why a factory cannot send service requests yet (ADR-021, OQ-46): shown only while the API says so
 * (`approval.may_send_requests`), with the way forward. The readiness assessment is never blocked.
 */
export const FactoryApprovalNotice: React.FC<{ factory: Factory; compact?: boolean }> = ({ factory, compact = false }) => {
  if (factory.approval.may_send_requests) return null;
  return (
    <div role="status" className="p-4 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs flex items-start gap-3" data-testid="factory-approval-notice">
      <ShieldAlert className="w-5 h-5 text-[#A66F0B] shrink-0" />
      <div className="space-y-1">
        <p className="font-bold text-[#A66F0B]">لا يمكن إرسال طلبات الخدمة قبل اعتماد المنشأة</p>
        <p className="text-[#8C5D08] leading-relaxed">{TEXT[factory.approval.status]}</p>
        {!compact && (
          <Link to="/factory/settings" className="inline-block font-semibold text-[#5146A5] hover:underline">
            عرض حالة الاعتماد
          </Link>
        )}
      </div>
    </div>
  );
};
