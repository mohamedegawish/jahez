import React, { useEffect, useState } from 'react';
import { Download, FileText } from 'lucide-react';
import type { OrganizationDocument } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { formatBytes, saveBlob } from '../../lib/files';
import { formatDate } from '../../lib/format';
import { ApiErrorState } from './ApiErrorState';

/**
 * An image the API serves only with the session token (a logo). It is fetched as a blob and
 * shown through an object URL, which is released when the component unmounts.
 */
export const PrivateImage: React.FC<{
  load: (signal: AbortSignal) => Promise<Blob>;
  /** Changes when the file changes (for example the document id), so the image is fetched again. */
  version: string | number;
  alt: string;
  className?: string;
  fallback?: React.ReactNode;
}> = ({ load, version, alt, className = '', fallback = null }) => {
  const [result, setResult] = useState<{ version: string | number; url: string | null }>({ version, url: null });

  useEffect(() => {
    const controller = new AbortController();
    let objectUrl: string | null = null;
    load(controller.signal)
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob);
        setResult({ version, url: objectUrl });
      })
      .catch(() => {
        if (!controller.signal.aborted) setResult({ version, url: null });
      });
    return () => {
      controller.abort();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
    // `load` is a new function on every render; `version` identifies the file.
    // oxlint-disable-next-line react-hooks/exhaustive-deps
  }, [version]);

  const url = result.version === version ? result.url : null;
  return url ? <img src={url} alt={alt} className={className} /> : <>{fallback}</>;
};

/** One uploaded document's metadata with a download button that reads the private file. */
export const DocumentRow: React.FC<{
  document: OrganizationDocument;
  label: string;
  load: () => Promise<Blob>;
  badge?: React.ReactNode;
}> = ({ document, label, load, badge }) => {
  const download = useApiMutation(load);

  const handleDownload = async () => {
    const result = await download.run();
    if (result.ok) saveBlob(result.data, document.original_name);
  };

  return (
    <div className="p-3 rounded-xl border border-[#E6EAF0] bg-white text-xs space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-2 min-w-0">
          <FileText className="w-4 h-4 text-[#5146A5] shrink-0 mt-0.5" />
          <div className="min-w-0">
            <div className="font-bold text-[#172033]">{label}</div>
            <div className="text-[#667085] truncate" dir="auto" title={document.original_name}>{document.original_name}</div>
            <div className="text-[10px] text-[#98A2B3]">
              {formatBytes(document.size_bytes)} · {formatDate(document.uploaded_at)}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {badge}
          <button
            type="button"
            onClick={handleDownload}
            disabled={download.pending}
            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-[#E6EAF0] font-bold text-[#0A6EB0] hover:bg-[#F7F9FC] disabled:opacity-50 cursor-pointer"
          >
            <Download className="w-3.5 h-3.5" />
            {download.pending ? 'جارٍ التنزيل...' : 'تنزيل'}
          </button>
        </div>
      </div>
      {download.error !== null && <ApiErrorState compact error={download.error} />}
    </div>
  );
};
