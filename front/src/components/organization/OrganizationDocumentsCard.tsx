import React, { useState } from 'react';
import { ImageIcon, Upload } from 'lucide-react';
import type { DocumentType, OrganizationDocuments } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { documentTypeLabel, LEGAL_ACCEPT, LEGAL_MAX_KB, LOGO_ACCEPT, LOGO_MAX_KB } from '../../lib/files';
import { fieldMessages } from '../../api';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FileField } from '../ui/FileField';
import { DocumentRow, PrivateImage } from '../ui/PrivateFile';

interface OrganizationDocumentsCardProps {
  documents: OrganizationDocuments | undefined;
  upload: (type: DocumentType, file: File) => Promise<unknown>;
  loadFile: (documentId: number, signal?: AbortSignal) => Promise<Blob>;
  onUploaded: () => void;
  /** Legal documents are replaced through a change request instead (approved providers). */
  legalLocked?: boolean;
}

/**
 * The logo and the registration documents of a factory or provider (ADR-019). Files are private:
 * they are read with the session token and never through a public link. Uploading a new file
 * replaces the current one; the previous file is kept on the server.
 */
export const OrganizationDocumentsCard: React.FC<OrganizationDocumentsCardProps> = ({ documents, upload, loadFile, onUploaded, legalLocked = false }) => {
  const logo = documents?.logo ?? null;

  return (
    <Card title="الشعار والمستندات" accent="blue">
      <div className="space-y-5">
        <div className="flex items-center gap-4">
          <div className="w-20 h-20 rounded-2xl border border-[#E6EAF0] bg-[#F7F9FC] flex items-center justify-center overflow-hidden shrink-0">
            {logo ? (
              <PrivateImage
                load={(signal) => loadFile(logo.id, signal)}
                version={logo.id}
                alt="الشعار"
                className="w-full h-full object-contain"
                fallback={<ImageIcon className="w-6 h-6 text-[#98A2B3]" />}
              />
            ) : (
              <ImageIcon className="w-6 h-6 text-[#98A2B3]" />
            )}
          </div>
          <div className="flex-1 min-w-0">
            <UploadControl type="logo" accept={LOGO_ACCEPT} maxKb={LOGO_MAX_KB} upload={upload} onUploaded={onUploaded} replace={logo !== null} />
          </div>
        </div>

        {(['commercial_registration', 'tax_registration'] as const).map((type) => {
          const document = documents?.[type] ?? null;
          return (
            <div key={type} className="space-y-2">
              {document ? (
                <DocumentRow document={document} label={documentTypeLabel[type]} load={() => loadFile(document.id)} />
              ) : (
                <p className="text-xs text-[#667085]">{documentTypeLabel[type]}: لم يُرفع بعد.</p>
              )}
              {!legalLocked && (
                <UploadControl type={type} accept={LEGAL_ACCEPT} maxKb={LEGAL_MAX_KB} upload={upload} onUploaded={onUploaded} replace={document !== null} />
              )}
            </div>
          );
        })}
        {legalLocked && (
          <p className="text-[11px] text-[#667085] leading-relaxed">
            تحقق مركز تحديث الصناعة من مستندات التسجيل. لاستبدال أحدها أرسل طلب تعديل البيانات القانونية.
          </p>
        )}
      </div>
    </Card>
  );
};

const UploadControl: React.FC<{
  type: DocumentType;
  accept: string;
  maxKb: number;
  upload: (type: DocumentType, file: File) => Promise<unknown>;
  onUploaded: () => void;
  replace: boolean;
}> = ({ type, accept, maxKb, upload, onUploaded, replace }) => {
  const [file, setFile] = useState<File | null>(null);
  const [done, setDone] = useState(false);
  const send = useApiMutation((chosen: File) => upload(type, chosen));

  const handleUpload = async () => {
    if (!file) return;
    setDone(false);
    const result = await send.run(file);
    if (result.ok) {
      setFile(null);
      setDone(true);
      onUploaded();
    }
  };

  return (
    <div className="space-y-2">
      <FileField
        label={`${replace ? 'استبدال' : 'رفع'} ${documentTypeLabel[type]}`}
        accept={accept}
        maxKb={maxKb}
        value={file}
        onChange={(chosen) => {
          setFile(chosen);
          setDone(false);
        }}
        messages={fieldMessages(send.error, 'file')}
        disabled={send.pending}
      />
      {file && (
        <Button variant="secondary" size="sm" icon={Upload} onClick={handleUpload} isLoading={send.pending}>
          رفع الملف
        </Button>
      )}
      {done && <p role="status" className="text-[11px] font-semibold text-[#1D7E4C]">تم رفع الملف وحفظه.</p>}
      {send.error !== null && fieldMessages(send.error, 'file').length === 0 && <ApiErrorState compact error={send.error} />}
    </div>
  );
};
