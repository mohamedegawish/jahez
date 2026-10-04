import React from 'react';
import { BillingWorkspace } from '../../components/billing/BillingWorkspace';

/** IMC billing overview: every invoice and payment, status counts and the state of the financial policy. */
export const FinancialsReports: React.FC = () => <BillingWorkspace mySide="admin" />;
