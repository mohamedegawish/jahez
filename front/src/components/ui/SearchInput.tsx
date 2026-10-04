import React from 'react';
import { Search, X } from 'lucide-react';

interface SearchInputProps {
  value: string;
  onChange: (val: string) => void;
  placeholder?: string;
  className?: string;
}

export const SearchInput: React.FC<SearchInputProps> = ({
  value,
  onChange,
  placeholder = 'بحث...',
  className = ''
}) => {
  return (
    <div className={`relative flex items-center ${className}`}>
      <Search className="w-4 h-4 text-[#667085] absolute right-3 pointer-events-none" />
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full pr-9 pl-8 py-2 text-sm bg-white border border-[#E6EAF0] rounded-xl text-[#172033] placeholder:text-[#98A2B3] focus:outline-none focus:border-[#6EC8FF] focus:ring-2 focus:ring-[#DFF3FF] transition-all"
      />
      {value && (
        <button
          onClick={() => onChange('')}
          className="absolute left-2.5 text-[#98A2B3] hover:text-[#172033] p-1 rounded-md cursor-pointer"
        >
          <X className="w-3.5 h-3.5" />
        </button>
      )}
    </div>
  );
};
