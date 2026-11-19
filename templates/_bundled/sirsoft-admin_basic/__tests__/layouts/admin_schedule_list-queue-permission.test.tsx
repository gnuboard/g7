/**
 * @file admin_schedule_list-queue-permission.test.tsx
 * @description 스케줄 관리 「큐 모니터링」 탭 — 큐 작업 조회 권한(core.queue_jobs.read)이 없는 관리자에게 탭·요청·빈 안내가 사실과 맞다
 *
 * 결함: 「스케줄 조회」만 있고 「큐 작업 조회」가 없는 관리자에게 큐 탭이 그대로 보였고, 열면 `queue/status`·`queue/pending` 이
 * 403 인데 화면은 권한 안내 없이 「측정 불가 / 대기 중인 작업이 없습니다.」로 그렸다(실제 대기는 4건). 스케줄 탭에서도 레이아웃이
 * `queue_status` 를 늘 불러 화면마다 403 과 콘솔 오류가 났다. 레이아웃 권한은 `core.schedules.read` 하나뿐이고, 큐 탭 항목과
 * `queue_*` 데이터소스에 큐 조회 권한 조건이 없었으며, 받기 실패(null)를 「0건」과 같은 빈 안내로 그렸다.
 *
 * 고침:
 *  - 베이스(`_admin_base`)가 로그인 관리자(current_user)를 받으면 큐 작업 조회 권한 보유를 전역 `adminQueueJobsReadable` 에 올린다
 *    (알림 조회 판정 `adminNotificationsReadable` 과 같은 자리·같은 방식). 로그인 화면은 지난 판정을 비운다.
 *  - 판정이 거짓이면 큐 탭 항목을 숨기고, 큐 주소로 직접 들어오면 본문 대신 권한 안내를 보이며, `queue_*` 를 부르지 않는다
 *    (데이터소스 조건 문맥에는 사용자가 없어 전역 판정을 쓴다). 판정 전(새 탭 첫 화면)에 나간 요청의 403 은 조용히 넘긴다(suppress).
 *  - 받기 실패(`_dataSourceErrors`)면 표의 빈 문구 자리에 「권한 없음」(403)·「불러오지 못함」을, 현황 카드 대신 실패 안내를 보인다.
 *
 * 렌더 방식: 배포 JSON(`admin_schedule_list.json` 의 탭 바·탭 본문, 조각은 배포 JSON 을 그대로 끼움)을 실제 엔진 하네스(createLayoutTest)로
 * 그린다. 데이터소스는 엔진의 조건 판정(DataSourceManager.filterByCondition, TemplateApp 과 같은 문맥 모양)으로 고른 것만 하네스가 받는다.
 * TabNavigation 자리에는 엔진이 넘기는 props 를 받는 수집기를 두고, 받은 props 로 **실제 TabNavigation** 을 따로 그려 보이는 탭을 읽는다
 * (하네스 렌더러와 템플릿 React 사본이 갈리는 환경 — admin_schedule_list-delete-blocked-reason.test.tsx 와 같은 사정).
 *
 * 시나리오 매니페스트: queue-management (tests/scenarios — test_files 귀속)
 * @axes case=queue_permission
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { ErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';
import { TabNavigation } from '../../src/components/composite/TabNavigation';

const mainLayout = require('../../layouts/admin_schedule_list.json');
const adminBase = require('../../layouts/_admin_base.json');
const adminLogin = require('../../layouts/admin_login.json');
const koAdmin = require('../../lang/partial/ko/admin.json');
const enAdmin = require('../../lang/partial/en/admin.json');
const koCommon = require('../../lang/partial/ko/common.json');
const koAuth = require('../../lang/partial/ko/auth.json');

const QUEUE_PATH = '/admin/schedules/queue';
const SCHEDULE_PATH = '/admin/schedules';
const QUEUE_SOURCES = ['queue_status', 'queue_pending', 'queue_failed', 'queue_logs'];

const TEXT = {
    queueTab: koAdmin.schedules.tabs.queue,
    emptyPending: koAdmin.schedules.queue.empty.pending,
    unmeasurable: koAdmin.schedules.queue.status.unmeasurable,
    noPermission: koAdmin.schedules.queue.notice?.no_permission,
    listFailed: koAdmin.schedules.queue.errors?.list_load_failed,
    statusFailed: koAdmin.schedules.queue.errors?.status_load_failed,
};

/** 객체 트리에서 id 가 같은 노드 하나를 찾습니다. */
function byId(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = byId(child, id);
            if (found) return found;
        }
        return null;
    }
    if (node.id === id) return node;
    for (const value of Object.values(node)) {
        const found = byId(value, id);
        if (found) return found;
    }
    return null;
}

