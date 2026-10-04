import React from 'react';
import { AgreementsWorkspace } from '../../components/contracts/AgreementsWorkspace';

/** The factory's agreements and contract drafts (shared view; the API scopes it to this factory). */
export const FactoryContracts: React.FC = () => <AgreementsWorkspace mySide="factory" />;
