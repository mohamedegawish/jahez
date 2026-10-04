import React from 'react';
import { useSearchParams } from 'react-router-dom';
import { AccountSettingsCard } from '../../components/settings/AccountSettingsCard';
import { Tabs } from '../../components/ui/Tabs';
import { FactoryProfile } from './FactoryProfile';

const TABS = [
  { id: 'profile', label: 'ملف المنشأة والتقييم' },
  { id: 'account', label: 'الحساب والإشعارات' },
];

/**
 * Factory settings: the profile and contact details, reviewed legal changes, the readiness assessment
 * status (the category comes only from the assessment, never from a manual choice), and the account.
 */
export const FactorySettings: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'account' ? 'account' : 'profile';
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الإعدادات</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">بيانات المنشأة والتواصل، والبيانات القانونية التي يراجعها المركز، وحالة تقييم الجاهزية، وإعدادات الحساب.</p>
      </div>
      <Tabs tabs={TABS} activeTab={tab} onChange={(id) => setParams({ tab: id }, { replace: true })} />
      {tab === 'profile' ? <FactoryProfile /> : <AccountSettingsCard />}
    </div>
  );
};
