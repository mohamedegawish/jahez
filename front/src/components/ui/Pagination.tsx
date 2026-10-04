import React from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { PageMeta } from '../../api/types';

const btn =
  'min-w-8 h-8 px-2 inline-flex items-center justify-center rounded-lg text-xs font-bold border transition-colors cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed';

/** Page numbers to show: first, last, and the current page with one neighbour, with `null` as a gap. */
function windowOf(current: number, last: number): (number | null)[] {
  const pages = new Set<number>([1, last, current - 1, current, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out: (number | null)[] = [];
  sorted.forEach((p, i) => {
    if (i > 0 && p - sorted[i - 1] > 1) out.push(null);
    out.push(p);
  });
  return out;
}

interface PaginationProps {
  /** `meta` from a length-aware list response (`Paginated<T>`). */
  meta: PageMeta;
  onPage: (page: number) => void;
  /** When given, a page-size selector is shown (the API accepts 1 to 100 per page). */
  onPerPage?: (perPage: number) => void;
  className?: string;
}

const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

/** Driven by the API's own pagination metadata (`current_page`, `last_page`, `from`, `to`, `total`). */
export const Pagination: React.FC<PaginationProps> = ({ meta, onPage, onPerPage, className = '' }) => {
  if (meta.total === 0) return null;
  const { current_page: current, last_page: last } = meta;

  return (
    <div className={`flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-[#667085] ${className}`}>
      <div className="flex items-center gap-3">
        <span>
          عرض {meta.from ?? 0}–{meta.to ?? 0} من {meta.total}
        </span>
        {onPerPage && (
          <label className="flex items-center gap-1.5">
            <span>الصفوف:</span>
            <select
              aria-label="عدد الصفوف في الصفحة"
              value={meta.per_page}
              onChange={(e) => onPerPage(Number(e.target.value))}
              className="py-1 px-2 rounded-lg border border-[#E6EAF0] bg-white text-[#172033]"
            >
              {[...new Set([...PER_PAGE_OPTIONS, meta.per_page])].sort((a, b) => a - b).map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      {last > 1 && (
        <nav className="flex items-center gap-1" aria-label="التنقل بين الصفحات">
          <button
            type="button"
            className={`${btn} bg-white border-[#E6EAF0] text-[#172033] hover:bg-[#F7F9FC]`}
            disabled={current <= 1}
            onClick={() => onPage(current - 1)}
            aria-label="الصفحة السابقة"
          >
            <ChevronRight className="w-4 h-4" />
          </button>
          {windowOf(current, last).map((p, i) =>
            p === null ? (
              <span key={`gap-${i}`} className="px-1">…</span>
            ) : (
              <button
                key={p}
                type="button"
                onClick={() => onPage(p)}
                aria-current={p === current ? 'page' : undefined}
                className={`${btn} ${
                  p === current
                    ? 'bg-[#5146A5] border-[#5146A5] text-white'
                    : 'bg-white border-[#E6EAF0] text-[#172033] hover:bg-[#F7F9FC]'
                }`}
              >
                {p}
              </button>
            ),
          )}
          <button
            type="button"
            className={`${btn} bg-white border-[#E6EAF0] text-[#172033] hover:bg-[#F7F9FC]`}
            disabled={current >= last}
            onClick={() => onPage(current + 1)}
            aria-label="الصفحة التالية"
          >
            <ChevronLeft className="w-4 h-4" />
          </button>
        </nav>
      )}
    </div>
  );
};

interface CursorPaginationProps {
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  className?: string;
}

/** For cursor-paginated lists (the audit log): the API gives only a next cursor, no totals. */
export const CursorPagination: React.FC<CursorPaginationProps> = ({ hasPrev, hasNext, onPrev, onNext, className = '' }) => (
  <div className={`flex items-center justify-center gap-2 ${className}`}>
    <button type="button" className={`${btn} bg-white border-[#E6EAF0] text-[#172033] hover:bg-[#F7F9FC] px-3`} disabled={!hasPrev} onClick={onPrev}>
      الأحدث
    </button>
    <button type="button" className={`${btn} bg-white border-[#E6EAF0] text-[#172033] hover:bg-[#F7F9FC] px-3`} disabled={!hasNext} onClick={onNext}>
      الأقدم
    </button>
  </div>
);
