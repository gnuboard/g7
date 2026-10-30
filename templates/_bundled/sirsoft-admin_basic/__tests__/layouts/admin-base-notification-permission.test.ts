/**
 * @file admin-base-notification-permission.test.ts
 * @description 알림 조회 권한이 없는 관리자에게 머리 알림 요청을 되풀이해 보내지 않는다
 *
 * 결함: `_admin_base` 의 알림 목록(`/api/admin/notifications`)과 안 읽은 수(`/unread-count`) 데이터소스가 화면마다
 * 무조건 받기를 해서, 알림 조회 권한(`core.notifications.read`)이 없는 관리자(SSO 운영자 등 확장 역할만 가진 계정)는
 * 화면을 옮길 때마다(같은 화면의 검색·쪽 넘김 포함) 두 요청이 403 으로 실패했다(콘솔 오류 반복). 머리의 종 아이콘도 그대로 떠 있었다.
 *
 * 고침: 로그인 관리자(`current_user`)를 받으면 그 권한 목록으로 알림 조회 권한 보유를 판정해 전역 `adminNotificationsReadable`
 * 에 올린다. 판정이 거짓이면 두 소스를 화면에 두지 않고(데이터소스 `if`), 실시간 알림 수신도 다시 받지 않으며, 종 아이콘
 * (데스크톱·모바일)을 그리지 않는다. 판정 전(새로 연 탭의 첫 화면)은 미정이라 종전대로 받는다. 로그인 화면은 지난 판정을 비운다.
 * `auto_fetch:false` 로 미루는 방식은 쓰지 않는다 — 받지 않은 소스가 undefined 로 남아 소스를 지정하지 않은
 * `blur_until_loaded` 화면이 흐린 채 멈춘다(마지막 단언).
 *
 * 첫 화면 결함: 판정은 로그인 관리자 응답이 와야 서므로 새로 연 탭·새로고침·주소 직접 진입의 첫 화면에서는 판정 전이라 두 요청이
 * 나간다. 두 소스에 오류 처리가 없어 403 이 시스템 기본(빨간 토스트 「해당 권한이 없습니다.」)으로 가, 권한 없는 관리자가 관리자
 * 화면을 새로 열 때마다 알림이 두 번 떴다. 이제 두 소스는 403 만 조용히 넘긴다(그 밖의 오류는 종전 처리 그대로). 판정 전 요청 자체를
 * 막으려면 데이터소스 사이 선후(다른 소스 도착 뒤 조건 재평가)가 필요해 레이아웃만으로는 없앨 수 없다.
 *
 * @scenario readable=unknown
 * @scenario readable=true
 * @scenario readable=false
 * @scenario readable=reset_null
 * @effects notifications_not_requested_without_permission, notifications_requested_with_permission, bell_hidden_without_permission, first_screen_forbidden_silent
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { evaluateRenderCondition } from '@core/template-engine/helpers/RenderHelpers';
import { ErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';

import adminBase from '../../layouts/_admin_base.json';
import adminLogin from '../../layouts/admin_login.json';

type Node = Record<string, any>;

const SOURCES = ['notifications', 'notification_unread_count'];

/**
 * 데이터소스 정의를 찾는다.
 *
 * @param id 데이터소스 id
 * @returns 정의
 */
function source(id: string): Node {
  const found = ((adminBase as any).data_sources ?? []).find((ds: Node) => ds.id === id);
  expect(found, `data source ${id}`).toBeDefined();

  return found;
}

/**
 * id 로 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 노드 또는 undefined
 */
function findById(node: unknown, id: string): Node | undefined {
  if (!node || typeof node !== 'object') return undefined;
  const v = node as Node;
  if (v.id === id) return v;
  for (const child of Object.values(v)) {
    const found = findById(child, id);
    if (found) return found;
  }

  return undefined;
}

/**
 * `/api/admin/auth/user` 응답 본문.
 *
 * @param permissions 보유 권한 식별자
 * @returns 응답 본문
 */
function userBody(permissions: string[]): Node {
  return { success: true, data: { uuid: 'u-1', permissions: permissions.map((identifier, i) => ({ id: i + 1, identifier })) } };
}

/**
 * 실제 엔진 dispatcher 로 데이터소스 성공 처리기를 엔진과 같은 문맥(`response = { data: 본문, sourceId }`)에서 돌리고,
 * 다시 받기를 요청한 소스 id 와 쓴 전역 값을 돌려준다.
 *
 * @param handlers 성공 처리기 목록
 * @param body 응답 본문
 * @param sourceId 소스 id
 * @returns 다시 받기를 요청한 소스 id 목록과 전역 쓰기 결과
 */
