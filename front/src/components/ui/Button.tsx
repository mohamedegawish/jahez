import React from 'react';
import { LucideIcon } from 'lucide-react';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'success';
  size?: 'sm' | 'md' | 'lg';
  icon?: LucideIcon;
  iconPosition?: 'left' | 'right';
  isLoading?: boolean;
}

export const Button: React.FC<ButtonProps> = ({
  children,
  variant = 'primary',
  size = 'md',
  icon: Icon,
  iconPosition = 'right',
  isLoading = false,
  className = '',
  disabled,
  ...props
}) => {
  const baseStyles = 'inline-flex items-center justify-center font-medium rounded-xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed select-none active:scale-[0.98] cursor-pointer';

  const sizeStyles = {
    sm: 'text-xs px-3 py-1.5 gap-1.5',
    md: 'text-sm px-4 py-2.5 gap-2',
    lg: 'text-base px-5 py-3 gap-2.5'
  };

  const variantStyles = {
    primary: 'bg-[#5146A5] hover:bg-[#43388E] text-white shadow-sm shadow-[#5146A5]/20 focus:ring-[#9B8AFB]',
    secondary: 'bg-[#EEEAFE] hover:bg-[#E3DCFD] text-[#5146A5] font-semibold focus:ring-[#9B8AFB]',
    outline: 'border border-[#E6EAF0] bg-white hover:bg-[#F7F9FC] text-[#172033] hover:border-[#CCD5E2] focus:ring-[#6EC8FF]',
    ghost: 'bg-transparent hover:bg-[#F1F4F9] text-[#667085] hover:text-[#172033] focus:ring-[#6EC8FF]',
    danger: 'bg-[#E45B6A] hover:bg-[#D34555] text-white shadow-sm shadow-[#E45B6A]/20 focus:ring-[#E45B6A]',
    success: 'bg-[#35B779] hover:bg-[#2D9E67] text-white shadow-sm shadow-[#35B779]/20 focus:ring-[#35B779]'
  };

  return (
    <button
      className={`${baseStyles} ${sizeStyles[size]} ${variantStyles[variant]} ${className}`}
      disabled={disabled || isLoading}
      {...props}
    >
      {isLoading ? (
        <span className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin" />
      ) : (
        <>
          {Icon && iconPosition === 'right' && <Icon className="w-4 h-4 shrink-0" />}
          <span>{children}</span>
          {Icon && iconPosition === 'left' && <Icon className="w-4 h-4 shrink-0" />}
        </>
      )}
    </button>
  );
};
