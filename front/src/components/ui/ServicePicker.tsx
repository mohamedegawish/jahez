import React, { useMemo, useState } from 'react';
import type { ServiceCategory } from '../../api';
import { useCatalogCategories } from '../../hooks/useReference';
import { ApiErrorState } from './ApiErrorState';
import { Skeleton } from './LoadingState';
import { SearchInput } from './SearchInput';

interface ServicePickerProps {
  /** Selected catalog service codes. */
  value: string[];
  onChange: (codes: string[]) => void;
  disabled?: boolean;
}

/**
 * Checkbox list of the fixed service catalog (7 categories, 42 services, ADR-014), grouped by
 * category. Search filters the bounded list in the browser; nothing here can add a service.
 */
export const ServicePicker: React.FC<ServicePickerProps> = (props) => {
  const categories = useCatalogCategories();

  if (categories.status === 'loading') return <Skeleton className="h-40 w-full" />;
  if (categories.status === 'error') return <ApiErrorState compact error={categories.error} />;

  return <ServiceChoice {...props} categories={categories.data ?? []} />;
};

/** The catalog checkboxes for categories the caller already has (the public registration options). */
export const ServiceChoice: React.FC<ServicePickerProps & { categories: ServiceCategory[] }> = ({ value, onChange, categories, disabled = false }) => {
  const [term, setTerm] = useState('');

  const selected = useMemo(() => new Set(value), [value]);

  const needle = term.trim().toLowerCase();
  const toggle = (code: string) =>
    onChange(selected.has(code) ? value.filter((c) => c !== code) : [...value, code]);

  return (
    <div className="space-y-3">
      <SearchInput value={term} onChange={setTerm} placeholder="ابحث في خدمات الكتالوج..." className="w-full" />
      <div className="space-y-4 max-h-[26rem] overflow-y-auto pr-1">
        {categories.map((category) => {
          const services = (category.services ?? []).filter(
            (service) => needle === '' || service.name_ar.toLowerCase().includes(needle) || service.code.toLowerCase().includes(needle),
          );
          if (services.length === 0) return null;
          const chosen = services.filter((service) => selected.has(service.code)).length;
          return (
            <fieldset key={category.code} className="space-y-1.5">
              <legend className="text-xs font-bold text-[#5146A5] flex items-center gap-2">
                {category.name_ar}
                <span className="text-[10px] font-semibold text-[#98A2B3]">
                  {chosen}/{services.length}
                </span>
              </legend>
              {services.map((service) => (
                <label
                  key={service.code}
                  className={`flex items-start gap-2.5 p-2.5 rounded-xl border text-xs cursor-pointer transition-colors ${
                    selected.has(service.code) ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                  } ${disabled ? 'opacity-60 cursor-not-allowed' : ''}`}
                >
                  <input
                    type="checkbox"
                    checked={selected.has(service.code)}
                    disabled={disabled}
                    data-service={service.code}
                    onChange={() => toggle(service.code)}
                    className="mt-0.5 accent-[#5146A5]"
                  />
                  <span className="flex-1 leading-relaxed text-[#172033] font-medium">{service.name_ar}</span>
                  <span className="font-mono text-[10px] text-[#98A2B3]" dir="ltr">{service.code}</span>
                </label>
              ))}
            </fieldset>
          );
        })}
      </div>
    </div>
  );
};
