/**
 * editorErrors.ts — 레이아웃 편집기 공통 에러 타입
 *
 * 여러 hook (`useLayoutDocument` / `useEditorTemplateAssets` / `useEditorRoutes`)
 * 의 실패 응답을 통일된 구조로 표현해 `AccessErrorPanel` 이 단일 분기 렌더로
 * 처리하게 한다.
 *
 * 분류:
 * - `unauthorized` (401) — 비로그인/세션 만료 → 자동 로그인 redirect
 * - `forbidden` (403) — 권한 부족
 * - `not_found` (404) — 리소스 없음
 * - `server_error` (5xx) — 서버 오류
 * - `network` — fetch 자체 실패 (네트워크/CORS/파싱)
 * - `unknown` — 그 외
 *
 * @since engine-v1.50.0
 */

export type EditorErrorKind =
  | 'unauthorized'
  | 'forbidden'
  | 'not_found'
  | 'server_error'
  | 'network'
  | 'unknown';

export interface EditorAccessError {
  kind: EditorErrorKind;
  /** HTTP 상태 코드 (네트워크 실패 등은 0) */
  status: number;
  /** 백엔드/네트워크가 제공한 원본 메시지 (UI 폴백/디버깅용) */
  message: string;
  /** 백엔드 응답이 제공한 필요 권한 (403/401 시 노출용) */
  requiredPermissions?: string;
  /**
   * 에러 발생 소스 — `AccessErrorPanel` 이 안내 문구를 미세 조정할 때 활용.
   * - `layout` : 레이아웃 문서 fetch 실패 (useLayoutDocument)
   * - `assets` : 편집 대상 자산 manifest/IIFE/lang 로드 실패
   * - `routes` : 라우트 트리 fetch 실패
   */
  source?: 'layout' | 'assets' | 'routes';
}

/**
 * HTTP status + body → EditorAccessError 변환.
 *
 * 백엔드 응답은 `ResponseHelper` 표준 (`{ success, message, data:
 * { required_permissions } }`) 또는 단순 `{ message }` 형태를 다룬다.
 */
export function buildEditorAccessError(
  status: number,
  body: unknown,
  source?: EditorAccessError['source'],
): EditorAccessError {
  const b = (body ?? {}) as Record<string, any>;
  const message =
    (b.message as string) ||
    (b.error as string) ||
    `HTTP ${status}`;
  const requiredPermissions =
    (b?.data?.required_permissions as string | undefined) ??
    (b?.required_permissions as string | undefined);

  let kind: EditorErrorKind;
  if (status === 401) kind = 'unauthorized';
  else if (status === 403) kind = 'forbidden';
  else if (status === 404) kind = 'not_found';
  else if (status >= 500) kind = 'server_error';
  else kind = 'unknown';

  return {
    kind,
    status,
    message: String(message),
    requiredPermissions: requiredPermissions ? String(requiredPermissions) : undefined,
    source,
  };
}

/**
 * fetch 자체 실패 (network/parse) → EditorAccessError.
 */
export function buildNetworkError(
  err: unknown,
  source?: EditorAccessError['source'],
): EditorAccessError {
  return {
    kind: 'network',
    status: 0,
    message: err instanceof Error ? err.message : String(err ?? 'Unknown network error'),
    source,
  };
}

/**
 * 값이 EditorAccessError 구조를 갖는지 판정 — try/catch 에서 throw 한 객체가
 * 이미 구조화된 에러인지 일반 Error 인지 구분할 때 사용.
 */
export function isEditorAccessError(value: unknown): value is EditorAccessError {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    typeof v.kind === 'string' &&
    typeof v.status === 'number' &&
    typeof v.message === 'string'
  );
}
