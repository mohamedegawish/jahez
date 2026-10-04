import React, { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { Sidebar } from '../components/layout/Sidebar';
import { Header } from '../components/layout/Header';

/** Layout of the signed-in portals (IMC, factory, provider): sidebar and header. Public pages use PublicLayout. */
export const AppLayout: React.FC = () => {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  return (
    <div className="min-h-screen bg-[#F7F9FC] flex flex-row-reverse text-right" dir="rtl">
      {/* Sidebar (Right-anchored in RTL) */}
      <Sidebar
        isOpen={mobileSidebarOpen}
        onClose={() => setMobileSidebarOpen(false)}
        isCollapsed={sidebarCollapsed}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />

      {/* Main Content Area */}
      <div 
        className={`flex-1 flex flex-col min-w-0 transition-all duration-300 ${
          sidebarCollapsed ? 'lg:mr-20' : 'lg:mr-64'
        }`}
      >
        {/* Sticky Header */}
        <Header 
          onToggleSidebar={() => setMobileSidebarOpen(true)}
        />

        {/* Page Content Viewport */}
        <main className="flex-1 w-full p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
};
