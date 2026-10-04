import { api } from '../api';
import type {
  CatalogService,
  EvaluationCriterion,
  FactorySize,
  MaturityTier,
  Pathway,
  Sector,
  ServiceCategory,
} from '../api';
import { cachedReference } from '../lib/referenceCache';
import { useApiQuery, type ApiQuery } from './useApiQuery';

// Reference data (GET /reference/*, GET /catalog/*) is bounded and changes only through the API's
// seeders, so it is fetched once per session and shared by every screen. This replaces the sector,
// category and size constants that used to be hard-coded in forms and filters.

const useReferenceQuery = <T>(key: string, load: () => Promise<T>): ApiQuery<T> =>
  useApiQuery(() => cachedReference(key, load), []);

export const useSectors = (): ApiQuery<Sector[]> => useReferenceQuery('sectors', () => api.reference.sectors());
export const useFactorySizes = (): ApiQuery<FactorySize[]> => useReferenceQuery('factory-sizes', () => api.reference.factorySizes());
export const useMaturityTiers = (): ApiQuery<MaturityTier[]> => useReferenceQuery('maturity-tiers', () => api.reference.maturityTiers());
export const usePathways = (): ApiQuery<Pathway[]> => useReferenceQuery('pathways', () => api.reference.pathways());
export const useEvaluationCriteria = (): ApiQuery<EvaluationCriterion[]> =>
  useReferenceQuery('evaluation-criteria', () => api.reference.evaluationCriteria());
/** The 7 catalog categories, each with its services. */
export const useCatalogCategories = (): ApiQuery<ServiceCategory[]> => useReferenceQuery('catalog-categories', () => api.catalog.categories());
/** All 42 catalog services, each with its category. Per-user filters (`eligible`, `recommended`) are not cached. */
export const useCatalogServices = (): ApiQuery<CatalogService[]> => useReferenceQuery('catalog-services', () => api.catalog.services());

/** Display name for a reference code (sector, size, category), falling back to the raw code. */
export function nameOf(items: readonly { code: string; name_ar: string }[] | undefined, code: string | null | undefined): string {
  if (!code) return '-';
  return items?.find((item) => item.code === code)?.name_ar ?? code;
}
