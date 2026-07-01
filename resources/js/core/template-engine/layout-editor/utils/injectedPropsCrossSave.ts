/**
 * injectedPropsCrossSave.ts — inject_props 교차 저장
 *
 * 호스트 노드 속성 모달에서 편집한 "확장이 주입한 속성"을 그 확장 행
 * (`layout-extensions/{id}`)으로 교차 저장한다. 호스트 레이아웃이 아니라 확장으로 저장
 * 되므로 모듈/플러그인 업데이트 시 수정 감지·보존이 동작한다.
 *
 * 흐름:
 *  1. GET `/layout-extensions/{extensionId}` — 현재 content(JSON 문자열) + lock_version.
 *  2. content.injections 에서 `position==='inject_props' && target_id===hostNodeId` 항목의
 *     `props` 를 편집값으로 교체.
 *  3. PUT `/layout-extensions/{extensionId}` { content, expected_lock_version }.
 *
 * @since engine-v1.50.0
 */

import { buildAuthHeaders } from './authToken';

/** 교차 저장 결과 */
export type InjectedPropsSaveResult =
  | { kind: 'success'; newLockVersion: number }
  | { kind: 'not_found' }
  | { kind: 'injection_not_found' }
  | { kind: 'conflict'; currentVersion: number; yourVersion: number }
  | { kind: 'error'; message: string };

/**
 * 확장의 inject_props injection(대상 호스트 노드) props 를 교체해 저장한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param extensionId 확장 PK
 * @param hostNodeId inject_props 대상 호스트 노드 id (target_id)
 * @param nextProps 편집된 props
 * @param fetchImpl fetch 구현 (테스트 주입용, 기본 window.fetch)
 * @returns 저장 결과
 */
export async function saveInjectedPropsToExtension(
  templateIdentifier: string,
  extensionId: number,
  hostNodeId: string,
  nextProps: Record<string, unknown>,
  fetchImpl: typeof fetch = fetch,
): Promise<InjectedPropsSaveResult> {
  const baseUrl = `/api/admin/templates/${encodeURIComponent(
    templateIdentifier,
  )}/layout-extensions/${extensionId}`;

  // 1. 현재 확장 content 로드.
  let showRes: Response;
  try {
    showRes = await fetchImpl(baseUrl, {
      headers: buildAuthHeaders(),
      credentials: 'same-origin',
    });
  } catch (e) {
    return { kind: 'error', message: e instanceof Error ? e.message : String(e) };
  }
  if (showRes.status === 404) return { kind: 'not_found' };
  if (!showRes.ok) {
    return { kind: 'error', message: `HTTP ${showRes.status}` };
  }
  const showBody = await showRes.json().catch(() => null);
  const data = (showBody && (showBody.data || showBody)) ?? {};

  let content: Record<string, unknown> = {};
  const rawContent = (data as any).content;
  if (typeof rawContent === 'string') {
    try {
      content = JSON.parse(rawContent);
    } catch {
      return { kind: 'error', message: 'invalid extension content' };
    }
  } else if (rawContent && typeof rawContent === 'object') {
    content = rawContent;
  }
  const lockVersion =
    typeof (data as any).lock_version === 'number' ? (data as any).lock_version : 0;

  // 2. inject_props injection 의 props 교체.
  const injections = Array.isArray(content.injections) ? [...(content.injections as any[])] : [];
  const idx = injections.findIndex(
    (inj) => inj && inj.position === 'inject_props' && inj.target_id === hostNodeId,
  );
  if (idx < 0) return { kind: 'injection_not_found' };
  injections[idx] = { ...injections[idx], props: nextProps };
  const nextContent = { ...content, injections };

  // 3. PUT 저장.
  let putRes: Response;
  try {
    putRes = await fetchImpl(baseUrl, {
      method: 'PUT',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      credentials: 'same-origin',
      body: JSON.stringify({
        content: nextContent,
        expected_lock_version: lockVersion,
      }),
    });
  } catch (e) {
    return { kind: 'error', message: e instanceof Error ? e.message : String(e) };
  }

  const putBody = await putRes.json().catch(() => null);
  if (putRes.ok) {
    const newLockVersion =
      typeof (putBody as any)?.data?.lock_version === 'number'
        ? (putBody as any).data.lock_version
        : lockVersion + 1;
    return { kind: 'success', newLockVersion };
  }
  if (putRes.status === 409) {
    return {
      kind: 'conflict',
      currentVersion: (putBody as any)?.current_version ?? -1,
      yourVersion: (putBody as any)?.your_version ?? lockVersion,
    };
  }
  return { kind: 'error', message: (putBody as any)?.message ?? `HTTP ${putRes.status}` };
}
