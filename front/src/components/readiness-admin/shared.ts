export const inputClass = 'w-full p-2 rounded-lg border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF] bg-white disabled:bg-[#F7F9FC] disabled:text-[#475467]';
export const selectClass = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/** Colours of the four readiness levels in charts and bars. */
export const CATEGORY_COLORS: Record<string, string> = { b4_automation: '#98A2B3', basic: '#6EC8FF', advanced: '#9B8AFB', smart: '#35B779' };

/** The result-list filters kept in the URL (`/admin/readiness?tab=results&…`). */
export const RESULT_FILTER_KEYS = ['category', 'version', 'current', 'from', 'to', 'score_min', 'score_max', 'factory'] as const;

/** Swap an item with its neighbour; ordering is the only structural change a version allows. */
export function move<T>(list: T[], index: number, offset: -1 | 1): void {
  const target = index + offset;
  if (target < 0 || target >= list.length) return;
  [list[index], list[target]] = [list[target], list[index]];
}
