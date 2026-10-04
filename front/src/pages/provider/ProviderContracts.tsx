import React from 'react';
import { AgreementsWorkspace } from '../../components/contracts/AgreementsWorkspace';

/** The provider's agreements and contract drafts (shared view; the API scopes it to this provider). */
export const ProviderContracts: React.FC = () => <AgreementsWorkspace mySide="provider" />;
