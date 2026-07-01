/**
 * customTranslations.ts — 커스텀 다국어 키 관리 API 클라이언트.
 *
 * `template_custom_translations` 백엔드(S8-1 완비)에 대한 list/update/delete/
 * bulkDelete 호출을 단일 진실 공급원화한다. 관리 모달(CustomTranslationManager)이
 * 본 클라이언트를 쓴다. 인라인 편집(useInlineEdit)의 create/find 헬퍼와는 책임이
 * 분리된다(생성은 인라인 편집, 관리·정리는 본 모달).
 *
 * 모든 요청은 `buildAuthHeaders` 로 `Authorization: Bearer` 를 첨부한다 —
 * 권한 가드(core.templates.layouts.edit) 엔드포인트 fetch.
 *
 * 엔드포인트:
 *  - `GET    /api/admin/templates/{id}/custom-translations?layout_name=&status=` → index
 *  - `PUT    /api/admin/templates/{id}/custom-translations/{rowId}`              → update (409 충돌)
 *  - `DELETE /api/admin/templates/{id}/custom-translations/{rowId}`              → destroy
 *  - `DELETE /api/admin/templates/{id}/custom-translations` (body: ids[])        → bulkDestroy
 *
 * @since engine-v1.50.0
 */

import { buildAuthHeaders } from './authToken';

/** 커스텀 다국어 키 1건 — 백엔드 TemplateCustomTranslationResource 필드 */
export interface CustomTranslation {
  id: number;
  template_id: number;
  layout_name: string | null;
  translation_key: string;
  values: Record<string, string>;
  status: 'active' | 'orphaned';
  lock_version: number;
  created_at?: string;
  updated_at?: string;
}

/** 커스텀 다국어 키 API 결과 — 호출자가 분기 처리 */
export type CustomTranslationResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; message: string };

function encodeId(value: string): string {
  return encodeURIComponent(value);
}

/**
 * 현재 레이아웃의 커스텀 다국어 키 목록 조회.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param layoutName 레이아웃 이름 필터 (옵션 — 미지정 시 템플릿 전체)
 * @param status 상태 필터 (active|orphaned, 옵션)
 * @return 커스텀 키 배열 또는 에러
 */
export async function listCustomTranslations(
  templateIdentifier: string,
  layoutName?: string,
  status?: 'active' | 'orphaned',
): Promise<CustomTranslationResult<CustomTranslation[]>> {
  const params = new URLSearchParams();
  if (layoutName) params.set('layout_name', layoutName);
  if (status) params.set('status', status);
  const qs = params.toString();
  const url =
    `/api/admin/templates/${encodeId(templateIdentifier)}/custom-translations` +
    (qs ? `?${qs}` : '');
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: buildAuthHeaders(),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body?.success) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    const data = Array.isArray(body?.data) ? (body.data as CustomTranslation[]) : [];
    return { ok: true, data };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 커스텀 다국어 키의 로케일별 값 수정 (낙관적 잠금).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param id 커스텀 키 ID
 * @param values 로케일별 번역 값
 * @param expectedLockVersion 편집기가 보유한 lock_version
 * @return 수정된 키 또는 에러 (409 충돌 시 status=409)
 */
export async function updateCustomTranslation(
  templateIdentifier: string,
  id: number,
  values: Record<string, string>,
  expectedLockVersion: number,
): Promise<CustomTranslationResult<CustomTranslation>> {
  const url = `/api/admin/templates/${encodeId(templateIdentifier)}/custom-translations/${id}`;
  try {
    const res = await fetch(url, {
      method: 'PUT',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ values, expected_lock_version: expectedLockVersion }),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body?.success || !body?.data) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    return { ok: true, data: body.data as CustomTranslation };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 커스텀 다국어 키 1건 삭제.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param id 커스텀 키 ID
 * @return 성공 여부
 */
export async function deleteCustomTranslation(
  templateIdentifier: string,
  id: number,
): Promise<CustomTranslationResult<true>> {
  const url = `/api/admin/templates/${encodeId(templateIdentifier)}/custom-translations/${id}`;
  try {
    const res = await fetch(url, {
      method: 'DELETE',
      headers: buildAuthHeaders(),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || (body && body.success === false)) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    return { ok: true, data: true };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 커스텀 다국어 키 일괄 삭제 (관리 모달 "선택 삭제"/"미사용 전체 삭제").
 *
 * @param templateIdentifier 템플릿 식별자
 * @param ids 삭제할 커스텀 키 ID 목록
 * @return 삭제된 건수 또는 에러
 */
export async function bulkDeleteCustomTranslations(
  templateIdentifier: string,
  ids: number[],
): Promise<CustomTranslationResult<{ deleted: number }>> {
  const url = `/api/admin/templates/${encodeId(templateIdentifier)}/custom-translations`;
  try {
    const res = await fetch(url, {
      method: 'DELETE',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ ids }),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body?.success) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    const deleted = typeof body?.data?.deleted === 'number' ? body.data.deleted : ids.length;
    return { ok: true, data: { deleted } };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

const CUSTOM_KEY_RE = /\$t:(custom\.[A-Za-z0-9_\-]+\.\d+)/g;

/**
 * 레이아웃 content 에서 참조되는 커스텀 키 집합을 수집한다 (백엔드
 * CustomTranslationUsageScanner 의 TS 트윈). 관리 모달이 **현재 편집 중인**
 * 캔버스를 실시간 스캔해 "사용중/미사용(현재 캔버스)" 를 저장된 status 와
 * 별개로 표시하는 데 쓴다(저장 전이라도 정확). 노드 text·props·표현식 전체
 * 문자열을 보수적으로 순회한다.
 *
 * @param content 레이아웃 content (raw — 배열/객체/문자열 혼재 가능)
 * @return 참조된 커스텀 키 Set (예: 'custom.home.1')
 */
export function collectReferencedCustomKeys(content: unknown): Set<string> {
  const keys = new Set<string>();
  const walk = (value: unknown): void => {
    if (typeof value === 'string') {
      if (value.includes('$t:custom.')) {
        let m: RegExpExecArray | null;
        CUSTOM_KEY_RE.lastIndex = 0;
        while ((m = CUSTOM_KEY_RE.exec(value)) !== null) keys.add(m[1]);
      }
      return;
    }
    if (Array.isArray(value)) {
      for (const item of value) walk(item);
      return;
    }
    if (value && typeof value === 'object') {
      for (const v of Object.values(value as Record<string, unknown>)) walk(v);
    }
  };
  walk(content);
  return keys;
}

function messageOf(body: unknown, status: number): string {
  if (body && typeof body === 'object' && 'message' in body) {
    const m = (body as { message?: unknown }).message;
    if (typeof m === 'string' && m.length > 0) return m;
  }
  return `HTTP ${status}`;
}
