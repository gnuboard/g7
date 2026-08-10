/**
 * useLayoutVersions.ts — 레이아웃 버전 히스토리 조회/복원 hook
 *
 * 기존 코드 편집기가 쓰는 `LayoutController` 의 버전 API 를 재사용한다(신규 백엔드 없음):
 *  - GET  /api/admin/templates/{id}/layouts/{name}/versions          (목록)
 *  - GET  /api/admin/templates/{id}/layouts/{name}/versions/{version} (단건 content)
 *  - POST /api/admin/templates/{id}/layouts/{name}/versions/{id}/restore (복원)
 *
 * 복원은 새 버전을 적재(`restoreVersion`)하므로, 성공 후 호출자가 `useLayoutDocument.reload()`
 * 를 수행해 캔버스를 서버 최신(=복원된 content)으로 재로드하고 dirty/lock_version 을
 * 재동기화한다. 본 hook 은 fetch 만 담당하고 reload 는 onRestored 콜백으로 위임한다.
 *
 * 버전 API 는 `layoutName`(슬래시 포함 path-segmented 키)을 raw 그대로 URL 에 삽입한다 —
 * useLayoutDocument 의 로드/저장 URL 과 동일 규칙(encodeURIComponent 로 슬래시를 %2F 로
 * 바꾸면 Laravel where 제약이 404 를 반환).
 *
 * extension 편집 모드는 확장 전용 버전 API(`/layout-extensions/{id}/versions...`)를
 * 동일 흐름으로 사용한다. 대상 종류는
 * `VersionTarget` 으로 받으며, 확장 버전 단건 응답은 content 원본(JSON 문자열)을
 * full_content 로 변환해 레이아웃과 동일한 diff 비교 경로를 탄다.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useState } from 'react';
import { buildAuthHeaders } from '../utils/authToken';

/**
 * 버전 기록/복원 대상 — 레이아웃 본체 또는 확장 조각.
 * null 이면 대상 없음(버튼 비활성 등 호출 측 디그레이드).
 */
export type VersionTarget =
  | { kind: 'layout'; layoutName: string }
  | { kind: 'extension'; extensionId: string };

/**
 * 대상별 버전 API base URL 을 구성한다.
 *
 * layout 의 layoutName 은 raw 그대로 삽입(슬래시 포함 path-segmented 키 —
 * encodeURIComponent 금지, useLayoutDocument 로드/저장 URL 과 동일 규칙).
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param target 버전 대상
 * @returns `/versions` 까지 포함한 base URL
 */