/**
 * `{ partial: 'partials/…json' }` 자리를 배포된 조각 JSON 으로 바꿉니다(서버의 조각 병합과 같은 결과).
 *
 * @param node 컴포넌트 노드
 * @returns 조각을 끼운 노드
 */
function inlinePartials(node: any): any {
    if (Array.isArray(node)) return node.map(inlinePartials);
    if (!node || typeof node !== 'object') return node;
    if (typeof node.partial === 'string') {
        const { meta, layout_name, comment, ...root } = require(`../../layouts/${node.partial}`);
        return inlinePartials(root);
    }
    const out: Record<string, any> = {};
    for (const [key, value] of Object.entries(node)) out[key] = inlinePartials(value);
    return out;
}

/** 관리자 본인 응답(_admin_base 의 current_user) — permissions 는 식별자 목록 */
function currentUser(permissions: string[]): any {
    return { success: true, data: { uuid: 'u-1', permissions: permissions.map((identifier, i) => ({ id: i + 1, identifier })) } };
}

const SCHEDULE_ONLY = currentUser(['core.schedules.read']);
const WITH_QUEUE = currentUser(['core.schedules.read', 'core.queue_jobs.read']);
/** 베이스가 current_user 로 정한 판정(전역) */
const NO_QUEUE = { adminQueueJobsReadable: false };
const HAS_QUEUE = { adminQueueJobsReadable: true };

/**
 * 베이스 current_user 의 성공 처리기를 실제 엔진 dispatcher 로 엔진과 같은 문맥(`response = { data: 본문, sourceId }`)에서 돌려,
 * 쓴 전역 값을 돌려줍니다(admin-base-notification-permission.test.ts 와 같은 방식).
 *
 * @param handlers 성공 처리기 목록
 * @param body 응답 본문
 * @returns 전역 쓰기 결과
 */
async function runBaseSuccess(handlers: any[], body: any): Promise<Record<string, any>> {
    const global: Record<string, any> = {};
    const saved = (window as any).G7Core;
    (window as any).G7Core = { dataSource: { refetch: async () => undefined }, state: { get: () => ({ ...global }), getLocal: () => ({}) } };
    try {
        const dispatcher = new ActionDispatcher({ navigate: () => {} } as any);
        dispatcher.setGlobalStateUpdater?.((updates: any) => Object.assign(global, updates));
        for (const handler of handlers) {
            await dispatcher.dispatchAction({ type: 'click', ...handler } as any, { data: { response: { data: body, sourceId: 'current_user' } } } as any);
        }
    } finally {
        (window as any).G7Core = saved;
    }

    return global;
}

/**
 * 엔진이 고르는 데이터소스 id — TemplateApp 진입·navigate 재평가와 같은 문맥 모양(`{route: 라우트 파라미터, query, _global}`).
 *
 * @param query 쿼리
 * @param global 전역 상태
 * @returns 골라진 데이터소스 id 목록
 */
function selectedIds(query: Record<string, string>, global: Record<string, any>): string[] {
    return new DataSourceManager().filterByCondition(mainLayout.data_sources ?? [], { route: {}, query, _global: global } as any).map((d: any) => d.id);
}

interface Screen {
    routePath: string;
    query?: Record<string, string>;
    /** current_user 값 — undefined 면 받기 전 */
    user?: any;
    /** 진입 전 전역 상태(앞 화면에서 정한 판정 등) */
    global?: Record<string, any>;
    /** 데이터소스 id → 응답 본문 */
    responses?: Record<string, any>;
    /** 데이터소스 id → 실패 상태 코드 */
    failures?: Record<string, number>;
}

