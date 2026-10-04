import React from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ShieldCheck,
  Building2,
  Briefcase,
  ArrowLeft,
  Compass,
  UserPlus,
  Layers,
  FileCheck,
} from 'lucide-react';
import { JahezShaderCanvas } from './JahezShaderCanvas';
import { Button } from '../ui/Button';
import { useAuth } from '../../auth/authContext';
import type { Role } from '../../api';

export const HeroSection: React.FC = () => {
  const navigate = useNavigate();
  const { user, status } = useAuth();
  // While a stored session is being verified the viewer is neither visitor nor user: show neither set.
  const resolving = status === 'loading';
  const isVisitor = !user && !resolving;
  // A signed-in user has exactly one portal (their server-side role); visitors see all three.
  const showPortal = (role: Role) => !resolving && (!user || user.role === role);

  return (
    <JahezShaderCanvas className="w-full shadow-lg min-h-[360px] sm:min-h-[420px] flex items-center justify-center p-5 sm:p-8 lg:p-10">
      {/* Background Soft Glow Accents */}
      <div className="absolute top-1/4 right-1/4 w-80 h-80 bg-[#6EC8FF]/20 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute bottom-10 left-1/4 w-72 h-72 bg-[#9B8AFB]/25 rounded-full blur-3xl pointer-events-none" />

      {/* Main Centered Content */}
      <div className="max-w-6xl mx-auto text-center space-y-4 relative z-10">
        {/* Subtle pill tag */}
        <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/85 backdrop-blur-md border border-[#E6EAF0] text-xs font-bold text-[#5146A5] shadow-xs">
          <span>المنظومة الوطنية الذكية للثورة الصناعية الرابعة | Industry 4.0</span>
        </div>

        {/* Hero Title: JAHEZ and under it جاهز */}
        <div className="space-y-1">
          <h1 className="text-5xl sm:text-6xl lg:text-7xl font-black tracking-tight text-[#172033] font-sans drop-shadow-xs">
            JAHEZ
          </h1>
          <div className="text-3xl sm:text-4xl lg:text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-[#5146A5] via-[#43388E] to-[#6EC8FF] tracking-wide">
            جاهز
          </div>
        </div>

        {/* Subtitle */}
        <p className="text-sm sm:text-base lg:text-lg text-[#344054] max-w-2xl mx-auto font-medium leading-relaxed">
          المنصة الوطنية لربط المنشآت الصناعية بمزودي حلول الأتمتة والذكاء الاصطناعي، 
          بإشراف وحوكمة معتمدة من <strong className="text-[#5146A5]">مركز تحديث الصناعة (IMC)</strong>.
        </p>

        {/* Condensed platform facts strip */}
        <div className="flex flex-wrap items-center justify-center gap-2.5 pt-1">
          {[
            { icon: ShieldCheck, label: 'حوكمة IMC المعتمدة', color: 'text-[#1D7E4C]' },
            { icon: Layers, label: 'كتالوج معتمد في 7 تصنيفات', color: 'text-[#0A6EB0]' },
            { icon: FileCheck, label: 'تقييم الجاهزية الرقمية للمصنع', color: 'text-[#5146A5]' },
          ].map((fact, i) => (
            <span
              key={i}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/85 backdrop-blur-md border border-[#E6EAF0] text-[11px] font-bold text-[#344054] shadow-xs"
            >
              <fact.icon className={`w-3.5 h-3.5 ${fact.color}`} />
              {fact.label}
            </span>
          ))}
        </div>

        {/* Quick Entrance Portal Cards - "اي card يعمل لون ويظهر لاعلي عند لمسه" */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 pt-2 max-w-6xl mx-auto text-right">
          {/* Card 1: Factory Portal */}
          {showPortal('factory_member') && <div 
            onClick={() => navigate('/factory/dashboard')}
            className="jahez-card p-4.5 cursor-pointer text-right group"
          >
            <div className="flex items-center justify-between mb-2">
              <div className="w-10 h-10 rounded-xl bg-[#DFF3FF] text-[#0A6EB0] flex items-center justify-center group-hover:bg-[#6EC8FF] group-hover:text-white transition-colors">
                <Building2 className="w-5 h-5" />
              </div>
              <ArrowLeft className="w-4 h-4 text-[#98A2B3] group-hover:text-[#5146A5] group-hover:-translate-x-1 transition-all" />
            </div>
            <h4 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">بوابة المنشآت والمصانع</h4>
            <p className="text-[11px] text-[#667085] mt-1 leading-relaxed">
              تقييم الجدارة الرقمية، اكتشاف الحلول، وطلب خدمات التحول الذكي.
            </p>
          </div>}

          {/* Card 2: Provider Portal */}
          {showPortal('provider_member') && <div 
            onClick={() => navigate('/provider/dashboard')}
            className="jahez-card p-4.5 cursor-pointer text-right group"
          >
            <div className="flex items-center justify-between mb-2">
              <div className="w-10 h-10 rounded-xl bg-[#EEEAFE] text-[#5146A5] flex items-center justify-center group-hover:bg-[#9B8AFB] group-hover:text-white transition-colors">
                <Briefcase className="w-5 h-5" />
              </div>
              <ArrowLeft className="w-4 h-4 text-[#98A2B3] group-hover:text-[#5146A5] group-hover:-translate-x-1 transition-all" />
            </div>
            <h4 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">بوابة مزودي الخدمات</h4>
            <p className="text-[11px] text-[#667085] mt-1 leading-relaxed">
              طرح الحلول الصناعية، استقبال طلبات المصانع، ومساحة التفاوض.
            </p>
          </div>}

          {/* Card 3: Admin IMC Portal */}
          {showPortal('imc_admin') && <div 
            onClick={() => navigate('/admin/dashboard')}
            className="jahez-card p-4.5 cursor-pointer text-right group"
          >
            <div className="flex items-center justify-between mb-2">
              <div className="w-10 h-10 rounded-xl bg-[#E7F8EE] text-[#1D7E4C] flex items-center justify-center group-hover:bg-[#35B779] group-hover:text-white transition-colors">
                <ShieldCheck className="w-5 h-5" />
              </div>
              <ArrowLeft className="w-4 h-4 text-[#98A2B3] group-hover:text-[#5146A5] group-hover:-translate-x-1 transition-all" />
            </div>
            <h4 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">إدارة وحوكمة المنظومة (IMC)</h4>
            <p className="text-[11px] text-[#667085] mt-1 leading-relaxed">
              اعتماد المزودين بالمعايير الخمسة، توثيق العقود، والرقابة المالية.
            </p>
          </div>}

          {/* Card 4: Registration / Join the platform (visitors only) */}
          {isVisitor && <div className="jahez-card p-4.5 text-right group">
            <div className="flex items-center justify-between mb-2">
              <div className="w-10 h-10 rounded-xl bg-[#FFF3E2] text-[#B26A09] flex items-center justify-center group-hover:bg-[#F2B84B] group-hover:text-white transition-colors">
                <UserPlus className="w-5 h-5" />
              </div>
            </div>
            <h4 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">التسجيل والانضمام</h4>
            <p className="text-[11px] text-[#667085] mt-1 leading-relaxed">
              اشترك في المنصة كمنشأة صناعية أو كمزود خدمة معتمد.
            </p>
            <div className="flex gap-2 mt-2.5">
              <button
                type="button"
                onClick={() => navigate('/register/factory')}
                className="flex-1 py-1.5 rounded-lg bg-[#DFF3FF] text-[#0A6EB0] text-[11px] font-bold hover:bg-[#6EC8FF] hover:text-white transition-colors cursor-pointer"
              >
                تسجيل منشأة
              </button>
              <button
                type="button"
                onClick={() => navigate('/register/provider')}
                className="flex-1 py-1.5 rounded-lg bg-[#EEEAFE] text-[#5146A5] text-[11px] font-bold hover:bg-[#9B8AFB] hover:text-white transition-colors cursor-pointer"
              >
                تسجيل مزود
              </button>
            </div>
          </div>}
        </div>

        {/* Action Buttons */}
        <div className="pt-2 flex flex-wrap items-center justify-center gap-3">
          <Button
            size="lg"
            variant="primary"
            icon={Compass}
            onClick={() => navigate('/factory/catalog')}
            className="font-bold shadow-md shadow-[#5146A5]/25"
          >
            تصفح كتالوج وسوق الخدمات
          </Button>

          <Button
            size="lg"
            variant="outline"
            onClick={() => navigate('/factory/assessment')}
            className="bg-white/90 backdrop-blur-xs font-bold text-[#5146A5] hover:bg-white"
          >
            بدء تقييم الجدارة الرقمية للمصنع
          </Button>

          {isVisitor && (
            <Button
              size="lg"
              variant="secondary"
              onClick={() => navigate('/login')}
              className="font-bold"
            >
              تسجيل الدخول
            </Button>
          )}
        </div>
      </div>
    </JahezShaderCanvas>
  );
};
