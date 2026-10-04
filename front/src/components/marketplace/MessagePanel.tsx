import React, { useEffect, useRef, useState } from 'react';
import { RefreshCw, Send } from 'lucide-react';
import { api } from '../../api';
import type { NegotiationMessage, ProviderRequest } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { CardSkeleton } from '../ui/LoadingState';

const PER_PAGE = 50;

interface MessagePanelProps {
  thread: ProviderRequest;
  /** Which side the signed-in user is on; decides bubble alignment. */
  mySide: 'factory' | 'provider';
  /** Bumped by the parent's refresh timer. */
  refreshKey: number;
}

/**
 * The thread's messages, oldest first, shown from the newest page. Messages open only while the
 * provider has accepted the thread (status `accepted`); in any other state the composer explains why.
 */
export const MessagePanel: React.FC<MessagePanelProps> = ({ thread, mySide, refreshKey }) => {
  const [body, setBody] = useState('');
  // null = the newest page; a number = a page the user navigated back to.
  const [pageOverride, setPageOverride] = useState<number | null>(null);
  const bottomRef = useRef<HTMLDivElement | null>(null);

  const messages = useApiQuery(
    async (signal) => {
      const first = await api.providerRequests.messages.list(thread.id, { page: pageOverride ?? 1, per_page: PER_PAGE, signal });
      if (pageOverride === null && first.meta.last_page > 1) {
        return api.providerRequests.messages.list(thread.id, { page: first.meta.last_page, per_page: PER_PAGE, signal });
      }
      return first;
    },
    [thread.id, pageOverride, refreshKey],
  );

  const send = useApiMutation((text: string) => api.providerRequests.messages.create(thread.id, text));

  const canPost = thread.status === 'accepted';
  const list: NegotiationMessage[] = messages.data?.data ?? [];
  const meta = messages.data?.meta;

  useEffect(() => {
    if (pageOverride === null) bottomRef.current?.scrollIntoView({ block: 'end' });
  }, [list.length, pageOverride]);

  const handleSend = async () => {
    const text = body.trim();
    if (text === '') return;
    const result = await send.run(text);
    if (result.ok) {
      setBody('');
      setPageOverride(null);
      messages.refetch();
    }
  };

  return (
    <div className="jahez-card flex flex-col overflow-hidden h-full min-h-[420px]">
      <div className="p-3.5 border-b border-[#E6EAF0] bg-[#F7F9FC] flex items-center justify-between">
        <span className="text-xs font-bold text-[#172033] flex items-center gap-2">
          <span className={`w-2 h-2 rounded-full ${canPost ? 'bg-[#35B779]' : 'bg-[#98A2B3]'}`} />
          قناة التفاوض المباشرة
        </span>
        <button
          type="button"
          onClick={messages.refetch}
          className="text-[11px] text-[#667085] hover:text-[#172033] inline-flex items-center gap-1 cursor-pointer"
          title="تحديث الرسائل"
        >
          <RefreshCw className="w-3.5 h-3.5" /> تحديث
        </button>
      </div>

      <div className="flex-1 p-4 overflow-y-auto space-y-3.5" data-testid="messages">
        {messages.status === 'loading' && <CardSkeleton />}
        {messages.status === 'error' && <ApiErrorState error={messages.error} onRetry={messages.refetch} compact />}
        {messages.refreshError !== null && (
          <div data-testid="refresh-error">
            <ApiErrorState compact error={messages.refreshError} onRetry={messages.refetch} />
            <p className="mt-1 text-[11px] text-[#98A2B3]">الرسائل المعروضة آخر نسخة محمّلة بنجاح وقد لا تكون محدّثة.</p>
          </div>
        )}

        {meta && meta.current_page > 1 && (
          <button type="button" onClick={() => setPageOverride(meta.current_page - 1)} className="block mx-auto text-[11px] font-semibold text-[#0A6EB0] hover:underline cursor-pointer">
            عرض رسائل أقدم
          </button>
        )}
        {pageOverride !== null && meta && meta.current_page < meta.last_page && (
          <button type="button" onClick={() => setPageOverride(null)} className="block mx-auto text-[11px] font-semibold text-[#0A6EB0] hover:underline cursor-pointer">
            العودة إلى أحدث الرسائل
          </button>
        )}

        {messages.status === 'success' && list.length === 0 && (
          <p className="text-center text-xs text-[#98A2B3] py-10">
            {canPost ? 'لا توجد رسائل بعد. ابدأ المحادثة.' : 'لا توجد رسائل في هذه المحادثة.'}
          </p>
        )}

        {list.map((message) => {
          const mine = message.author_side === mySide;
          return (
            <div key={message.id} data-message-id={message.id} className={`flex flex-col ${mine ? 'items-start' : 'items-end'}`}>
              <div className="text-[10px] text-[#98A2B3] mb-1 px-1">
                {message.author?.name ?? (message.author_side === 'factory' ? 'المصنع' : 'المزود')} • {formatDateTime(message.created_at)}
              </div>
              <div
                className={`max-w-[85%] p-3.5 rounded-2xl text-xs leading-relaxed shadow-xs whitespace-pre-line break-words ${
                  mine ? 'bg-[#5146A5] text-white rounded-br-xs' : 'bg-[#F1F4F9] text-[#172033] rounded-bl-xs border border-[#E6EAF0]'
                }`}
                dir="auto"
              >
                {message.body}
              </div>
            </div>
          );
        })}
        <div ref={bottomRef} />
      </div>

      <div className="p-3 border-t border-[#E6EAF0] bg-white space-y-2">
        {send.error !== null && <ApiErrorState compact error={send.error} />}
        {!canPost && (
          <p className="text-[11px] text-[#98A2B3]">
            {thread.status === 'pending'
              ? 'تُفتح الرسائل بعد أن يقبل المزود الطلب.'
              : 'انتهت هذه المحادثة، فلا يمكن إرسال رسائل جديدة.'}
          </p>
        )}
        <div className="flex items-center gap-2">
          <input
            type="text"
            aria-label="نص الرسالة"
            value={body}
            maxLength={5000}
            disabled={!canPost || send.pending}
            onChange={(e) => setBody(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && void handleSend()}
            placeholder={mySide === 'provider' ? 'اكتب ردك أو استفسارك للمصنع...' : 'اكتب رسالتك للمزود...'}
            className="flex-1 py-2 px-3 text-xs bg-[#F7F9FC] border border-[#E6EAF0] rounded-xl focus:outline-none focus:border-[#6EC8FF] disabled:opacity-60"
          />
          <Button size="sm" variant="primary" onClick={handleSend} icon={Send} isLoading={send.pending} disabled={!canPost || body.trim() === ''}>
            إرسال
          </Button>
        </div>
        <p className="text-[10px] text-[#98A2B3]">رسائل نصية فقط؛ إرفاق الملفات غير مدعوم في الخادم حاليًا.</p>
      </div>
    </div>
  );
};