interface Rendered {
    harness: ReturnType<typeof createLayoutTest>;
    container: HTMLElement;
    /** 엔진이 탭 바(TabNavigation)에 넘긴 props */
    tabProps: any;
    /** 엔진이 넘긴 대기 표(DataGrid) props — 표를 그리지 않았으면 null */
    pendingGrid: any;
    /** 하네스가 받기를 시도한 데이터소스 */
    fetched: string[];
}

const pageList = (rows: any[]) => ({ data: { data: rows, pagination: { current_page: 1, last_page: 1, total: rows.length, has_more_pages: false } } });

const DEFAULT_RESPONSES: Record<string, any> = {
    schedules: { data: { data: [], abilities: {}, pagination: { current_page: 1, last_page: 1, total: 0 } } },
    schedule_owners: { data: [] },
    schedule_status: { data: { heartbeat: { healthy: true }, queue: { pending_count: 4, failed_count: 0 } } },
    queue_status: { data: { driver: 'database', connection: 'database', measurable: true, pending_count: 4, failed_count: 0, workers: { alive: 1, registered: 2 }, entries: [], connections: ['database'] } },
    queue_pending: { ...pageList([]), meta: { measurable: true } },
    queue_failed: pageList([]),
    queue_logs: pageList([]),
};

/**
 * 스케줄 관리 화면의 탭 바와 탭 본문을 실제 엔진 하네스로 그립니다.
 *
 * @param s 화면 조건
 * @returns 렌더 결과
 */
async function renderScreen(s: Screen): Promise<Rendered> {
    const query = s.query ?? {};
    const global = { ...(s.global ?? {}) };
    const captured: { tabs: any; pending: any } = { tabs: null, pending: null };

    const registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'TabNavigation', (props: any) => {
        if (props['data-testid'] === undefined && Array.isArray(props.tabs) && props.tabs.some((t: any) => t.id === 'queue')) captured.tabs = props;
        return null;
    });
    registry.register('composite', 'DataGrid', (props: any) => {
        if (props.id === 'queue_pending_grid' || props['data-ref'] === 'queue_pending_grid') captured.pending = props;
        const rows: any[] = Array.isArray(props.data) ? props.data : [];
        return React.createElement('div', { 'data-testid': 'grid' }, rows.length > 0 ? `${rows.length} rows` : (props.emptyMessage ?? ''));
    });

    const content = inlinePartials(JSON.parse(JSON.stringify(mainLayout.slots.content)));
    const sources = new DataSourceManager()
        .filterByCondition(mainLayout.data_sources ?? [], { route: {}, query, _global: global } as any)
        .filter((d: any) => d.id !== 'current_user');

    const probe = {
        version: '1.0.0',
        layout_name: 'admin_schedule_list_queue_permission_probe',
        computed: mainLayout.computed,
        init_actions: mainLayout.init_actions,
        data_sources: sources,
        components: [byId(content, 'tab_navigation_wrapper'), byId(content, 'schedule_tabs_content')],
    };

    const harness = createLayoutTest(probe as any, {
        componentRegistry: registry as any,
        translations: { admin: koAdmin, common: koCommon, auth: koAuth },
        locale: 'ko',
        routePath: s.routePath,
        queryParams: query,
        initialState: { _global: global },
        initialData: { current_user: s.user },
    });
    const responses = { ...DEFAULT_RESPONSES, ...(s.responses ?? {}) };
    for (const ds of sources) {
        const status = s.failures?.[ds.id];
        if (status) harness.mockApiError(ds.id, status, `failed ${status}`);
        else harness.mockApi(ds.id, { response: responses[ds.id] ?? { data: {} } });
    }
    const { container } = await harness.render();

    // 하네스가 ID 로 찾는 수집기와 별도로, 대기 표 노드를 그렸는지는 DataGrid 자리 렌더로 안다
    return { harness, container, tabProps: captured.tabs, pendingGrid: captured.pending, fetched: sources.map((d: any) => d.id) };
}

/**
 * 엔진이 넘긴 탭 props 로 실제 TabNavigation 을 그려, 화면에 보이는 탭 이름을 돌려줍니다.
 *
 * @param props 엔진이 넘긴 props
 * @returns 보이는 탭 이름 목록
 */
