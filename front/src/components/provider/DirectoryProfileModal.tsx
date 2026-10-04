import React from 'react';
import { ExternalLink } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { Button } from '../ui/Button';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';
import { UnavailableNotice } from '../ui/UnavailableNotice';

/** A provider's public directory profile (GET /provider-directory/{id}). */
export const DirectoryProfileModal: React.FC<{ providerId: number; onClose: () => void }> = ({ providerId, onClose }) => {
  const profile = useApiQuery((signal) => api.directory.get(providerId, signal), [providerId]);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={profile.data?.name ?? 'ملف المزود'}
      subtitle="الملف التعريفي المعتمد من مركز تحديث الصناعة"
      maxWidth="2xl"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          إغلاق
        </Button>
      }
    >
      <QueryBoundary query={profile} loading={<CardSkeleton />}>
        {(p) => (
          <div className="space-y-5 text-xs">
            <div className="grid grid-cols-2 gap-3">
              <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <span className="text-[10px] text-[#98A2B3] block">الخبرة في التحول الرقمي</span>
                <span className="font-bold text-[#172033]">{p.dx_experience_years === null ? '—' : `${p.dx_experience_years} سنة`}</span>
              </div>
              <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <span className="text-[10px] text-[#98A2B3] block">الموقع الإلكتروني</span>
                {p.website ? (
                  <a href={p.website} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-bold text-[#0A6EB0] hover:underline" dir="ltr">
                    {p.website.replace(/^https?:\/\//, '')} <ExternalLink className="w-3 h-3" />
                  </a>
                ) : (
                  <span className="font-bold text-[#172033]">—</span>
                )}
              </div>
            </div>

            <div>
              <span className="text-[10px] text-[#98A2B3] block mb-1">القطاعات المستهدفة</span>
              <div className="flex flex-wrap gap-1">
                {(p.sectors ?? []).map((sector) => (
                  <span key={sector.code} className="px-2 py-0.5 rounded-full bg-[#DFF3FF] text-[#0A6EB0] text-[11px] font-semibold">
                    {sector.name_ar}
                  </span>
                ))}
              </div>
            </div>

            <div>
              <span className="text-[10px] text-[#98A2B3] block mb-1">الخدمات المقدمة ({(p.services ?? []).length})</span>
              <div className="flex flex-wrap gap-1.5">
                {(p.services ?? []).map((service) => (
                  <span key={service.code} className="px-2 py-1 rounded-lg bg-[#EEEAFE] text-[#5146A5] text-[11px] font-semibold">
                    {service.name_ar}
                  </span>
                ))}
              </div>
            </div>

            {/* Contact details exist in the response only when the owner enabled them (OQ-37). */}
            {'email' in p || 'phone' in p || 'representative_name' in p ? (
              <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-1">
                <span className="text-[10px] font-bold text-[#98A2B3] block">بيانات التواصل</span>
                {p.representative_name && <div className="text-[#172033] font-semibold">{p.representative_name}{p.job_title ? ` — ${p.job_title}` : ''}</div>}
                {p.email && <div dir="ltr" className="text-right text-[#667085]">{p.email}</div>}
                {p.phone && <div dir="ltr" className="text-right text-[#667085]">{p.phone}</div>}
              </div>
            ) : (
              <UnavailableNotice kind="decision" title="بيانات التواصل" decisionNeeded="OQ-37">
                لا تُعرض بيانات التواصل مع المزود قبل قرار المركز بشأن إتاحتها للمصانع. يتم التواصل عبر طلب خدمة داخل المنصة.
              </UnavailableNotice>
            )}
          </div>
        )}
      </QueryBoundary>
    </Modal>
  );
};
