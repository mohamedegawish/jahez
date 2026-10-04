import React from 'react';

interface TabItem {
  id: string;
  label: string;
  count?: number;
  icon?: React.ReactNode;
}

interface TabsProps {
  tabs: TabItem[];
  activeTab: string;
  onChange: (tabId: string) => void;
  className?: string;
}

export const Tabs: React.FC<TabsProps> = ({
  tabs,
  activeTab,
  onChange,
  className = ''
}) => {
  return (
    <div className={`flex items-center gap-1 border-b border-[#E6EAF0] overflow-x-auto no-scrollbar ${className}`}>
      {tabs.map((tab) => {
        const isActive = tab.id === activeTab;
        return (
          <button
            key={tab.id}
            onClick={() => onChange(tab.id)}
            className={`flex items-center gap-2 py-3 px-4 text-sm font-medium border-b-2 transition-all cursor-pointer whitespace-nowrap ${
              isActive
                ? 'border-[#5146A5] text-[#5146A5] font-semibold'
                : 'border-transparent text-[#667085] hover:text-[#172033] hover:border-[#CCD5E2]'
            }`}
          >
            {tab.icon && <span>{tab.icon}</span>}
            <span>{tab.label}</span>
            {tab.count !== undefined && (
              <span className={`text-xs px-2 py-0.5 rounded-full ${
                isActive ? 'bg-[#EEEAFE] text-[#5146A5]' : 'bg-[#F1F4F9] text-[#667085]'
              }`}>
                {tab.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
};