function visibleTabs(props: any): string[] {
    expect(props, '엔진이 탭 바에 props 를 넘긴다').toBeTruthy();
    const { container } = render(<TabNavigation tabs={props.tabs} activeTabId={props.activeTabId} onTabChange={() => {}} />);
    const labels = Array.from(container.querySelectorAll('[role="tab"]')).map((el) => (el.textContent ?? '').trim());
    cleanup();
    return labels;
}

describe('큐 작업 조회 권한이 없는 관리자 — 큐 탭·요청·빈 안내', () => {
    let current: Rendered | undefined;

    afterEach(() => {
        cleanup();
        current?.harness.cleanup();
        current = undefined;
    });

    // @scenario case=queue_permission
    // @effects queue_tab_hidden_without_queue_permission
    it('새 문구 키가 ko·en 둘 다 있다', () => {
        for (const dict of [koAdmin, enAdmin]) {
            expect(dict.schedules.queue.notice?.no_permission, 'notice.no_permission').toBeTruthy();
            expect(dict.schedules.queue.errors?.list_load_failed, 'errors.list_load_failed').toBeTruthy();
            expect(dict.schedules.queue.errors?.status_load_failed, 'errors.status_load_failed').toBeTruthy();
        }
    });

    // @scenario case=queue_permission
    // @effects queue_sources_not_requested_without_queue_permission
    it('판정이 거짓이면 스케줄 탭·큐 탭 어디서도 queue_* 를 부르지 않고, 판정 전·참이면 종전대로 부른다', () => {
        const denied = { adminQueueJobsReadable: false };
        expect(selectedIds({}, denied).filter((id) => id.startsWith('queue_'))).toEqual([]);
        for (const view of ['pending', 'failed', 'logs']) {
            expect(selectedIds({ view }, denied).filter((id) => id.startsWith('queue_')), view).toEqual([]);
        }
        expect(selectedIds({}, {})).toContain('queue_status');
        expect(selectedIds({ view: 'pending' }, {})).toEqual(expect.arrayContaining(['queue_status', 'queue_pending']));
        expect(selectedIds({ view: 'failed' }, { adminQueueJobsReadable: true })).toEqual(expect.arrayContaining(['queue_status', 'queue_failed']));
        // 스케줄 데이터소스는 판정과 무관하다
        expect(selectedIds({}, denied)).toEqual(expect.arrayContaining(['schedules', 'schedule_owners', 'schedule_status']));
    });

    // @scenario case=queue_permission
    // @effects queue_sources_not_requested_without_queue_permission
    it('판정 전에 나간 queue_* 요청의 403 은 조용히 넘긴다(전역 오류·콘솔 오류 없음)', () => {
        const resolver = new ErrorHandlingResolver();
        for (const id of QUEUE_SOURCES) {
            const ds = (mainLayout.data_sources ?? []).find((d: any) => d.id === id);
            expect(resolver.resolve(403, { errorHandling: ds?.errorHandling, onError: ds?.onError }).handler?.handler, id).toBe('suppress');
        }
    });

    // @scenario case=queue_permission
    // @effects queue_tab_hidden_without_queue_permission
    it('베이스가 로그인 관리자를 받으면 큐 작업 조회 권한 보유를 전역에 올리고, 로그인 화면은 그 판정을 비운다', async () => {
        const onSuccess = (adminBase.data_sources ?? []).find((d: any) => d.id === 'current_user')?.onSuccess ?? [];
        expect(await runBaseSuccess(onSuccess, SCHEDULE_ONLY)).toMatchObject({ adminQueueJobsReadable: false });
        expect(await runBaseSuccess(onSuccess, WITH_QUEUE)).toMatchObject({ adminQueueJobsReadable: true });
        const reset = (adminLogin.init_actions ?? []).find((a: any) => a?.handler === 'setState' && a?.params && 'adminQueueJobsReadable' in a.params);
        expect(reset?.params?.adminQueueJobsReadable, '로그인 화면 init 이 판정을 비운다').toBeNull();
    });

    // @scenario case=queue_permission
    // @effects queue_tab_hidden_without_queue_permission
    it('스케줄 탭: 판정이 거짓이면 큐 탭 항목이 보이지 않는다', async () => {
        current = await renderScreen({ routePath: SCHEDULE_PATH, user: SCHEDULE_ONLY, global: NO_QUEUE });
        expect(visibleTabs(current.tabProps)).toEqual([koAdmin.schedules.tabs.schedules]);
        expect(current.fetched.filter((id) => id.startsWith('queue_'))).toEqual([]);
    });

    // @scenario case=queue_permission
    // @effects queue_tab_direct_entry_shows_permission_notice
    it('큐 주소로 직접 들어오면 권한 안내가 보이고 대기 표·「없습니다」·「측정 불가」는 그리지 않는다', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: SCHEDULE_ONLY, global: NO_QUEUE });
        expect(current.fetched.filter((id) => id.startsWith('queue_'))).toEqual([]);
        const text = current.container.textContent ?? '';
        expect(TEXT.noPermission).toBeTruthy();
        expect(text).toContain(TEXT.noPermission);
        expect(current.pendingGrid).toBeNull();
        expect(text).not.toContain(TEXT.emptyPending);
        expect(text).not.toContain(TEXT.unmeasurable);
    });
});

