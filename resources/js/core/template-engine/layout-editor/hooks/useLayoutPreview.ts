/**
 * useLayoutPreview.ts — 편집 중 레이아웃 실데이터 미리보기 hook
 *
 * 현재 편집 중(미저장 포함) 문서 content 를 임시 저장(`storePreview`)하고, 응답
 * 토큰으로 `/preview/{token}` 을 새 창에 연다. 캔버스가 이미 샘플 렌더를
 * 하므로 우선순위는 낮으나, 실데이터(서버 데이터소스 응답) 기준 미리보기를 위해 구현.
 *
 *  - POST /api/admin/templates/{id}/layouts/{name}/preview  body { content }
 *    → { token, preview_url, expires_at }
 *  - openWindow `/preview/{token}` (코어 dispatch — target 미지정 시 새 창)
 *
 * 저장 페이로드는 useLayoutDocument 저장과 동일 마스킹(stripInheritedFromLayoutContent)
 * 후 content 를 직렬화한다 — 상속/주입/partial 노드 + 편집기 전용 메타 제거.
 *
 * extension 편집 모드는 `layoutName === null` 이라 미리보기 대상이 없다 → 호출 측에서
 * 버튼이 비활성된다(extension 분기는 S9 의존으로 보류).
 *
 * @since engine-v1.50.0
 */

import { useCallback, useState } from 'react';
import { buildAuthHeaders } from '../utils/authToken';
import { stripInheritedFromLayoutContent } from '../utils/layoutTreeUtils';

/** 미리보기 결과 — UI 분기용. */
export type PreviewResult =
  | { kind: 'success'; previewUrl: string; token: string }
  | { kind: 'no_document' }
  | { kind: 'network_error'; message: string };

export interface UseLayoutPreviewResult {
  /** 미리보기 생성 진행 중 여부 */
  isCreating: boolean;
  /** 마지막 에러 메시지 (null = 정상) */
  error: string | null;
  /**
   * 현재 문서 raw 로 미리보기 생성 후 새 창 열기.
   *
   * @param raw 현재 편집 중 문서 raw (useLayoutDocument.document.raw)
   * @return 결과 (성공 시 previewUrl 포함)
   */
  createPreview: (raw: Record<string, unknown> | null | undefined) => Promise<PreviewResult>;
}

/**
 * 실데이터 미리보기 hook.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param layoutName 현재 레이아웃 이름 (null = 미리보기 대상 없음)
 * @return 미리보기 생성 상태 + 액션
 */
export function useLayoutPreview(
  templateIdentifier: string,
  layoutName: string | null,
): UseLayoutPreviewResult {
  const [isCreating, setIsCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const createPreview = useCallback(
    async (raw: Record<string, unknown> | null | undefined): Promise<PreviewResult> => {
      if (!layoutName || !raw) {
        return { kind: 'no_document' };
      }
      setIsCreating(true);
      setError(null);
      try {
        // 저장과 동일 마스킹 후 content 직렬화.
        const maskedContent = stripInheritedFromLayoutContent(raw);
        const url = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/layouts/${layoutName}/preview`;
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ content: JSON.stringify(maskedContent) }),
        });
        const body = await response.json().catch(() => null);
        if (!response.ok) {
          const message = (body as { message?: string })?.message ?? `HTTP ${response.status}`;
          setError(message);
          return { kind: 'network_error', message };
        }
        const data = (body as { data?: { token?: unknown; preview_url?: unknown } })?.data;
        const token = typeof data?.token === 'string' ? data.token : '';
        const previewUrl =
          typeof data?.preview_url === 'string' && data.preview_url
            ? data.preview_url
            : token
              ? `/preview/${token}`
              : '';
        if (!previewUrl) {
          setError('invalid preview response');
          return { kind: 'network_error', message: 'invalid preview response' };
        }
        // openWindow — target 미지정 시 새 창(`_blank`). 편집기는 React Router 밖
        // 전체화면 오버레이라 코어 dispatch(openWindow)로 실제 창을 연다.
        (window as { G7Core?: { dispatch?: (action: unknown) => void } }).G7Core?.dispatch?.({
          handler: 'openWindow',
          params: { path: previewUrl },
        });
        return { kind: 'success', previewUrl, token };
      } catch (err: unknown) {
        const message = err instanceof Error ? err.message : 'network error';
        setError(message);
        return { kind: 'network_error', message };
      } finally {
        setIsCreating(false);
      }
    },
    [templateIdentifier, layoutName],
  );

  return { isCreating, error, createPreview };
}
