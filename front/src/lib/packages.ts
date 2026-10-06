import type { BillingPeriod, ServiceListingPackage } from '../api/types';

/** Listing packages and prices (jahez_api ADR-027): labels shared by the provider, factory and IMC screens. */
export const PERIOD_LABEL: Record<BillingPeriod, string> = { monthly: 'شهريًا', annual: 'سنويًا' };

/** "حتى 10 مستخدم", or null when the package states no number. */
export function usersLabel(count: number | null | undefined): string | null {
  if (count === null || count === undefined) return null;
  return count === 1 ? 'مستخدم واحد' : `حتى ${count} مستخدم`;
}

/** The listed price of a package for a period, or null when it has none. */
export function packagePrice(pkg: ServiceListingPackage, period: BillingPeriod): string | null {
  return period === 'monthly' ? pkg.monthly_price : pkg.annual_price;
}