async function runSuccess(handlers: Node[], body: Node, sourceId: string): Promise<{ refetched: string[]; global: Node }> {
  const refetch = vi.fn().mockResolvedValue(undefined);
  const global: Node = {};
  (window as any).G7Core = { dataSource: { refetch }, state: { get: () => ({ ...global }), getLocal: () => ({}) } };
  const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
  dispatcher.setGlobalStateUpdater?.((updates: Node) => Object.assign(global, updates));
  for (const handler of handlers) {
    await dispatcher.dispatchAction({ type: 'click', ...handler } as any, { data: { response: { data: body, sourceId } } } as any);
  }

  return { refetched: refetch.mock.calls.map((call) => call[0]), global };
}

/**
 * 엔진이 화면에 둘 데이터소스 id — 레이아웃 진입 때 `filterByCondition` 이 `_global` 로 `if` 를 가른 결과.
 *
 * @param global 전역 상태
 * @returns 남은 데이터소스 id
 */
function screenSources(global: Node): string[] {
  return new DataSourceManager().filterByCondition((adminBase as any).data_sources, { route: {}, query: {}, _global: global } as any).map((ds: Node) => ds.id);
}

describe('알림 조회 권한이 없는 관리자의 머리 알림', () => {
  beforeEach(() => {
    delete (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  // @scenario readable=false
  // @effects notifications_not_requested_without_permission
  it('권한이 없다고 판정된 뒤의 화면에는 두 알림 소스를 두지 않는다(요청 0)', () => {
    const ids = screenSources({ adminNotificationsReadable: false });
    for (const id of SOURCES) expect(ids, id).not.toContain(id);
    expect(ids).toContain('current_user');
    expect(ids).toContain('admin_menu');
  });

  // @scenario readable=unknown
  // @effects notifications_requested_with_permission
  it.each([
    ['판정 전(새로 연 탭의 첫 화면)', {}],
    ['권한 있음', { adminNotificationsReadable: true }],
    ['로그인 화면이 비운 판정', { adminNotificationsReadable: null }],
  ])('%s 이면 종전대로 두 알림 소스를 스스로 받는다', (_label, global) => {
    const ids = screenSources(global as Node);
    for (const id of SOURCES) {
      expect(ids, id).toContain(id);
      expect(source(id).auto_fetch).toBe(true);
    }
  });

  // @scenario readable=true
  // @effects notifications_requested_with_permission
  it('로그인 관리자를 받으면 알림 조회 권한 보유를 전역에 올린다(있음)', async () => {
    const onSuccess = [].concat(source('current_user').onSuccess ?? []);
    expect(onSuccess.length).toBeGreaterThan(0);
    const { global } = await runSuccess(onSuccess, userBody(['core.notifications.read', 'core.notifications.update']), 'current_user');
    expect(global.adminNotificationsReadable).toBe(true);
  });

  // @scenario readable=false
  // @effects notifications_not_requested_without_permission
  it('로그인 관리자를 받으면 알림 조회 권한 보유를 전역에 올린다(없음 — SSO 운영자)', async () => {
    const onSuccess = [].concat(source('current_user').onSuccess ?? []);
    expect(onSuccess.length).toBeGreaterThan(0);
    const { refetched, global } = await runSuccess(onSuccess, userBody(['sirsoft-sso.accounts.read', 'core.notifications.update']), 'current_user');
    expect(global.adminNotificationsReadable).toBe(false);
    expect(refetched).toEqual([]);
  });

  // @scenario readable=false
  // @effects notifications_not_requested_without_permission
  it('실시간 알림을 받아도 권한이 없다고 판정됐으면 알림 소스를 다시 받지 않는다', () => {
    const onReceive = [].concat(source('notification_ws').onReceive ?? []) as Node[];
    const refetches = onReceive.filter((a) => a.handler === 'refetchDataSource');
    expect(refetches.map((a) => a.params?.dataSourceId).sort()).toEqual([...SOURCES].sort());
    const engine = new DataBindingEngine();
    for (const action of refetches) {
      expect(action.if, `${action.params?.dataSourceId} 다시 받기 조건`).toBeTruthy();
      expect(evaluateRenderCondition({ if: action.if }, { _global: { adminNotificationsReadable: false } } as any, engine)).toBe(false);
      expect(evaluateRenderCondition({ if: action.if }, { _global: { adminNotificationsReadable: true } } as any, engine)).toBe(true);
    }
  });

  // @scenario readable=false
  // @effects bell_hidden_without_permission
  it.each(['notification_center_desktop', 'notification_center_mobile'])('%s 는 권한이 없다고 판정되면 그리지 않는다', (id) => {
    const node = findById(adminBase, id);
    expect(node, id).toBeDefined();
    expect(node!.if, `${id} if`).toBeTruthy();
    const engine = new DataBindingEngine();
    expect(evaluateRenderCondition({ if: node!.if }, { _global: { adminNotificationsReadable: false } } as any, engine)).toBe(false);
    expect(evaluateRenderCondition({ if: node!.if }, { _global: { adminNotificationsReadable: true } } as any, engine)).toBe(true);
    // 판정 전(미정)에는 그린다 — 권한이 있는 대다수 관리자에게 종이 늦게 튀어나오지 않게
    expect(evaluateRenderCondition({ if: node!.if }, { _global: {} } as any, engine)).toBe(true);
  });

  // @scenario readable=reset_null
  // @effects notifications_requested_with_permission
  it('관리자 로그인 화면은 지난 관리자의 판정을 비운다(다른 관리자가 같은 탭에서 로그인)', () => {
    const resets = JSON.stringify((adminLogin as any).init_actions ?? []);
    expect(resets).toContain('"adminNotificationsReadable":null');
  });

  // @scenario readable=false
  // @effects notifications_not_requested_without_permission
  it('베이스의 어떤 소스도 auto_fetch:false 로 미루지 않는다(받지 않은 소스가 undefined 로 남아 흐림이 멈추지 않게)', () => {
    const deferred = ((adminBase as any).data_sources ?? []).filter((ds: Node) => ds.type !== 'websocket' && ds.auto_fetch === false).map((ds: Node) => ds.id);
    expect(deferred).toEqual([]);
  });
});

describe('알림 조회 권한이 없는 관리자가 관리자 화면을 새로 연 첫 화면', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  /**
   * 판정 전 첫 화면(`_global` 비어 있음)에 엔진이 고른 베이스 소스를 실제 `DataSourceManager.fetchDataSources` 로 받는다.
   * 알림 두 요청만 주어진 상태 코드로 실패시키고(나머지는 빈 성공), 엔진이 고른 오류 처리기를 실행 직전에 모은다.
   *
   * @param status 알림 두 요청의 응답 상태 코드
   * @returns 실행된 오류 처리기 이름 목록
   */
  async function firstScreenErrorHandlers(status: number): Promise<string[]> {
    const manager = new DataSourceManager();
    const sources = manager.filterByCondition((adminBase as any).data_sources, { route: {}, query: {}, _global: {} } as any);
    vi.spyOn(manager as any, 'fetchDataSource').mockImplementation(async (ds: any) => {
      if (SOURCES.includes(ds.id)) {
        throw { response: { status, data: { success: false, message: '해당 권한이 없습니다.' } } };
      }

      return { success: true, data: {} };
    });
    vi.spyOn(manager as any, 'executeOnSuccessHandler').mockResolvedValue(undefined);
    const executed: string[] = [];
    vi.spyOn(ErrorHandlingResolver.prototype, 'execute').mockImplementation(async (handler: any) => {
      executed.push(handler?.handler);

      return undefined as any;
    });
    await manager.fetchDataSources(sources as any, {}, new URLSearchParams(), {}, {});

    return executed;
  }

  // @scenario readable=unknown
  // @effects first_screen_forbidden_silent
  it('판정 전에 나간 두 알림 요청의 403 은 조용히 넘긴다(빨간 「해당 권한이 없습니다.」 토스트 0)', async () => {
    const executed = await firstScreenErrorHandlers(403);
    expect(executed).toHaveLength(SOURCES.length);
    expect(executed.filter((h) => h === 'toast'), '토스트로 간 처리기').toEqual([]);
    expect(executed).toEqual(SOURCES.map(() => 'suppress'));
  });

  // @scenario readable=unknown
  // @effects first_screen_forbidden_silent
  it.each([401, 404, 500])('403 이 아닌 오류(%i)는 종전 처리 그대로다(데이터소스에서 덮지 않는다)', (status) => {
    const resolver = new ErrorHandlingResolver();
    const unchanged = resolver.resolve(status, {});
    for (const id of SOURCES) {
      const ds = source(id);
      const resolved = resolver.resolve(status, { errorHandling: ds.errorHandling, onError: ds.onError });
      expect(resolved.level, `${id} ${status}`).toBe(unchanged.level);
      expect(resolved.handler, `${id} ${status}`).toEqual(unchanged.handler);
    }
  });

  // @scenario readable=unknown
  // @effects first_screen_forbidden_silent
  it('나머지 베이스 소스(메뉴·로그인 관리자·설치 확장)는 403 을 덮지 않는다(권한 미들웨어가 없는 경로라 403 이 날 일이 없다)', () => {
    const others = ((adminBase as any).data_sources ?? []).filter((ds: Node) => ds.type !== 'websocket' && !SOURCES.includes(ds.id));
    expect(others.map((ds: Node) => ds.id).sort()).toEqual(['admin_menu', 'current_user', 'installed_modules', 'installed_plugins']);
    for (const ds of others) expect(ds.errorHandling?.['403'], ds.id).toBeUndefined();
  });
});
