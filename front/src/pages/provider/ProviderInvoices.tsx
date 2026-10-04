import React from 'react';
import { BillingWorkspace } from '../../components/billing/BillingWorkspace';

/** The provider's invoices (the API scopes the list to this provider). */
export const ProviderInvoices: React.FC = () => <BillingWorkspace mySide="provider" />;
