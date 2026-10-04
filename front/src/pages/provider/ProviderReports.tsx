import React from 'react';
import { ReportsView } from '../../components/reports/ReportsView';

/** The provider's reports, computed by the API from its own records. */
export const ProviderReports: React.FC = () => <ReportsView side="provider" />;
