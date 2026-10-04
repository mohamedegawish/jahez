import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Image as ImageIcon, X } from 'lucide-react';
import { api, fieldMessages, isValidationError } from '../../api';
import type { AdminAnnouncement, AnnouncementColor, AnnouncementPayload } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { LOGO_ACCEPT, LOGO_MAX_KB } from '../../lib/files';
import { AdCard } from '../hero/AdCard';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';
import { colorOptions } from './colorOptions';

/** Paths of the web client an announcement may link to (the API refuses anything outside the platform). */
const LINK_SUGGESTIONS = ['/register/factory', '/register/provider', '/login'];

interface FormState {
  title: string;
  description: string;
  badge_text: string;
  color: AnnouncementColor;
  link_path: string;
  tags: string;
  countdown_text: string;
  cover_alt: string;
  sort_order: string;
  starts_at: string;
  ends_at: string;
}

/** ISO 8601 (UTC) → the value of a datetime-local input, in the browser's time zone. */
const toLocalInput = (iso: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
const fromLocalInput = (value: string): string | null => (value ? new Date(value).toISOString() : null);
const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

const initial = (a: AdminAnnouncement | null): FormState => ({
  title: a?.title ?? '',
  description: a?.description ?? '',
  badge_text: a?.badge_text ?? '',
  color: a?.color ?? 'blue',
  link_path: a?.link_path ?? '',
  tags: (a?.tags ?? []).join('، '),
  countdown_text: a?.countdown_text ?? '',
  cover_alt: a?.cover_alt ?? '',
  sort_order: String(a?.sort_order ?? 0),
  starts_at: toLocalInput(a?.starts_at ?? null),
  ends_at: toLocalInput(a?.ends_at ?? null),
});

const inputClass = 'w-full p-2.5 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF] bg-white';

/**
 * Create or edit a landing-page announcement (ADR-022). Saving stores a draft (or updates the
 * record); publishing is a separate action on the card. The cover image is uploaded after the text
 * is saved and replaces the previous one.
 */
export const AnnouncementFormModal: React.FC<{
  announcement: AdminAnnouncement | null;
  onClose: () => void;
  onSaved: () => void;
}> = ({ announcement, onClose, onSaved }) => {
  const [form, setForm] = useState<FormState>(() => initial(announcement));
  const [cover, setCover] = useState<File | null>(null);
  const [coverError, setCoverError] = useState<string | null>(null);
  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((prev) => ({ ...prev, [key]: value }));

  const payload = (): AnnouncementPayload => ({
    title: form.title.trim(),
    description: form.description.trim(),
    color: form.color,
    badge_text: orNull(form.badge_text),
    link_path: orNull(form.link_path),
    tags: form.tags.split(/[،,]/).map((t) => t.trim()).filter(Boolean),
    countdown_text: orNull(form.countdown_text),
    cover_alt: orNull(form.cover_alt),
    sort_order: Number.parseInt(form.sort_order, 10) || 0,
    starts_at: fromLocalInput(form.starts_at),
    ends_at: fromLocalInput(form.ends_at),
  });

  // A record created on an earlier attempt whose cover upload failed: retry by updating it, never
  // by creating a second one.
  const savedId = useRef<number | null>(announcement?.id ?? null);
  const save = useApiMutation(async () => {
    const saved = savedId.current !== null ? await api.announcements.update(savedId.current, payload()) : await api.announcements.create(payload());
    savedId.current = saved.id;
    if (cover) await api.announcements.uploadCover(saved.id, cover);
    return saved;
  });

  const previewUrl = useMemo(() => (cover ? URL.createObjectURL(cover) : null), [cover]);
  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  const errors = (field: string) => fieldMessages(save.error, field);
  const fileErrors = fieldMessages(save.error, 'file');

  const handleSubmit = async () => {
    const result = await save.run();
    if (result.ok) onSaved();
  };

  const pickCover = (file: File | null) => {
    setCoverError(null);
    if (file && file.size > LOGO_MAX_KB * 1024) {
      setCoverError(`حجم الصورة يتجاوز ${LOGO_MAX_KB} كيلوبايت.`);
      return;
    }
    setCover(file);
  };

  const preview = {
    id: announcement?.id ?? 0,
    title: form.title || 'عنوان الإعلان',
    description: form.description || 'وصف مختصر يظهر على البطاقة.',
    badge_text: orNull(form.badge_text),
    color: form.color,
    link_path: orNull(form.link_path),
    tags: payload().tags ?? [],
    countdown_text: orNull(form.countdown_text),
    cover_alt: orNull(form.cover_alt),
    cover_path: null,
    ends_at: null,
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={announcement ? 'تعديل الإعلان' : 'إعلان جديد'}
      subtitle={announcement && announcement.state !== 'draft' ? 'الإعلان منشور: يظهر التعديل للزوار فور الحفظ.' : 'يُحفظ مسودة لا يراها الزوار حتى تنشرها.'}
      maxWidth="4xl"
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={save.pending}>
            إلغاء
          </Button>
          <Button variant="primary" size="sm" onClick={handleSubmit} isLoading={save.pending} disabled={form.title.trim() === '' || form.description.trim() === ''}>
            حفظ
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-1 lg:grid-cols-5 gap-6 text-xs">
        <div className="lg:col-span-3 space-y-3">
          {save.error !== null && !isValidationError(save.error) && <ApiErrorState compact error={save.error} />}
          <Field label="العنوان (مطلوب)" errors={errors('title')}>
            <input className={inputClass} value={form.title} maxLength={120} onChange={(e) => set('title', e.target.value)} />
          </Field>
          <Field label="الوصف (مطلوب)" errors={errors('description')}>
            <textarea className={inputClass} rows={3} value={form.description} maxLength={600} onChange={(e) => set('description', e.target.value)} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="نص الشارة (مثل التاريخ)" errors={errors('badge_text')}>
              <input className={inputClass} value={form.badge_text} maxLength={40} onChange={(e) => set('badge_text', e.target.value)} />
            </Field>
            <Field label="نص المدة (اختياري)" errors={errors('countdown_text')}>
              <input className={inputClass} value={form.countdown_text} maxLength={60} onChange={(e) => set('countdown_text', e.target.value)} />
            </Field>
          </div>
          <Field label="الرابط داخل المنصة (يبدأ بـ /)" errors={errors('link_path')}>
            <input className={inputClass} dir="ltr" list="announcement-links" value={form.link_path} maxLength={255} onChange={(e) => set('link_path', e.target.value)} placeholder="/register/factory" />
            <datalist id="announcement-links">
              {LINK_SUGGESTIONS.map((path) => (
                <option key={path} value={path} />
              ))}
            </datalist>
          </Field>
          <Field label="الوسوم (مفصولة بفاصلة، 5 على الأكثر)" errors={[...errors('tags'), ...errors('tags.0')]}>
            <input className={inputClass} value={form.tags} onChange={(e) => set('tags', e.target.value)} />
          </Field>
          <div className="grid grid-cols-3 gap-3">
            <Field label="ترتيب العرض" errors={errors('sort_order')}>
              <input className={inputClass} type="number" min={0} max={1000} value={form.sort_order} onChange={(e) => set('sort_order', e.target.value)} dir="ltr" />
            </Field>
            <Field label="يبدأ الظهور" errors={errors('starts_at')}>
              <input className={inputClass} type="datetime-local" value={form.starts_at} onChange={(e) => set('starts_at', e.target.value)} dir="ltr" />
            </Field>
            <Field label="ينتهي الظهور" errors={errors('ends_at')}>
              <input className={inputClass} type="datetime-local" value={form.ends_at} onChange={(e) => set('ends_at', e.target.value)} dir="ltr" />
            </Field>
          </div>
          <div>
            <span className="font-bold text-[#172033] block mb-1">لون البطاقة</span>
            <div className="flex flex-wrap gap-2">
              {colorOptions.map((option) => (
                <button
                  key={option.key}
                  type="button"
                  onClick={() => set('color', option.key as AnnouncementColor)}
                  className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border cursor-pointer ${form.color === option.key ? 'border-[#5146A5] ring-2 ring-[#EEEAFE]' : 'border-[#E6EAF0]'}`}
                >
                  <span className="w-3 h-3 rounded-full" style={{ backgroundColor: option.hex }} />
                  {option.label}
                </button>
              ))}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3 items-end">
            <Field label="صورة الغلاف (jpg / png / webp)" errors={coverError ? [coverError, ...fileErrors] : fileErrors}>
              <label className="flex items-center gap-2 p-2.5 rounded-xl border border-dashed border-[#CCD5E2] cursor-pointer hover:bg-[#F7F9FC]">
                <ImageIcon className="w-4 h-4 text-[#5146A5]" />
                <span className="truncate">{cover ? cover.name : announcement?.cover_path ? 'استبدال الصورة الحالية' : 'اختيار صورة'}</span>
                <input type="file" accept={LOGO_ACCEPT} className="hidden" onChange={(e) => pickCover(e.target.files?.[0] ?? null)} />
              </label>
            </Field>
            {cover && (
              <Button variant="ghost" size="sm" icon={X} onClick={() => setCover(null)}>
                إلغاء الصورة المختارة
              </Button>
            )}
          </div>
          <Field label="وصف الصورة لقارئات الشاشة" errors={errors('cover_alt')}>
            <input className={inputClass} value={form.cover_alt} maxLength={200} onChange={(e) => set('cover_alt', e.target.value)} />
          </Field>
        </div>
        <div className="lg:col-span-2">
          <span className="font-bold text-[#172033] block mb-2">معاينة البطاقة</span>
          <div className="jahez-ad-preview flex justify-center pointer-events-none select-none">
            <AdCard ad={preview} coverSrc={previewUrl} />
          </div>
        </div>
      </div>
    </Modal>
  );
};

const Field: React.FC<{ label: string; errors: string[]; children: React.ReactNode }> = ({ label, errors, children }) => (
  <label className="block space-y-1">
    <span className="font-bold text-[#172033] block">{label}</span>
    {children}
    <FieldError messages={errors} />
  </label>
);
