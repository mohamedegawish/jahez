// JAHEZ — Smart Industry Ecosystem Types

export type UserRole = 'admin' | 'provider' | 'factory';

export interface User {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  avatar?: string;
  organizationName: string;
  designation?: string;
}

export type MaturityLevel = 'Foundational' | 'Basic' | 'Advanced' | 'Smart';

export interface Factory {
  id: string;
  name: string;
  industry: string;
  maturityLevel: MaturityLevel;
  readinessScore: number;
  accountStatus: 'Active' | 'Pending' | 'Suspended';
  registrationDate: string;
  activeContractsCount: number;
  contactPerson: string;
  email: string;
  phone: string;
  location: string;
  crNumber: string;
  employeesCount: number;
  annualTurnover?: string;
  transformationStage: 'Assessment' | 'Planning' | 'Service Selection' | 'Implementation' | 'Smart Manufacturing';
}

export interface ProviderEvaluation {
  experience: number; // Max 30%
  saas: number; // Max 25%
  knowledgeTransfer: number; // Max 20%
  revenueShare: number; // Max 15%
  sla: number; // Max 10%
  totalScore: number; // 0-100
  notes?: string;
}

export interface ServiceProvider {
  id: string;
  companyName: string;
  representative: string;
  jobTitle: string;
  email: string;
  phone: string;
  website: string;
  industry: string;
  yearsOfExperience: number;
  servicesCount: number;
  approvalStatus: 'Approved' | 'Under Review' | 'Rejected';
  accountStatus: 'Active' | 'Pending' | 'Suspended';
  registrationDate: string;
  commercialRegistration: string;
  taxNumber: string;
  description: string;
  evaluation: ProviderEvaluation;
  documents: {
    id: string;
    title: string;
    type: string;
    status: 'Verified' | 'Pending' | 'Rejected';
    uploadDate: string;
  }[];
}

export type ServiceCategory = 
  | 'ERP & Applications'
  | 'OT & Automation'
  | 'Cloud & Infrastructure'
  | 'Cybersecurity for ICS/OT'
  | 'AI, Data & Analytics'
  | 'Digital Engineering & Smart Manufacturing'
  | 'Digital Transformation Consulting';

export interface ServiceItem {
  id: string;
  code: string; // e.g. JHZ-SRV-042
  name: string;
  category: ServiceCategory;
  providerId: string;
  providerName: string;
  description: string;
  fullDescription?: string;
  scopeOfWork: string[];
  deliverables: string[];
  requirements: string[];
  pricingModel: 'Fixed' | 'Subscription (SaaS)' | 'Milestone-based' | 'Consulting Hourly';
  priceEstimate: string;
  revSharePercentage: number; // e.g. 15%
  approvalStatus: 'Approved' | 'Under Review' | 'Rejected';
  isActive: boolean;
  implementationTimeline: string;
  rating?: number;
  targetMaturityLevel?: MaturityLevel;
}

export type RequestStatus = 
  | 'New'
  | 'Under Review'
  | 'Proposal Sent'
  | 'Negotiation'
  | 'Accepted'
  | 'Rejected';

export interface ServiceRequest {
  id: string;
  requestCode: string; // e.g. REQ-2026-089
  factoryId: string;
  factoryName: string;
  serviceId: string;
  serviceName: string;
  category: ServiceCategory;
  providerId: string;
  providerName: string;
  createdAt: string;
  budget: string;
  status: RequestStatus;
  requirements: string;
  technicalDetails?: string;
  desiredTimeline: string;
  contactName: string;
  contactEmail: string;
}

export interface NegotiationMessage {
  id: string;
  sender: 'factory' | 'provider' | 'admin';
  senderName: string;
  avatar?: string;
  message: string;
  timestamp: string;
  documentAttachment?: {
    name: string;
    size: string;
    url: string;
  };
}

export interface ProposalDetail {
  id: string;
  requestId: string;
  technicalOfferSummary: string;
  financialOfferAmount: number;
  currency: string;
  durationWeeks: number;
  deliverables: string[];
  slaGuarantee: string;
  revisionNumber: number;
  status: 'Pending Review' | 'Revision Requested' | 'Accepted' | 'Declined';
  updatedAt: string;
}

export type ContractStatus = 
  | 'Draft'
  | 'Under Review'
  | 'Active'
  | 'Expiring Soon'
  | 'Completed'
  | 'Cancelled';

export interface Contract {
  id: string;
  contractCode: string; // e.g. CTR-JHZ-2026-014
  factoryId: string;
  factoryName: string;
  providerId: string;
  providerName: string;
  services: {
    serviceId: string;
    name: string;
    code: string;
  }[];
  contractValue: number;
  revenueShare: {
    imcPercentage: number;
    imcAmount: number;
    providerPercentage: number;
    providerAmount: number;
  };
  startDate: string;
  endDate: string;
  status: ContractStatus;
  termsSummary: string;
  partiesSigned: {
    factory: boolean;
    provider: boolean;
    imc: boolean;
  };
  documents: {
    name: string;
    type: string;
    fileSize: string;
    date: string;
  }[];
}

export interface Invoice {
  id: string;
  invoiceNumber: string; // e.g. INV-2026-552
  contractId: string;
  contractCode: string;
  factoryName: string;
  providerName: string;
  amount: number;
  imcShareAmount: number;
  providerShareAmount: number;
  issueDate: string;
  dueDate: string;
  paymentMethod?: string;
  status: 'Paid' | 'Pending' | 'Overdue';
}

export interface AssessmentQuestion {
  id: string;
  pillar: 'Infrastructure' | 'Operations' | 'Data' | 'Automation' | 'Cybersecurity' | 'Capabilities';
  pillarTitleAr: string;
  questionAr: string;
  questionEn: string;
  options: {
    labelAr: string;
    labelEn: string;
    score: number; // 1 to 4
    levelIndicator: MaturityLevel;
  }[];
}

export interface AssessmentSubmission {
  factoryId: string;
  answers: Record<string, number>;
  totalScore: number; // 0 to 100
  level: MaturityLevel;
  pillarScores: Record<string, number>;
  recommendedServiceCategory: ServiceCategory[];
  completedAt: string;
}

// JAHEZ — Homepage Advertisement Cards (admin-managed)
export type AdColor = 'green' | 'blue' | 'orange' | 'red' | 'beige' | 'purple';

export interface AdCard {
  id: string;
  title: string;
  description: string;
  date: string; // header date text, e.g. "5 نوفمبر 2026"
  color: AdColor; // admin-chosen color preset
  progressPercent?: number; // 0-100 fill of the progress bar
  progressValue?: string; // text shown right of the progress label, e.g. "75%"
  progressLabel?: string; // text shown left above the bar
  imgSrc1?: string; // first footer avatar (URL or data URL)
  imgAlt1?: string;
  imgSrc2?: string; // second footer avatar (URL or data URL)
  imgAlt2?: string;
  link?: string; // route opened when the ad is clicked
  countdownText?: string; // e.g. "ينتهي خلال 5 أيام"
  coverImage?: string; // main cover image for the notched card (URL or data URL)
  coverAlt?: string; // accessible description of the cover image
  tags?: string[]; // keyword tags shown on the ad details page
  isActive: boolean;
  createdAt: string;
}
