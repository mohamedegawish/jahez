import React, { useState } from 'react';
import { AlertTriangle, Layers, Plus, Sparkles, Trash2 } from 'lucide-react';
import { api } from '../../api';
import type { ReadinessCategoryCode, ReadinessLevelAdmin, ReadinessLevelsOverview } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useCatalogServices } from '../../hooks/useReference';
import { LEVEL_LABEL } from '../../lib/roadmap';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';

const LEVEL_COLOR: Record<ReadinessCategoryCode, string> = {
  b4_automation: 'border-t-[#98A2B3]',
  basic: 'border-t-[#6EC8FF]',
  advanced: 'border-t-[#9B8AFB]',
  smart: 'border-t-[#35B779]',
};

/**
 * Which catalog services each readiness level makes available (jahez_api ADR-025). Factories see and
 * request only the services of their current level; a service may belong to several levels, and
 * removing it from a level never deletes it from the catalog. The roadmap-recommended services are
 * shown as hints only (owner decision): IMC adds each one explicitly. Every change is saved and
 * audited by the API at once.
 */
export const ReadinessLevelServices: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('readiness_services.manage');
  const overview = useApiQuery((signal) => api.readinessLevels.overview(signal), []);
  const [level, setLevel] = useState<ReadinessCategoryCode>('b4_automation');

  if (overview.status === 'error' && !overview.data) return <ApiErrorState error={overview.error} onRetry={overview.refetch} />;
  if (!overview.data) return <CardSkeleton />;

  const data = overview.data;
  const selected = data.levels.find((entry) => entry.code === level) ?? data.levels[0];

  return (
    <div className="space-y-5" data-testid="level-services">
      <p className="text-xs sm:text-sm text-[#667085] leading-relaxed">
        يرى المصنع ويطلب فقط الخدمات المتاحة لمستوى جاهزيته الحالي وللمستويات السابقة له، ومن مزودين معتمدين بقوائم معتمدة في قطاعاته؛ لذا تكفي إتاحة الخدمة لأدنى مستوى يحتاجها. عندما تسجّل الوزارة إتمام كل خدمات مستوى المصنع في خطة التحول الخاصة به يُفتح له المستوى التالي. لا يُضاف شيء تلقائيًا؛ الخدمات الموصى بها في خارطة الطريق تظهر كاقتراح فقط.
      </p>

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        {data.levels.map((entry) => {
          const active = entry.services.filter((service) => service.is_active);
          const warnings = active.filter((service) => service.approved_provider_count === 0).length;
          return (
            <button
              key={entry.code}
              type="button"
              onClick={() => setLevel(entry.code)}
              className={`jahez-card p-4 text-start border-t-4 ${LEVEL_COLOR[entry.code]} ${entry.code === selected.code ? 'ring-2 ring-[#9B8AFB]' : ''}`}
              data-testid="level-card"
              data-level={entry.code}
            >
              <p className="text-sm font-bold text-[#172033]">{entry.name_ar ?? LEVEL_LABEL[entry.code]}</p>
              <p className="text-[11px] text-[#667085]" dir="ltr">{entry.min_score}–{entry.max_score}</p>
              <p className="text-xs text-[#344054] mt-2">{active.length} خدمة متاحة{entry.services.length > active.length ? ` · ${entry.services.length - active.length} موقوفة` : ''}</p>
              {warnings > 0 && <p className="text-[11px] text-[#A66F0B] mt-1">⚠ {warnings} بلا مزود معتمد</p>}
            </button>
          );
        })}
      </div>

      <LevelPanel key={selected.code} level={selected} overview={data} canManage={canManage} onChanged={overview.refetch} />

      <Card title="ملخص الكتالوج" subtitle={`${data.summary.catalog_service_count} خدمة في الكتالوج`}>
        <div className="space-y-3 text-xs">
          <p>
            <span className="font-bold text-[#172033]">خدمات غير متاحة لأي مستوى ({data.summary.unassigned_services.length}): </span>
            <span className="text-[#667085]">{data.summary.unassigned_services.map((service) => service.name_ar).join('، ') || 'لا يوجد'}</span>
          </p>
          <p>
            <span className="font-bold text-[#172033]">خدمات متاحة لأكثر من مستوى: </span>
            <span className="text-[#667085]">{data.summary.multi_level_service_ids.length}</span>
          </p>
          <p>
            <span className="font-bold text-[#172033]">خدمات مفعّلة بلا مزود معتمد: </span>
            <span className={data.summary.without_provider_service_ids.length > 0 ? 'text-[#A66F0B]' : 'text-[#667085]'}>{data.summary.without_provider_service_ids.length}</span>
          </p>
        </div>
      </Card>
    </div>
  );
};

interface LevelPanelProps {
  level: ReadinessLevelAdmin;
  overview: ReadinessLevelsOverview;
  canManage: boolean;
  onChanged: () => void;
}

