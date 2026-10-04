import React from 'react';
import { useAuth } from '../../auth/authContext';
import { useListParams } from '../../hooks/useListParams';
import { LevelsTab } from '../../components/readiness-admin/LevelsTab';
import { OverviewTab } from '../../components/readiness-admin/OverviewTab';
import { PillarsTab } from '../../components/readiness-admin/PillarsTab';
import { QuestionsTab } from '../../components/readiness-admin/QuestionsTab';
import { ResultsTab } from '../../components/readiness-admin/ResultsTab';
import { RESULT_FILTER_KEYS } from '../../components/readiness-admin/shared';
import { RoadmapTab } from '../../components/readiness-admin/RoadmapTab';
import { useReadinessWorkspace } from '../../components/readiness-admin/useReadinessWorkspace';
import { VersionsTab } from '../../components/readiness-admin/VersionsTab';
import { Tabs } from '../../components/ui/Tabs';

const FILTER_KEYS = ['tab', ...RESULT_FILTER_KEYS] as const;

const TABS = [
  { id: 'overview', label: 'نظرة عامة' },
  { id: 'pillars', label: 'محاور التقييم' },
  { id: 'questions', label: 'الأسئلة والاختيارات' },
  { id: 'levels', label: 'مستويات الجاهزية' },
  { id: 'roadmap', label: 'التوصيات وخارطة الطريق' },
  { id: 'results', label: 'نتائج تقييم المصانع' },
  { id: 'versions', label: 'سجل الإصدارات والإحصائيات' },
];

/**
 * «إدارة تقييم الجاهزية الرقمية» (jahez_api ADR-018 and its addenda, ADR-021): analytics and results from
 * the stored assessments, and the questionnaire editor. A published version never changes; changes are
 * made in a draft, reviewed and published as a new version, and earlier results keep their version,
 * answers, score and level. The API enforces every rule and permission shown here.
 */
export const ReadinessAdministration: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('readiness_questionnaires.manage');
  const list = useListParams(FILTER_KEYS);
  const tab = TABS.some((item) => item.id === list.filters.tab) ? list.filters.tab : 'overview';
  const workspace = useReadinessWorkspace(canManage);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">إدارة تقييم الجاهزية الرقمية</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5 max-w-3xl leading-relaxed">
          خمسة محاور وعشرة أسئلة بأربعة اختيارات (1–4 نقاط) من وثيقة «إطار تقييم مستوى الجاهزية الرقمية». يجمع الخادم نقاط الإجابات (10–40) ويحدد
          المستوى من حدود الإصدار الذي أُجيب عنه. مستوى الجاهزية منفصل عن اعتماد المنشأة.
        </p>
      </div>

      <div className="overflow-x-auto -mx-1 px-1">
        <Tabs tabs={TABS} activeTab={tab} onChange={(id) => list.setFilter('tab', id === 'overview' ? '' : id)} />
      </div>

      {tab === 'overview' && (
        <OverviewTab
          from={list.filters.from}
          to={list.filters.to}
          setFrom={(value) => list.setFilter('from', value)}
          setTo={(value) => list.setFilter('to', value)}
          onOpenResults={() => list.setFilter('tab', 'results')}
        />
      )}
      {tab === 'pillars' && <PillarsTab workspace={workspace} canManage={canManage} />}
      {tab === 'questions' && <QuestionsTab workspace={workspace} canManage={canManage} />}
      {tab === 'levels' && <LevelsTab workspace={workspace} canManage={canManage} />}
      {tab === 'roadmap' && <RoadmapTab workspace={workspace} canManage={canManage} />}
      {tab === 'results' && <ResultsTab list={list} />}
      {tab === 'versions' && <VersionsTab workspace={workspace} canManage={canManage} />}
    </div>
  );
};
