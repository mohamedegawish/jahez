import React from 'react';
import { BillingWorkspace } from '../../components/billing/BillingWorkspace';

/** The factory's invoices and payments (the API scopes the list to this factory). */
export const FactoryInvoices: React.FC = () => <BillingWorkspace mySide="factory" />;
