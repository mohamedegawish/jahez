import React, { useMemo, useRef, useState } from 'react';
import { CheckCircle2, ImagePlus, Save, Trash2, Upload } from 'lucide-react';
import { api, publicApiUrl } from '../../api';
import type { AdminSiteSetting } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { ConfirmModal } from '../../components/ui/ConfirmModal';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import {
  SECTION_ORDER,
  SECTION_TITLES,
  buildSiteSettingsPayload,
  labelFor,
} from '../../site/siteSettings';

type Draft = Record<string, { ar: string; en: string }>;

function toDraft(settings: AdminSiteSetting[]): Draft {
  const next: Draft = {};
  for (const setting of settings) {
    if (setting.type === 'image') continue;
    next[setting.key] = { ar: setting.ar ?? '', en: setting.en ?? '' };
  }
  return next;
}

/**
 * Landing-page content for IMC administrators: every text of the public homepage
 * in Arabic and English, plus the brand logo. Empty fields fall back to the web
 * client's built-in copy; saving stores them and clears the public cache at once.
 */
export const SiteSettings: React.FC = () => {
  const list = useApiQuery((signal) => api.siteSettings.adminList(signal), []);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">محتوى الصفحة الرئيسية</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            كل نصوص الصفحة الرئيسية بالعربية والإنجليزية، والشعار. الحقل الفارغ يعرض النص الأصلي.
          </p>
        </div>
      </div>

      <QueryBoundary
        query={list}
        loading={<CardSkeleton />}
        isEmpty={(result) => result.length === 0}
        empty={<EmptyState title="لا توجد إعدادات" description="فشل تحميل الإعدادات من الخادم." />}
      >
        {(result) => <SettingsForm settings={result} onChanged={list.refetch} />}
      </QueryBoundary>
    </div>
  );
};

