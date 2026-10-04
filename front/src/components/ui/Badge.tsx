import React from 'react';
import { MaturityLevel } from '../../types';

interface BadgeProps {
  children: React.ReactNode;
  variant?: 'blue' | 'purple' | 'success' | 'warning' | 'error' | 'neutral';
  size?: 'sm' | 'md';
  className?: string;
}

export const Badge: React.FC<BadgeProps> = ({
  children,
  variant = 'blue',
  size = 'md',
  className = ''
}) => {
  const sizeStyles = {
    sm: 'text-[11px] px-2 py-0.5 font-medium rounded-md',
    md: 'text-xs px-2.5 py-1 font-medium rounded-lg'
  };

  const variantStyles = {
    blue: 'bg-[#DFF3FF] text-[#0A6EB0] border border-[#BDE5FD]',
    purple: 'bg-[#EEEAFE] text-[#5146A5] border border-[#DDD5FD]',
    success: 'bg-[#E7F8EE] text-[#1D7E4C] border border-[#C5F0D5]',
    warning: 'bg-[#FEF5E7] text-[#A66F0B] border border-[#FDE5BE]',
    error: 'bg-[#FDECEE] text-[#B82B3B] border border-[#F9C3C9]',
    neutral: 'bg-[#F1F4F9] text-[#475467] border border-[#E2E8F0]'
  };

  return (
    <span className={`inline-flex items-center gap-1.5 leading-none shrink-0 ${sizeStyles[size]} ${variantStyles[variant]} ${className}`}>
      {children}
    </span>
  );
};

export const StatusBadge: React.FC<{ status: string; size?: 'sm' | 'md' }> = ({ status, size = 'md' }) => {
  switch (status) {
    case 'Active':
    case 'Approved':
    case 'Paid':
    case 'Accepted':
    case 'Verified':
      return (
        <Badge variant="success" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#1D7E4C] animate-pulse" />
          {status === 'Active' ? 'نشط' : 
           status === 'Approved' ? 'معتمد' : 
           status === 'Paid' ? 'مدفوع' : 
           status === 'Accepted' ? 'مقبول' : 'موثق'}
        </Badge>
      );
    case 'Pending':
    case 'Under Review':
    case 'Proposal Sent':
    case 'Draft':
      return (
        <Badge variant="warning" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#A66F0B]" />
          {status === 'Pending' ? 'قيد الانتظار' : 
           status === 'Under Review' ? 'قيد المراجعة' : 
           status === 'Proposal Sent' ? 'تم إرسال العرض' : 'مسودة'}
        </Badge>
      );
    case 'Suspended':
    case 'Rejected':
    case 'Cancelled':
    case 'Overdue':
      return (
        <Badge variant="error" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#B82B3B]" />
          {status === 'Suspended' ? 'موقوف' : 
           status === 'Rejected' ? 'مرفوض' : 
           status === 'Overdue' ? 'متأخر' : 'ملغي'}
        </Badge>
      );
    case 'Negotiation':
      return (
        <Badge variant="purple" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#5146A5]" />
          مرحلة التفاوض
        </Badge>
      );
    case 'New':
      return (
        <Badge variant="blue" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#0A6EB0]" />
          جديد
        </Badge>
      );
    case 'Expiring Soon':
      return (
        <Badge variant="warning" size={size}>
          <span className="w-1.5 h-1.5 rounded-full bg-[#F2B84B]" />
          ينتهي قريباً
        </Badge>
      );
    default:
      return <Badge variant="neutral" size={size}>{status}</Badge>;
  }
};

export const MaturityBadge: React.FC<{ level: MaturityLevel; size?: 'sm' | 'md' }> = ({ level, size = 'md' }) => {
  switch (level) {
    case 'Foundational':
      return <Badge variant="neutral" size={size}>مستوى تأسيسي (Foundational)</Badge>;
    case 'Basic':
      return <Badge variant="blue" size={size}>تحول أولي (Basic)</Badge>;
    case 'Advanced':
      return <Badge variant="purple" size={size}>تحول متقدم (Advanced)</Badge>;
    case 'Smart':
      return <Badge variant="success" size={size}>مصنع ذكي (Smart 4.0)</Badge>;
  }
};
