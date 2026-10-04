import React from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Factory as FactoryIcon, Gauge, Percent, TriangleAlert, Users } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { Card, KPICard } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReadinessBadge } from '../ui/ReadinessBadge';
import { CATEGORY_COLORS, selectClass } from './shared';

/**
 * «نظرة عامة»: analytics the server computes from the stored assessments (ADR-021) and the latest
 * submissions. Nothing here is calculated from a partial list in the browser.
 */
export const OverviewTab: React.FC<{ from: string; to: string; setFrom: (value: string) => void; setTo: (value: string) => void; onOpenResults: () => void }> = ({
  from,
  to,
  setFrom,
  setTo,
  onOpenResults,
}) => {
  const analytics = useApiQuery((signal) => api.readiness.analytics({ from: from || undefined, to: to || undefined, signal }), [from, to]);
  const latest = useApiQuery((signal) => api.readiness.listAll({ per_page: 6, sort: 'newest', signal }), []);

  return (
    <QueryBoundary query={analytics} loading={<CardSkeleton />}>
      {(d) => {
        const assessedTotal = d.current.by_category.reduce((n, c) => n + c.factories, 0);
        const def = d.definition;
        const structureOk = def !== null && def.problems.length === 0;
        return (
          <div className="space-y-6" data-testid="readiness-analytics">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <KPICard title="المنشآت المسجلة" value={d.factories_total} icon={FactoryIcon} accentColor="blue" description="كل المنشآت في المنظومة" />
              <KPICard title="أكملت التقييم" value={d.factories_assessed} icon={CheckCircle2} accentColor="success" description={`${d.assessments_total} تقييمًا مُسجَّلًا إجمالًا`} />
              <KPICard title="لم تُكمل التقييم" value={d.factories_not_assessed} icon={Users} accentColor="purple" description="لا يُحفظ تقييم جزئي" />
              <KPICard
                title="نسبة الإكمال"
                value={d.completion_rate_percent === null ? '—' : `${d.completion_rate_percent}%`}
                icon={Percent}
                accentColor="indigo"
                description="أكملت ÷ كل المنشآت المسجلة"
              />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <Card title="توزيع المنشآت على المستويات" subtitle="آخر تقييم لكل منشأة (مستواها الحالي)" accent="purple" className="lg:col-span-2">
                <div className="space-y-3" data-testid="category-distribution">
                  {d.current.by_category.map((category) => (
                    <div key={category.code} className="flex flex-wrap sm:flex-nowrap items-center gap-x-3 gap-y-1 text-xs">
                      <span className="w-40 shrink-0">
                        <ReadinessBadge category={category} size="sm" />
                      </span>
                      <span className="text-[10px] text-[#98A2B3] w-12 shrink-0" dir="ltr">
                        {category.min_score}–{category.max_score}
                      </span>
                      <div className="flex-1 min-w-24 bg-[#F1F4F9] h-2.5 rounded-full overflow-hidden">
                        <div
                          className="h-full rounded-full"
                          style={{ width: `${assessedTotal ? (category.factories * 100) / assessedTotal : 0}%`, backgroundColor: CATEGORY_COLORS[category.code] ?? '#6EC8FF' }}
                        />
                      </div>
                      <span className="font-bold text-[#172033] w-8 text-left">{category.factories}</span>
                      <span className="text-[10px] text-[#98A2B3] w-20">متوسط {category.average_score ?? '—'}</span>
                    </div>
                  ))}
                </div>
                <div className="mt-4 pt-3 border-t border-[#F1F4F9] text-xs text-[#667085] flex flex-wrap gap-4">
                  <span>
                    متوسط الدرجة الحالية: <strong className="text-[#172033]">{d.current.average_score ?? '—'}</strong>
                    {def ? ` من ${def.score_range.max}` : ''}
                  </span>
                  <span>محسوب من التقييمات المحفوظة فقط.</span>
                </div>
              </Card>

              <Card title="بنية الاستبيان الحالي" subtitle="فحص الخادم للإصدار الذي تجيب عنه المصانع" accent={structureOk ? 'blue' : 'none'}>
                {def === null ? (
                  <p className="text-xs text-[#B82B3B]">لا يوجد إصدار حالي للاستبيان.</p>
                ) : (
                  <div className="space-y-2 text-xs" data-testid="definition-check">
                    <Row label="الإصدار" value={String(def.version)} />
                    <Row label="المحاور" value={String(def.pillars)} />
                    <Row label="الأسئلة" value={String(def.questions)} />
                    <Row label="الاختيارات" value={String(def.choices)} />
                    <Row label="أدنى وأعلى مجموع ممكن" value={`${def.score_range.min}–${def.score_range.max}`} />
                    <div className={`mt-3 p-2.5 rounded-lg flex items-start gap-2 ${structureOk ? 'bg-[#E7F8EE] text-[#1D7E4C]' : 'bg-[#FDECEE] text-[#B82B3B]'}`}>
                      {structureOk ? <CheckCircle2 className="w-4 h-4 shrink-0" /> : <TriangleAlert className="w-4 h-4 shrink-0" />}
                      <span>{structureOk ? 'البنية مطابقة لوثيقة الإطار، وحدود المستويات تغطي كل مجموع ممكن بلا فجوات أو تداخل.' : def.problems.join(' ')}</span>
                    </div>
                  </div>
                )}
              </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <Card
                title="التقييمات المُسلَّمة عبر الزمن"
                subtitle={`من ${d.period.from} إلى ${d.period.to} (UTC) — ${d.period.submissions} تقييمًا، متوسط ${d.period.average_score ?? '—'}`}
                className="lg:col-span-2"
                action={
                  <div className="flex items-center gap-2">
                    <input type="date" aria-label="من تاريخ" value={from} onChange={(e) => setFrom(e.target.value)} className={selectClass} dir="ltr" />
                    <input type="date" aria-label="إلى تاريخ" value={to} onChange={(e) => setTo(e.target.value)} className={selectClass} dir="ltr" />
                  </div>
                }
              >
                {d.period.by_month.length === 0 ? (
                  <p className="text-xs text-[#667085] text-center py-8">لا توجد تقييمات في هذه الفترة.</p>
                ) : (
                  <div className="h-60" dir="ltr">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart data={d.period.by_month} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E6EAF0" />
                        <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#667085' }} />
                        <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#667085' }} />
                        <Tooltip formatter={(val: unknown) => [`${val}`, 'تقييمات']} contentStyle={{ direction: 'rtl', borderRadius: '8px', fontSize: '12px' }} />
                        <Bar dataKey="submissions" fill="#9B8AFB" radius={[6, 6, 0, 0]} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                )}
                {d.period.by_version.length > 0 && (
                  <div className="mt-3 pt-3 border-t border-[#F1F4F9] flex flex-wrap gap-3 text-xs text-[#667085]">
                    <Gauge className="w-4 h-4 text-[#5146A5]" />
                    {d.period.by_version.map((v) => (
                      <span key={v.version}>
                        الإصدار {v.version}: <strong className="text-[#172033]">{v.submissions}</strong>
                      </span>
                    ))}
                  </div>
                )}
              </Card>

              <Card
                title="أحدث التقييمات"
                subtitle="من كل المنشآت"
                action={
                  <button type="button" onClick={onOpenResults} className="text-xs font-bold text-[#5146A5] hover:underline cursor-pointer">
                    كل النتائج
                  </button>
                }
              >
                <QueryBoundary query={latest} loading={<CardSkeleton />} isEmpty={(page) => page.data.length === 0} empty={<p className="text-xs text-[#667085] text-center py-6">لا توجد تقييمات بعد.</p>}>
                  {(page) => (
                    <ul className="divide-y divide-[#F1F4F9]" data-testid="latest-assessments">
                      {page.data.map((assessment) => (
                        <li key={assessment.id} className="py-2.5 flex items-center justify-between gap-2 text-xs">
                          <div className="min-w-0">
                            <Link to={`/admin/approvals/factories/${assessment.factory_id}`} className="font-bold text-[#172033] hover:text-[#5146A5] truncate block">
                              {assessment.factory?.name ?? `#${assessment.factory_id}`}
                            </Link>
                            <span className="text-[#98A2B3]">{formatDateTime(assessment.completed_at)} · الإصدار {assessment.questionnaire_version}</span>
                          </div>
                          <div className="flex items-center gap-2 shrink-0">
                            <span className="font-extrabold text-[#5146A5]">{assessment.total_score}</span>
                            <ReadinessBadge category={assessment.category} size="sm" />
                          </div>
                        </li>
                      ))}
                    </ul>
                  )}
                </QueryBoundary>
              </Card>
            </div>
          </div>
        );
      }}
    </QueryBoundary>
  );
};

const Row: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div className="flex items-center justify-between">
    <span className="text-[#667085]">{label}</span>
    <span className="font-bold text-[#172033]" dir="ltr">
      {value}
    </span>
  </div>
);