const SettingsForm: React.FC<{ settings: AdminSiteSetting[]; onChanged: () => void }> = ({ settings, onChanged }) => {
  // The form mounts only after the list loads (QueryBoundary), so the draft starts from it;
  // a successful save refreshes the draft from the response — no synchronizing effect.
  const [draft, setDraft] = useState<Draft>(() => toDraft(settings));
  const [justSaved, setJustSaved] = useState(false);
  const [deletingLogo, setDeletingLogo] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const save = useApiMutation((payload: Parameters<typeof api.siteSettings.updateBulk>[0]) =>
    api.siteSettings.updateBulk(payload),
  );
  const upload = useApiMutation((uploadFile: File) => api.siteSettings.uploadLogo(uploadFile));
  const remove = useApiMutation(() => api.siteSettings.removeLogo());

  const logo = settings.find((setting) => setting.key === 'brand.logo');
  const logoUrl = logo?.image_url ? publicApiUrl(logo.image_url) : null;

  const groups = useMemo(() => {
    const bySection = new Map<string, AdminSiteSetting[]>();
    for (const setting of settings) {
      if (setting.type === 'image') continue;
      const list = bySection.get(setting.section) ?? [];
      list.push(setting);
      bySection.set(setting.section, list);
    }
    return SECTION_ORDER.map((section) => ({ section, items: bySection.get(section) ?? [] })).filter(
      (group) => group.items.length > 0,
    );
  }, [settings]);

  const setValue = (key: string, lang: 'ar' | 'en', value: string) => {
    setJustSaved(false);
    setDraft((prev) => ({ ...prev, [key]: { ar: prev[key]?.ar ?? '', en: prev[key]?.en ?? '', [lang]: value } }));
  };

  const handleSave = async () => {
    const result = await save.run(buildSiteSettingsPayload(draft));
    if (result.ok) {
      setDraft(toDraft(result.data));
      setJustSaved(true);
      onChanged();
    }
  };

  const handleUpload = async (uploadFile: File) => {
    const result = await upload.run(uploadFile);
    if (result.ok) onChanged();
  };

  return (
    <div className="space-y-6">
      {/* Brand logo */}
      <section className="jahez-card p-6 sm:p-8" aria-label={SECTION_TITLES.brand}>
        <h3 className="text-base font-bold text-[#172033]">{SECTION_TITLES.brand}</h3>
        <p className="text-xs text-[#667085] mt-0.5">يظهر في الترويسة العلوية للصفحات العامة. JPG أو PNG أو WebP.</p>
        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4 mt-4">
          <div className="w-32 h-20 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] flex items-center justify-center overflow-hidden">
            {logoUrl ? (
              <img src={logoUrl} alt={logo?.ar ?? 'الشعار'} className="max-w-full max-h-full object-contain" />
            ) : (
              <span className="text-[11px] text-[#98A2B3]">لا يوجد شعار مرفوع</span>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(event) => {
                const selected = event.target.files?.[0];
                event.target.value = '';
                if (selected) void handleUpload(selected);
              }}
            />
            <Button size="sm" variant="outline" icon={logoUrl ? Upload : ImagePlus} isLoading={upload.pending} onClick={() => fileRef.current?.click()}>
              {logoUrl ? 'استبدال الشعار' : 'رفع الشعار'}
            </Button>
            {logoUrl && (
              <Button size="sm" variant="ghost" icon={Trash2} isLoading={remove.pending} onClick={() => setDeletingLogo(true)}>
                حذف
              </Button>
            )}
          </div>
        </div>
        {upload.error !== null && <ApiErrorState compact error={upload.error} />}
      </section>

      {/* Text sections */}
      {groups.map((group) => (
        <section key={group.section} className="jahez-card p-6 sm:p-8" aria-label={SECTION_TITLES[group.section] ?? group.section}>
          <h3 className="text-base font-bold text-[#172033] mb-4">{SECTION_TITLES[group.section] ?? group.section}</h3>
          <div className="space-y-5">
            {group.items.map((setting) => {
              const value = draft[setting.key] ?? { ar: '', en: '' };
              return (
                <div key={setting.key}>
                  <div className="flex items-baseline justify-between gap-2 mb-1.5">
                    <label className="text-xs font-bold text-[#172033]">{labelFor(setting.key)}</label>
                    {setting.max !== null && (
                      <span className="text-[10px] text-[#98A2B3]">بحد أقصى {setting.max} حرف</span>
                    )}
                  </div>
                  <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    {(['ar', 'en'] as const).map((lang) => (
                      <div key={lang}>
                        <span className="block text-[10px] font-bold text-[#667085] mb-1">
                          {lang === 'ar' ? 'العربية' : 'English'}
                        </span>
                        {setting.type === 'textarea' ? (
                          <textarea
                            value={value[lang]}
                            maxLength={setting.max ?? undefined}
                            rows={3}
                            onChange={(event) => setValue(setting.key, lang, event.target.value)}
                            placeholder={lang === 'ar' ? 'يُعرض النص الأصلي عند الترك فارغًا' : 'Falls back to the built-in copy when empty'}
                            className="w-full rounded-xl border border-[#E6EAF0] bg-white px-3 py-2 text-xs text-[#172033] placeholder:text-[#98A2B3] focus:outline-none focus:ring-2 focus:ring-[#5146A5]/30 focus:border-[#5146A5]"
                          />
                        ) : (
                          <input
                            value={value[lang]}
                            maxLength={setting.max ?? undefined}
                            onChange={(event) => setValue(setting.key, lang, event.target.value)}
                            placeholder={lang === 'ar' ? 'يُعرض النص الأصلي عند الترك فارغًا' : 'Falls back to the built-in copy when empty'}
                            className="w-full rounded-xl border border-[#E6EAF0] bg-white px-3 py-2 text-xs text-[#172033] placeholder:text-[#98A2B3] focus:outline-none focus:ring-2 focus:ring-[#5146A5]/30 focus:border-[#5146A5]"
                          />
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </section>
      ))}

      {/* Save bar */}
      <div className="jahez-card p-4 sm:p-5 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <Button size="md" variant="primary" icon={Save} isLoading={save.pending} onClick={() => void handleSave()}>
          حفظ كل التعديلات
        </Button>
        {save.error !== null && <ApiErrorState compact error={save.error} />}
        {remove.error !== null && <ApiErrorState compact error={remove.error} />}
        {save.error === null && remove.error === null && justSaved && (
          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-[#1D7E4C]">
            <CheckCircle2 className="w-4 h-4" />
            تم الحفظ — يظهر التعديل في الصفحة الرئيسية فورًا
          </span>
        )}
      </div>

      {deletingLogo && (
        <ConfirmModal
          title="حذف الشعار"
          description="حذف الشعار المرفوع؟ ستعرض الصفحة الشعار الأصلي."
          confirmLabel="حذف"
          variant="danger"
          onConfirm={() => api.siteSettings.removeLogo()}
          onDone={() => {
            setDeletingLogo(false);
            onChanged();
          }}
          onClose={() => setDeletingLogo(false)}
        />
      )}
    </div>
  );
};