const LevelPanel: React.FC<LevelPanelProps> = ({ level, overview, canManage, onChanged }) => {
  const catalog = useCatalogServices();
  const [adding, setAdding] = useState('');
  const change = useApiMutation((run: () => Promise<unknown>) => run());
  const assigned = new Set(level.services.map((entry) => entry.service.id));
  const multiLevel = new Set(overview.summary.multi_level_service_ids);
  const recommendedMissing = (catalog.data ?? []).filter((service) => level.recommended_service_ids.includes(service.id) && !assigned.has(service.id));
  const addable = (catalog.data ?? []).filter((service) => !assigned.has(service.id));

  const act = async (run: () => Promise<unknown>) => {
    const result = await change.run(run);
    if (result.ok) onChanged();
  };

  return (
    <Card title={`الخدمات المتاحة: ${level.name_ar ?? LEVEL_LABEL[level.code]}`} subtitle={`${level.services.length} خدمة مرتبطة بهذا المستوى`} accent="purple">
      <div className="space-y-4">
        {change.error !== null && <ApiErrorState compact error={change.error} />}

        {level.services.length === 0 ? (
          <p className="text-xs text-[#667085] p-3 rounded-xl bg-[#F7F9FC]">لا توجد خدمات متاحة لهذا المستوى بعد؛ لن يرى مصنع في هذا المستوى أي خدمة.</p>
        ) : (
          <ul className="space-y-2" data-testid="level-service-list">
            {level.services.map((entry) => (
              <li key={entry.service.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-3 rounded-xl border border-[#E6EAF0] bg-white" data-service={entry.service.code}>
                <div className="min-w-0">
                  <p className={`text-sm font-semibold break-words ${entry.is_active ? 'text-[#172033]' : 'text-[#98A2B3] line-through'}`}>{entry.service.name_ar}</p>
                  <div className="flex flex-wrap items-center gap-1.5 mt-1">
                    {entry.service.category && <span className="text-[11px] text-[#667085]">{entry.service.category.name_ar}</span>}
                    <Badge variant={entry.approved_provider_count > 0 ? 'blue' : 'warning'} size="sm">
                      {entry.approved_provider_count > 0 ? `${entry.approved_provider_count} مزود معتمد` : 'لا يوجد مزود معتمد'}
                    </Badge>
                    {entry.is_active && entry.approved_provider_count === 0 && (
                      <span className="inline-flex items-center gap-1 text-[11px] text-[#A66F0B]" data-testid="no-provider-warning">
                        <AlertTriangle className="w-3 h-3" /> مفعّلة بلا مزود مؤهل
                      </span>
                    )}
                    {entry.recommended && <Badge variant="success" size="sm"><Sparkles className="w-3 h-3" /> موصى بها للمستوى</Badge>}
                    {multiLevel.has(entry.service.id) && <Badge variant="purple" size="sm"><Layers className="w-3 h-3" /> عدة مستويات</Badge>}
                    {!entry.is_active && <Badge variant="neutral" size="sm">موقوفة لهذا المستوى</Badge>}
                  </div>
                </div>
                {canManage && (
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      size="sm"
                      variant="outline"
                      isLoading={change.pending}
                      onClick={() => act(() => api.readinessLevels.set(level.code, entry.service.id, !entry.is_active))}
                      data-testid="toggle-level-service"
                    >
                      {entry.is_active ? 'إيقاف' : 'تفعيل'}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      icon={Trash2}
                      onClick={() => act(() => api.readinessLevels.remove(level.code, entry.service.id))}
                      aria-label="إزالة من المستوى"
                    />
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        {canManage && (
          <>
            <div className="flex flex-col sm:flex-row gap-2">
              <select
                value={adding}
                onChange={(e) => setAdding(e.target.value)}
                className="w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]"
                aria-label="خدمة من الكتالوج"
                data-testid="level-add-select"
              >
                <option value="">اختر خدمة من الكتالوج لإتاحتها لهذا المستوى…</option>
                {addable.map((service) => (
                  <option key={service.id} value={service.id}>{service.name_ar}</option>
                ))}
              </select>
              <Button
                size="sm"
                variant="primary"
                icon={Plus}
                disabled={adding === ''}
                isLoading={change.pending}
                onClick={async () => {
                  await act(() => api.readinessLevels.set(level.code, Number(adding), true));
                  setAdding('');
                }}
                data-testid="level-add"
              >
                إتاحة للمستوى
              </Button>
            </div>

            {recommendedMissing.length > 0 && (
              <div className="p-3 rounded-xl bg-[#E7F8EE]/50 border border-[#C5F0D5] space-y-2">
                <p className="text-xs font-bold text-[#1D7E4C]">اقتراحات من خارطة الطريق لهذا المستوى (لم تُضف بعد):</p>
                <div className="flex flex-wrap gap-2">
                  {recommendedMissing.map((service) => (
                    <button
                      key={service.id}
                      type="button"
                      onClick={() => act(() => api.readinessLevels.set(level.code, service.id, true))}
                      className="text-[11px] px-2.5 py-1 rounded-lg bg-white border border-[#C5F0D5] text-[#1D7E4C] hover:bg-[#E7F8EE]"
                    >
                      + {service.name_ar}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </Card>
  );
};
