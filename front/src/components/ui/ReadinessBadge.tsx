import React from 'react';
import { Badge } from './Badge';

type BadgeVariant = React.ComponentProps<typeof Badge>['variant'];

// The four readiness categories come from the API (ADR-018); only their colour lives here.
const VARIANT: Record<string, BadgeVariant> = {
  b4_automation: 'neutral',
  basic: 'blue',
  advanced: 'purple',
  smart: 'success',
};

interface ReadinessBadgeProps {
  category: { code: string; name_ar: string | null } | null | undefined;
  size?: 'sm' | 'md';
}

/** A readiness category or level (ADR-018, ADR-026) as decided by the server, or "not assessed". */
export const ReadinessBadge: React.FC<ReadinessBadgeProps> = ({ category, size = 'md' }) =>
  category ? (
    <Badge variant={VARIANT[category.code] ?? 'blue'} size={size}>
      {category.name_ar ?? '—'}
    </Badge>
  ) : (
    <Badge variant="neutral" size={size}>
      لم يُقيَّم بعد
    </Badge>
  );