function buildVersionsBaseUrl(templateIdentifier: string, target: VersionTarget): string {
  const prefix = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}`;
  return target.kind === 'layout'
    ? `${prefix}/layouts/${target.layoutName}/versions`
    : `${prefix}/layout-extensions/${encodeURIComponent(target.extensionId)}/versions`;
}

/**
 * 버전 변경 요약 — LayoutVersionResource.changes_summary 와 정합.
 *
 * 라인 단위 카운트(추가/삭제 라인 수 + 문자 수 변화). modified 는 라인 diff 에 대응
 * 개념이 없어(값 변경 = 삭제+추가 라인) 두지 않는다.
 */
export interface LayoutVersionChangesSummary {
  added_count: number;
  removed_count: number;
  char_diff: number;
}

/** 버전 목록 항목 — LayoutVersionResource / LayoutExtensionVersionResource 의 목록 표시 필드. */
export interface LayoutVersionSummary {
  id: number;
  /** 레이아웃 버전 행의 소속 레이아웃 ID (확장 버전 행에는 없음) */
  layout_id?: number;
  /** 확장 버전 행의 소속 확장 ID */
  extension_id?: number;
  version: number;
  changes_summary: LayoutVersionChangesSummary | null;
  created_at: string | null;
  /** 저장자 이름 (LayoutVersionResource.created_by_name) — 탈퇴/미로딩 시 null. */
  created_by_name: string | null;
}

/**
 * 특정 버전의 content 조회 결과 — showVersion 응답 (diff 비교용).
 * LayoutVersionResource 가 content 를 분해해 components/data_sources/metadata/endpoint 로 반환한다.
 */
export interface LayoutVersionDetail {
  version: number;
  endpoint: string | null;
  components: unknown;
  data_sources: unknown;
  metadata: unknown;
  /**
   * content 원본 전체 (단건 조회 전용 — withFullContent). slots/extends 등 분해되지
   * 않는 키까지 포함하므로 버전 비교 diff 는 이 값을 우선 사용한다. 구버전 응답/누락 시
   * undefined → 호출 측이 분해 키(components 등)로 폴백.
   */
  full_content?: Record<string, unknown>;
}

/** 버전 content 조회 결과 — UI 분기용. */
export type VersionDetailResult =
  | { kind: 'success'; detail: LayoutVersionDetail }
  | { kind: 'not_found' }
  | { kind: 'network_error'; message: string };

/**
 * 버전 목록 한 묶음 크기 — 서버 기본 상한과 같다.
 *
 * 버전 행은 저장할 때마다 쌓이고 정리되지 않으므로 서버가 조회 건수를 제한한다. 화면은
 * 기본 한 묶음을 보여주고 '더 보기' 로 이전 것을 넓혀 간다.
 */
export const VERSION_PAGE_SIZE = 100;

/**
 * 한 번에 조회 가능한 최대 건수 — 서버 `LayoutVersionListRequest::MAX_LIMIT` 과 같아야 한다.
 *
 * '더 보기' 는 조회 상한을 한 묶음씩 넓히는 방식이라, 넓히기만 하고 멈추지 않으면 서버 상한을
 * 넘는 순간 목록 전체가 422 가 되어 이미 보고 있던 이력까지 사라진다. 이 값에 도달하면 더
 * 넓히지 않는다.
 *
 * 두 상수의 일치는 `tests/Unit/LayoutVersionLimitParityTest.php` 가 검사한다.
 */
export const VERSION_MAX_LIMIT = 500;

/** 복원 결과 — UI 분기용. */
export type RestoreResult =
  | { kind: 'success'; newVersion: number }
  | { kind: 'not_found' }
  | { kind: 'network_error'; message: string };

export interface UseLayoutVersionsResult {
  /** 버전 목록 (최근 버전 우선) */
  versions: LayoutVersionSummary[];
  /** 목록 로딩 중 여부 */
  isLoading: boolean;
  /** 마지막 에러 메시지 (null = 정상) */
  error: string | null;
  /** 복원 진행 중인 버전 ID (null = 없음) */
  restoringId: number | null;
  /** 버전 목록 로드/재로드 (표시 건수는 현재까지 펼친 만큼 유지) */
  loadVersions: () => Promise<void>;
  /** 더 오래된 버전이 남아 있을 수 있는지 (마지막 응답이 요청 상한을 채웠는지) */
  hasMore: boolean;
  /** 다음 묶음까지 펼쳐 다시 조회 */
  loadMore: () => Promise<void>;
  /** 추가 로드 진행 중 여부 */
  isLoadingMore: boolean;
  /**
   * 특정 버전 복원 — 성공 시 onRestored 콜백 호출(호출자가 reload 수행).
   * onRestored 에 복원으로 적재된 새 버전 번호가 전달된다.
   */
  restore: (versionId: number) => Promise<RestoreResult>;
  /**
   * 특정 버전의 content 조회 (diff 비교용) — showVersion API.
   * version 은 버전 번호(LayoutVersionSummary.version), id 아님.
   */
  loadVersionDetail: (version: number) => Promise<VersionDetailResult>;
}

/**
 * 버전 히스토리 조회/복원 hook.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param target 버전 대상 — 레이아웃 본체 또는 확장 (null = 대상 없음)
 * @param onRestored 복원 성공 시 호출 — 호출자가 활성 문서 reload() 수행.
 *                   복원으로 적재된 새 버전 번호가 인자로 전달된다(응답 누락 시 undefined).
 * @return 버전 목록 상태 + 로드/복원 액션
 */
export function useLayoutVersions(
  templateIdentifier: string,
  target: VersionTarget | null,
  onRestored?: (newVersion?: number) => void | Promise<void>,
): UseLayoutVersionsResult {
  const [versions, setVersions] = useState<LayoutVersionSummary[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [restoringId, setRestoringId] = useState<number | null>(null);
  /** 현재 요청 중인 표시 상한 — '더 보기' 를 누를 때마다 한 묶음씩 넓어진다 */
  const [limit, setLimit] = useState(VERSION_PAGE_SIZE);
  const [hasMore, setHasMore] = useState(false);

  /**
   * 버전 목록을 지정한 상한으로 조회한다.
   *
   * 응답 건수가 요청 상한과 같으면 더 오래된 버전이 남아 있을 수 있다고 본다. 서버가 최신순
   * 상한 조회만 지원하므로(누적 페이지 토큰 없음), '더 보기' 는 상한을 넓혀 **다시 조회**한다 —
   * 이어붙이기(append)를 하지 않으므로 중복/누락이 생길 여지가 없다.
   */
  const fetchVersions = useCallback(
    async (nextLimit: number, mode: 'reload' | 'more'): Promise<void> => {
      if (!target) {
        setVersions([]);
        setError(null);
        setHasMore(false);
        return;
      }
      if (mode === 'more') {
        setIsLoadingMore(true);
      } else {
        setIsLoading(true);
      }
      setError(null);
      try {
        const url = `${buildVersionsBaseUrl(templateIdentifier, target)}?limit=${nextLimit}`;
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: buildAuthHeaders(),
        });
        const body = await response.json().catch(() => null);
        if (!response.ok) {
          setError((body as { message?: string })?.message ?? `HTTP ${response.status}`);
          if (mode === 'reload') setVersions([]);
          return;
        }
        const list = (body as { data?: unknown })?.data;
        const rows = Array.isArray(list) ? (list as LayoutVersionSummary[]) : [];
        setVersions(rows);
        // 서버 상한에 도달했으면 더 넓힐 수 없으므로 '더 보기' 를 감춘다 — 남은 이력이 있어도
        // 그 이상은 한 응답으로 받을 수 없다.
        setHasMore(rows.length >= nextLimit && nextLimit < VERSION_MAX_LIMIT);
        setLimit(nextLimit);
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'network error');
        if (mode === 'reload') setVersions([]);
      } finally {
        if (mode === 'more') {
          setIsLoadingMore(false);
        } else {
          setIsLoading(false);
        }
      }
    },
    [templateIdentifier, target],
  );

  const loadVersions = useCallback(async (): Promise<void> => {
    await fetchVersions(limit, 'reload');
  }, [fetchVersions, limit]);

  const loadMore = useCallback(async (): Promise<void> => {
    // 서버 상한을 넘는 limit 은 422 라 목록이 통째로 비어 버린다. 상한에서 멈춘다.
    const nextLimit = Math.min(limit + VERSION_PAGE_SIZE, VERSION_MAX_LIMIT);
    if (nextLimit <= limit) {
      setHasMore(false);

      return;
    }
    await fetchVersions(nextLimit, 'more');
  }, [fetchVersions, limit]);

  const restore = useCallback(
    async (versionId: number): Promise<RestoreResult> => {
      if (!target) return { kind: 'not_found' };
      setRestoringId(versionId);
      try {
        const url = `${buildVersionsBaseUrl(templateIdentifier, target)}/${versionId}/restore`;
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
        });
        const body = await response.json().catch(() => null);
        if (response.status === 404) return { kind: 'not_found' };
        if (!response.ok) {
          return {
            kind: 'network_error',
            message: (body as { message?: string })?.message ?? `HTTP ${response.status}`,
          };
        }
        const newVersion =
          typeof (body as { data?: { version?: unknown } })?.data?.version === 'number'
            ? ((body as { data: { version: number } }).data.version)
            : -1;
        // 복원 성공 — 호출자가 캔버스를 서버 최신(복원본)으로 재로드 + 트리 버전 배지 동기화.
        await onRestored?.(newVersion >= 0 ? newVersion : undefined);
        return { kind: 'success', newVersion };
      } catch (err: unknown) {
        return {
          kind: 'network_error',
          message: err instanceof Error ? err.message : 'network error',
        };
      } finally {
        setRestoringId(null);
      }
    },
    [templateIdentifier, target, onRestored],
  );

  const loadVersionDetail = useCallback(
    async (version: number): Promise<VersionDetailResult> => {
      if (!target) return { kind: 'not_found' };
      try {
        const url = `${buildVersionsBaseUrl(templateIdentifier, target)}/${version}`;
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: buildAuthHeaders(),
        });
        const body = await response.json().catch(() => null);
        if (response.status === 404) return { kind: 'not_found' };
        if (!response.ok) {
          return {
            kind: 'network_error',
            message: (body as { message?: string })?.message ?? `HTTP ${response.status}`,
          };
        }
        const data = (body as { data?: LayoutVersionDetail })?.data;
        if (!data) return { kind: 'not_found' };
        // 확장 버전 단건 응답은 content 원본을 JSON 문자열로 노출(LayoutExtensionVersionResource)
        // — 레이아웃의 full_content 와 동일 의미이므로 파싱해 채워 diff 비교 경로를 통일한다
        // 파싱 실패 시 full_content 미설정 → 분해 키 폴백(빈 비교).
        if (target.kind === 'extension' && !data.full_content) {
          const rawContent = (data as { content?: unknown }).content;
          if (typeof rawContent === 'string') {
            try {
              const parsed = JSON.parse(rawContent) as Record<string, unknown>;
              if (parsed && typeof parsed === 'object') data.full_content = parsed;
            } catch {
              // 손상 content — full_content 없이 진행(호출 측 폴백)
            }
          }
        }
        return { kind: 'success', detail: data };
      } catch (err: unknown) {
        return {
          kind: 'network_error',
          message: err instanceof Error ? err.message : 'network error',
        };
      }
    },
    [templateIdentifier, target],
  );

  return {
    versions,
    isLoading,
    isLoadingMore,
    error,
    restoringId,
    hasMore,
    loadVersions,
    loadMore,
    restore,
    loadVersionDetail,
  };
}