describe('큐 작업 조회 권한이 있는 관리자 — 종전 화면과 받기 실패 안내', () => {
    let current: Rendered | undefined;

    afterEach(() => {
        cleanup();
        current?.harness.cleanup();
        current = undefined;
    });

    // @scenario case=queue_permission
    // @effects queue_tab_hidden_without_queue_permission
    it('큐 탭 항목이 보이고, 대기 0건이면 「대기 중인 작업이 없습니다.」, 권한 안내는 없다', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: WITH_QUEUE, global: HAS_QUEUE });
        expect(visibleTabs(current.tabProps)).toContain(TEXT.queueTab);
        expect(current.fetched).toEqual(expect.arrayContaining(['queue_status', 'queue_pending']));
        expect(current.pendingGrid?.emptyMessage).toBe(TEXT.emptyPending);
        expect(current.container.textContent ?? '').not.toContain(TEXT.noPermission ?? '권한 안내 키 없음');
    });

    // @scenario case=queue_permission
    // @effects queue_tab_hidden_without_queue_permission
    it('관리자 정보를 받기 전에는 큐 탭을 숨기지 않고 권한 안내도 내지 않는다(판정 전)', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: undefined });
        expect(visibleTabs(current.tabProps)).toContain(TEXT.queueTab);
        expect(current.container.textContent ?? '').not.toContain(TEXT.noPermission ?? '권한 안내 키 없음');
    });

    // @scenario case=queue_permission
    // @effects queue_load_failure_not_shown_as_empty
    it('대기 목록 받기가 실패하면(500) 빈 문구 자리에 「불러오지 못했습니다」를 보이고 「없습니다」는 말하지 않는다', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: WITH_QUEUE, global: HAS_QUEUE, failures: { queue_pending: 500 } });
        expect(TEXT.listFailed).toBeTruthy();
        expect(current.pendingGrid?.emptyMessage).toBe(TEXT.listFailed);
        expect(current.container.textContent ?? '').not.toContain(TEXT.emptyPending);
    });

    // @scenario case=queue_permission
    // @effects queue_load_failure_not_shown_as_empty
    it('대기 목록이 403 이면(판정 전에 나간 요청) 빈 문구 자리에 권한 안내를 보인다', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: undefined, failures: { queue_pending: 403 } });
        expect(current.pendingGrid?.emptyMessage).toBe(TEXT.noPermission);
    });

    // @scenario case=queue_permission
    // @effects queue_load_failure_not_shown_as_empty
    it('큐 현황 받기가 실패하면 현황 카드(측정 불가·0개·0/0) 대신 실패 안내를 보인다', async () => {
        current = await renderScreen({ routePath: QUEUE_PATH, query: { view: 'pending' }, user: WITH_QUEUE, global: HAS_QUEUE, failures: { queue_status: 500 } });
        const text = current.container.textContent ?? '';
        expect(TEXT.statusFailed).toBeTruthy();
        expect(text).toContain(TEXT.statusFailed);
        expect(current.container.querySelector('[data-ref="queue_status_cards"], #queue_status_cards')).toBeNull();
        expect(text).not.toContain(TEXT.unmeasurable);
    });
});
