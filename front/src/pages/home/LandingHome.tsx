import React from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Layers,
  Cpu,
  Database,
  Lock,
  Cloud,
  Compass,
  Zap,
  Gauge,
  ShieldCheck,
  Handshake,
  LineChart,
  UserPlus,
  Search,
  MessageSquareText,
  FileSignature,
  Rocket,
  Factory,
  Briefcase,
  LogIn,
} from 'lucide-react';
import { HeroSection } from '../../components/hero/HeroSection';
import { AdStrip } from '../../components/hero/AdStrip';
import { useAuth } from '../../auth/authContext';

export const LandingHome: React.FC = () => {
  const navigate = useNavigate();
  const { user, status } = useAuth();
  const isVisitor = !user && status !== 'loading';

  const categories = [
    { title: 'ERP & Applications', desc: 'تخطيط الموارد والإنتاج السحابي MRP', icon: Cpu },
    { title: 'OT & Automation', desc: 'ترقية لوحات التحكم والروبوتات الصناعية', icon: Layers },
    { title: 'Cloud & Infrastructure', desc: 'الحوسبة الطرفية Edge وربط الماكينات', icon: Cloud },
    { title: 'Cybersecurity for ICS/OT', desc: 'تحصين شبكات SCADA ومواصفة IEC 62443', icon: Lock },
    { title: 'AI, Data & Analytics', desc: 'الصيانة التنبؤية بالاهتزازات والرؤية الذكية', icon: Database },
    { title: 'Smart Manufacturing', desc: 'التوأم الرقمي Digital Twin ومؤشر OEE', icon: Zap },
    { title: 'Digital Consulting', desc: 'خارطة طريق التحول الرقمي واستشارات الجاهزية', icon: Compass },
  ];

  return (
    <div className="animate-in fade-in duration-300">
      {/* 1. Main Hero Section with ShaderGradient */}
      <HeroSection />

      {/* 1b. Ad strip — sits BELOW the hero (admin-managed) */}
      <AdStrip />

      <div className="px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-10 mt-10 pb-12">
        {/* 1c. Join the platform: the two registration paths and sign-in (visitors only) */}
        {isVisitor && (
          <section aria-labelledby="join-title" className="jahez-card p-6 sm:p-8">
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
              <div className="space-y-1.5 max-w-xl">
                <h2 id="join-title" className="text-lg sm:text-xl font-extrabold text-[#172033] tracking-tight">انضم إلى منظومة جاهز</h2>
                <p className="text-xs sm:text-sm text-[#667085] leading-relaxed">
                  سجّل منشأتك أو شركتك مباشرة. تصلك رسالة على بريدك الإلكتروني لتعيين كلمة المرور، ثم تكمل ملفك من بوابتك.
                  يظهر مزود الخدمة للمصانع بعد اعتماده من مركز تحديث الصناعة.
                </p>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:min-w-[520px]">
                <button
                  type="button"
                  onClick={() => navigate('/register/factory')}
                  className="flex flex-col items-start gap-2 p-4 rounded-2xl border border-[#DFF3FF] bg-[#DFF3FF]/40 hover:bg-[#DFF3FF] text-right transition-colors cursor-pointer"
                >
                  <Factory className="w-5 h-5 text-[#0A6EB0]" />
                  <span className="text-sm font-bold text-[#172033]">تسجيل منشأة صناعية</span>
                  <span className="text-[11px] text-[#667085]">ثم تقييم الجاهزية الرقمية</span>
                </button>
                <button
                  type="button"
                  onClick={() => navigate('/register/provider')}
                  className="flex flex-col items-start gap-2 p-4 rounded-2xl border border-[#EEEAFE] bg-[#EEEAFE]/40 hover:bg-[#EEEAFE] text-right transition-colors cursor-pointer"
                >
                  <Briefcase className="w-5 h-5 text-[#5146A5]" />
                  <span className="text-sm font-bold text-[#172033]">تسجيل مزود خدمة</span>
                  <span className="text-[11px] text-[#667085]">يراجعه مركز تحديث الصناعة</span>
                </button>
                <button
                  type="button"
                  onClick={() => navigate('/login')}
                  className="flex flex-col items-start gap-2 p-4 rounded-2xl border border-[#E6EAF0] bg-white hover:bg-[#F7F9FC] text-right transition-colors cursor-pointer"
                >
                  <LogIn className="w-5 h-5 text-[#172033]" />
                  <span className="text-sm font-bold text-[#172033]">لديك حساب؟</span>
                  <span className="text-[11px] text-[#667085]">تسجيل الدخول</span>
                </button>
              </div>
            </div>
          </section>
        )}

        {/* 2. Platform Value Proposition */}
        <div className="text-center space-y-2 max-w-2xl mx-auto">
          <h2 className="text-2xl sm:text-3xl font-extrabold text-[#172033] tracking-tight">
            منظومة متكاملة لتمكين الصناعة الوطنية
          </h2>
          <p className="text-xs sm:text-sm text-[#667085] leading-relaxed">
            حلول تكنولوجية موثوقة مصممة خصيصاً لمصانع الأغذية، النسيج، الكيماويات، والصناعات الهندسية.
          </p>
        </div>

        {/* 3. The 7 Service Categories - Interactive Cards with upward lift and color shift */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {categories.map((cat, idx) => {
            const Icon = cat.icon;
            return (
              <div
                key={idx}
                onClick={() => navigate('/factory/catalog')}
                className="jahez-card p-5 cursor-pointer text-right group flex flex-col justify-between"
              >
                <div>
                  <div className="w-10 h-10 rounded-xl bg-[#DFF3FF] text-[#0A6EB0] group-hover:bg-[#5146A5] group-hover:text-white flex items-center justify-center transition-colors mb-3">
                    <Icon className="w-5 h-5" />
                  </div>
                  <h3 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">
                    {cat.title}
                  </h3>
                  <p className="text-xs text-[#667085] mt-1.5 leading-relaxed">
                    {cat.desc}
                  </p>
                </div>

                <div className="mt-4 pt-3 border-t border-[#F1F4F9] flex items-center justify-between text-xs">
                  <span className="text-[#98A2B3]">تصنيف في الكتالوج</span>
                  <span className="text-[#5146A5] font-bold flex items-center gap-1 group-hover:-translate-x-1 transition-transform">
                    استعراض ←
                  </span>
                </div>
              </div>
            );
          })}

          {/* Bonus 8th Card: Digital Readiness Assessment Callout */}
          <div
            onClick={() => navigate('/factory/assessment')}
            className="jahez-card p-5 cursor-pointer text-right group flex flex-col justify-between"
          >
            <div>
              <div className="w-10 h-10 rounded-xl bg-[#DFF3FF] text-[#0A6EB0] group-hover:bg-[#5146A5] group-hover:text-white flex items-center justify-center transition-colors mb-3">
                <Gauge className="w-5 h-5" />
              </div>
              <h3 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors">
                تقييم الجاهزية الرقمية
              </h3>
              <p className="text-xs text-[#667085] mt-1.5 leading-relaxed">
                أجب عن استبيان الجاهزية الرقمية واحصل على فئة جاهزية مصنعك وخارطة طريق بخدمات مقترحة.
              </p>
            </div>

            <div className="mt-4 pt-3 border-t border-[#F1F4F9] flex items-center justify-between text-xs">
              <span className="text-[#98A2B3]">يحسبه الخادم فور الإرسال</span>
              <span className="text-[#5146A5] font-bold flex items-center gap-1 group-hover:-translate-x-1 transition-transform">
                ابدأ الاستبيان ←
              </span>
            </div>
          </div>
        </div>

        {/* 5. Platform Overview — About, Services & How to Use */}
        <div className="space-y-10">
          {/* 5a. About the platform */}
          <div className="jahez-card p-6 sm:p-8">
            <div className="flex items-center gap-2 mb-4">
              <span className="w-8 h-1 rounded-full bg-[#5146A5]" />
              <h2 className="text-lg sm:text-xl font-extrabold text-[#172033] tracking-tight">عن المنصة</h2>
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-3 text-xs sm:text-sm text-[#667085] leading-relaxed">
                <p>
                  <strong className="text-[#172033]">جاهز (JAHEZ)</strong> هي المنصة الوطنية الرقمية التي تربط المنشآت
                  الصناعية بمزودي حلول التحول الرقمي والأتمتة والذكاء الاصطناعي، ضمن منظومة موحّدة تديرها وتراقبها
                  <strong className="text-[#5146A5]"> مركز تحديث الصناعة (IMC)</strong> لضمان جودة الحلول وحماية حقوق
                  الطرفين.
                </p>
                <p>
                  تمنح المنصة المصانع طريقاً واضحاً للتحول من التصنيع التقليدي إلى المصنع الذكي (Industry 4.0) عبر تقييم
                  الجاهزية، اختيار الحلول المعتمدة، التفاوض والتعاقد الرقمي، ومتابعة التنفيذ والفواتير — كلها في مكان واحد
                  وبشفافية كاملة.
                </p>
                <div className="flex flex-wrap gap-2 pt-1">
                  {[
                    { icon: ShieldCheck, text: 'حوكمة رسمية من IMC' },
                    { icon: Handshake, text: 'اتفاقيات موثّقة بعد قبول العرض' },
                    { icon: LineChart, text: 'فواتير وسداد وفق السياسة المالية المعتمدة' },
                  ].map((item, i) => (
                    <span
                      key={i}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#EEEAFE] text-[#5146A5] text-[11px] font-bold"
                    >
                      <item.icon className="w-3.5 h-3.5" />
                      {item.text}
                    </span>
                  ))}
                </div>
              </div>

              {/* Quick facts sidebar */}
              <div className="grid grid-cols-2 lg:grid-cols-1 gap-3">
                {[
                  { value: '7', label: 'تصنيفات حلول صناعية معتمدة' },
                  { value: '4', label: 'قطاعات صناعية مستهدفة' },
                  { value: '3', label: 'بوابات: مصنع، مزود، إدارة' },
                ].map((fact, i) => (
                  <div key={i} className="rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] p-4 text-center">
                    <span className="text-2xl font-black text-[#5146A5] block">{fact.value}</span>
                    <p className="text-[11px] text-[#667085] font-semibold mt-1 leading-snug">{fact.label}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* 5b. Services offered by the platform */}
          <div>
            <div className="flex items-center gap-2 mb-4">
              <span className="w-8 h-1 rounded-full bg-[#6EC8FF]" />
              <h2 className="text-lg sm:text-xl font-extrabold text-[#172033] tracking-tight">خدمات المنصة</h2>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {[
                {
                  icon: Gauge,
                  title: 'تقييم الجاهزية الرقمية',
                  desc: 'استبيان بركائز متعددة يحدد فئة جاهزية مصنعك ويعرض خارطة طريق وخدمات مقترحة من الكتالوج.',
                  color: 'bg-[#DFF3FF] text-[#0A6EB0]',
                },
                {
                  icon: Compass,
                  title: 'كتالوج وسوق الحلول',
                  desc: 'تصفح خدمات الكتالوج المعتمدة الموزعة على 7 تصنيفات: ERP، الأتمتة، السحابة، الأمن الصناعي، الذكاء الاصطناعي والهندسة الرقمية.',
                  color: 'bg-[#E7F8EE] text-[#1D7E4C]',
                },
                {
                  icon: MessageSquareText,
                  title: 'تفاوض وإرسال عروض',
                  desc: 'مساحة تفاوض مباشرة بين المصنع والمزود مع رسائل وعروض بأسعار ومدد محددة داخل المنصة.',
                  color: 'bg-[#FFF3E2] text-[#B26A09]',
                },
                {
                  icon: FileSignature,
                  title: 'اتفاقيات وعقود ومدفوعات',
                  desc: 'اتفاقية موثّقة عند قبول العرض ومسودات عقود بمتطلبات نقل المعرفة، وفواتير ومدفوعات تُفعَّل وفق السياسة المالية المعتمدة.',
                  color: 'bg-[#EEEAFE] text-[#5146A5]',
                },
              ].map((svc, idx) => {
                const Icon = svc.icon;
                return (
                  <div key={idx} className="jahez-card p-5 text-right group hover:-translate-y-1 transition-transform">
                    <div className={`w-10 h-10 rounded-xl ${svc.color} flex items-center justify-center mb-3`}>
                      <Icon className="w-5 h-5" />
                    </div>
                    <h3 className="font-bold text-sm text-[#172033] mb-1.5">{svc.title}</h3>
                    <p className="text-xs text-[#667085] leading-relaxed">{svc.desc}</p>
                  </div>
                );
              })}
            </div>
          </div>

          {/* 5c. How to use — 4 steps */}
          <div className="jahez-card p-6 sm:p-8">
            <div className="flex items-center gap-2 mb-2">
              <span className="w-8 h-1 rounded-full bg-[#35B779]" />
              <h2 className="text-lg sm:text-xl font-extrabold text-[#172033] tracking-tight">كيفية الاستخدام</h2>
            </div>
            <p className="text-xs sm:text-sm text-[#667085] mb-6">
              أربع خطوات تفصلك من التسجيل حتى تشغيل أول مشروع تحول رقمي داخل مصنعك.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {[
                {
                  step: '1',
                  icon: UserPlus,
                  title: 'سجّل حسابك',
                  desc: 'سجّل منشأتك الصناعية أو شركتك كمزود خدمة، وستصلك رسالة لتعيين كلمة المرور والدخول إلى بوابتك.',
                  cta: 'ابدأ التسجيل',
                  onClick: () => navigate('/register/factory'),
                },
                {
                  step: '2',
                  icon: Gauge,
                  title: 'قيّم جاهزيتك',
                  desc: 'أكمل استبيان الجاهزية الرقمية واحصل على نتيجة محسوبة من الخادم بفئة جاهزية مصنعك.',
                  cta: 'ابدأ التقييم',
                  onClick: () => navigate('/factory/assessment'),
                },
                {
                  step: '3',
                  icon: Search,
                  title: 'اختر حلك المناسب',
                  desc: 'تصفح كتالوج الخدمات المعتمدة، قارن العروض، وأرسل طلبك للمزود المناسب.',
                  cta: 'افتح الكتالوج',
                  onClick: () => navigate('/factory/catalog'),
                },
                {
                  step: '4',
                  icon: Rocket,
                  title: 'تفاوض وتعاقد',
                  desc: 'تفاوض مع المزود، وثّق الاتفاق وأعدّ مسودة العقد، وتابع الفواتير من لوحتك.',
                  cta: 'سجّل الدخول',
                  onClick: () => navigate('/login'),
                },
              ].map((step, idx) => {
                const Icon = step.icon;
                return (
                  <div key={idx} className="relative rounded-2xl border border-[#E6EAF0] bg-[#F7F9FC] p-5 text-right">
                    <span className="absolute top-4 left-4 w-7 h-7 rounded-full bg-[#5146A5] text-white text-xs font-black flex items-center justify-center">
                      {step.step}
                    </span>
                    <div className="w-10 h-10 rounded-xl bg-white border border-[#E6EAF0] text-[#5146A5] flex items-center justify-center mb-3">
                      <Icon className="w-5 h-5" />
                    </div>
                    <h3 className="font-bold text-sm text-[#172033] mb-1.5">{step.title}</h3>
                    <p className="text-xs text-[#667085] leading-relaxed mb-3">{step.desc}</p>
                    <button
                      type="button"
                      onClick={step.onClick}
                      className="text-xs font-bold text-[#5146A5] hover:text-[#43388E] transition-colors cursor-pointer"
                    >
                      {step.cta} ←
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
