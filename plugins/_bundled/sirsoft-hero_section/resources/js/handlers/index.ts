import { apiUrl, routePath } from '../apiUrl';

/** 대상 저장 완료는 await 뒤의 현재 주소·상태를 읽는다. 캡처된 query/_local로 다른 대상을 덮지 않는다. */
type Overrides = Record<string, unknown>;
type Completion = { position: string; target: string; requestId: string; submitted?: Overrides; response?: { message?: string; data?: { position?: string; target?: { key?: string }; overrides?: Overrides } }; error?: { message?: string } };
type Mutation = { position: string; target: string; method: 'PUT' | 'DELETE'; overrides?: Overrides };
type Response = NonNullable<Completion['response']>;
type Core = {
  api: { put(url: string, body: unknown): Promise<Response>; delete(url: string): Promise<Response> };
  t(key: string): string;
  state: { getLocal(): { targetSaving?: { key: string; id: string } | null; targetForm?: { key: string; overrides: Overrides } | null }; setLocal(update: Record<string, unknown>, options?: { merge: 'shallow' }): void };
  dataSource: { set(id: string, data: unknown): void };
  getActionDispatcher(): { dispatchAction(action: unknown): Promise<unknown> };
};

/**
 * PUT/DELETE 성공·실패의 공통 완료 처리. 저장 중 다른 대상으로 이동했다면 그 화면의 응답·입력·피드백은 건드리지 않는다.
 * 같은 대상에서도 저장 요청 뒤 바꾼 입력은 지우지 않는다. 서버 응답은 GET과 같은 모양으로 바로 반영한다.
 * @param action 요청 시점의 위치·대상·제출값과 응답
 * @returns 현재 대상에 반영했으면 true
 */
export async function finishTargetSettings(action: { params?: Completion }): Promise<boolean> {
  const params = action.params;
  const core = (window as unknown as { G7Core?: Core }).G7Core;
  if (!params || !core) return false;
  const key = `${params.position}|${params.target}`;
  const local = core.state.getLocal();
  // 같은 URL로 돌아와 새 저장을 시작한 경우도 이전 응답과 구분한다.
  if (local.targetSaving?.key !== key || local.targetSaving.id !== params.requestId) return false;
  const update: Record<string, unknown> = { targetSaving: null };
  const query = new URLSearchParams(window.location.search);
  const current = routePath(window.location.pathname) === '/admin/plugins/sirsoft-hero_section/banners' && (query.get('position') || 'home_hero') === params.position && (query.get('target') || '') === params.target;
  if (!current) {
    if (Object.keys(update).length) core.state.setLocal(update, { merge: 'shallow' });
    return false;
  }
  const response = params.response;
  if (response?.data?.position === params.position && String(response.data.target?.key ?? '') === params.target) {
    core.dataSource.set('target_settings', response);
    if (local.targetForm?.key === key && JSON.stringify(local.targetForm.overrides) === JSON.stringify(params.submitted ?? {})) update.targetForm = null;
  }
  if (Object.keys(update).length) core.state.setLocal(update, { merge: 'shallow' });
  const message = params.error?.message ?? response?.message;
  if (message) await core.getActionDispatcher().dispatchAction({ type: 'click', handler: 'toast', params: { type: params.error ? 'error' : 'success', message } });
  return true;
}

/**
 * 요청 시작 때 위치·대상·제출값을 복사한다. 레이아웃의 onSuccess 바인딩은 응답 전에 이동하면 새 화면의 computed를 볼 수 있다.
 * 토큰 부착·인증 오류는 코어 API 클라이언트가 담당한다.
 * @param action 현재 화면에서 고른 대상과 재정의
 * @returns 현재 요청을 처리했으면 true
 */
export async function saveTargetSettings(action: { params?: Mutation }): Promise<boolean> {
  const core = (window as unknown as { G7Core?: Core }).G7Core;
  if (!core || !action.params || core.state.getLocal().targetSaving) return false;
  const position = String(action.params.position);
  const target = String(action.params.target);
  const method = action.params.method;
  const submitted = { ...(action.params.overrides ?? {}) };
  // randomUUID 는 HTTPS·localhost 에만 있다 — HTTP 로 연 관리자에서도 요청마다 다른 값이면 충분하다
  const requestId = globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  core.state.setLocal({ targetSaving: { key: `${position}|${target}`, id: requestId } }, { merge: 'shallow' });
  const endpoint = apiUrl(`/api/plugins/sirsoft-hero_section/admin/position-settings/${encodeURIComponent(position)}/targets/${encodeURIComponent(target)}`);
  const snapshot = { position, target, requestId, submitted };
  try {
    const response = method === 'DELETE' ? await core.api.delete(endpoint) : await core.api.put(endpoint, { overrides: submitted });
    return await finishTargetSettings({ params: { ...snapshot, response } });
  } catch (error) {
    const failure = error as { response?: { data?: { message?: string } }; message?: string };
    const message = failure?.response?.data?.message || failure?.message || core.t('core.errors.api_request_failed');
    return await finishTargetSettings({ params: { ...snapshot, error: { message } } });
  }
}

export const handlerMap = {
  saveTargetSettings: saveTargetSettings,
};
