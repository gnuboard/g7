/**
 * @file admin_schedule_list-queue-tab.test.tsx
 * @description 스케줄 관리 > 큐 탭(7.2.0, #17 §5.7·D6·D7) — 하위 탭·필터·대기/실패/처리 기록 표·상세·재시도 확인 창의 레이아웃 계약
 *
 * 배경:
 *  - 큐 탭은 퍼블리싱만 되어 `:disabled` 였고 큐 관리 API 가 0건이었다. 7.2.0 은 K8 큐 API 로 대기·실패·처리 기록을
 *    보고 실패 작업을 건별·일괄(요청당 100건) 재시도·삭제한다.
 *  - 필터는 스케줄 탭과 같은 쿠폰 목록형 틀(D6) — 입력은 `_local.queueFilter` 에 쌓이고 검색 버튼·Enter 에서만 조회한다.
 *  - 하위 탭마다 받는 쿼리 키가 달라(대기는 기간·오류 검색 없음, 처리 기록만 결과 상태) 하위 탭을 바꾸면 조건을 비운다.
 *  메인 레이아웃 결선(include·모달·명명 액션)은 S5 소유라 그 부분은 결선 존재만 확인한다. 화면 data_source 키 ↔ 서버
 *  FormRequest 키 대조는 PHP 가드(LayoutQueryParamGateParityTest)가 한다.
 *
 * 시나리오 매니페스트: queue-management (tests/scenarios — test_files 귀속)
 * @axes e2e=queue_tab_failed_retry
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const mainLayout = require('../../layouts/admin_schedule_list.json');
const tabQueue = require('../../layouts/partials/admin_schedule_list/_tab_queue.json');
const pendingGrid = require('../../layouts/partials/admin_schedule_list/_queue_pending_grid.json');
const failedGrid = require('../../layouts/partials/admin_schedule_list/_queue_failed_grid.json');
const logsGrid = require('../../layouts/partials/admin_schedule_list/_queue_logs_grid.json');
const detailModal = require('../../layouts/partials/admin_schedule_list/_modal_queue_job_detail.json');
const confirmModal = require('../../layouts/partials/admin_schedule_list/_modal_queue_confirm.json');
const routes = require('../../routes.json');
const ko = require('../../lang/partial/ko/admin.json');
const en = require('../../lang/partial/en/admin.json');

/** 객체 트리의 모든 노드를 방문합니다. */
function walk(node: any, visit: (n: any) => void): void {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        node.forEach((n) => walk(n, visit));
        return;
    }
    visit(node);
    Object.values(node).forEach((v) => walk(v, visit));
}

/** 조건에 맞는 노드를 모읍니다. */
function collect(node: any, predicate: (n: any) => boolean): any[] {
    const out: any[] = [];
    walk(node, (n) => {
        if (predicate(n)) out.push(n);
    });
    return out;
}

/** id 로 노드 하나를 찾습니다. */
function byId(node: any, id: string): any {
    return collect(node, (n) => n.id === id)[0];
}

/** `{{ 식 }}` 하나를 주어진 범위로 평가합니다(테스트 전용 — 엔진 평가기를 대신하지 않는다). */
function evalExpr(expr: string, scope: Record<string, any>): any {
    const body = expr.replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

/** 점 경로로 사전 값을 읽습니다. */
function resolveKey(dict: any, key: string): any {
    return key.split('.').reduce((o: any, k: string) => (o == null ? undefined : o[k]), dict);
}

const QUEUE_FILES = { tabQueue, pendingGrid, failedGrid, logsGrid, detailModal, confirmModal };

describe('메인 레이아웃·라우트 결선', () => {
    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('큐 탭 본문 자리에 _tab_queue 가 들어가고 큐 모달 둘이 등록되어 있다', () => {
        const content = byId(mainLayout, 'queue_tab_content');
        expect(content.if).toContain("route.path === '/admin/schedules/queue'");
        const json = JSON.stringify(mainLayout);
        expect(json).toContain('_tab_queue');
        expect(json).toContain('_modal_queue_job_detail');
        expect(json).toContain('_modal_queue_confirm');
        expect(Object.keys(mainLayout.named_actions)).toEqual(expect.arrayContaining(['searchQueue', 'resetQueue']));
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('routes.json 에 큐 경로가 스케줄 경로보다 먼저 있고 큐 조회 권한을 요구한다', () => {
        const list = routes.routes ?? routes;
        const queueIdx = list.findIndex((r: any) => r.path === '*/admin/schedules/queue');
        const schedIdx = list.findIndex((r: any) => r.path === '*/admin/schedules');
        expect(queueIdx).toBeGreaterThanOrEqual(0);
        expect(queueIdx).toBeLessThan(schedIdx);
        expect(list[queueIdx].layout).toBe('admin_schedule_list');
        expect(list[queueIdx].meta.permission).toBe('core.queue_jobs.read');
    });
});

describe('하위 탭 — query.view 하나로 갈리고, 바꾸면 조건을 비운다', () => {
    const nav = byId(tabQueue, 'queue_subtab_navigation');

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('대기·실패·처리 기록 세 탭이고 활성 탭은 view(없으면 pending), 실패 탭 배지는 실패 건수다', () => {
        const tabs = nav.props.tabs;
        expect(tabs.map((t: any) => t.id)).toEqual(['pending', 'failed', 'logs']);
        expect(nav.props.activeTabId).toBe("{{query.view || 'pending'}}");
        expect(tabs.find((t: any) => t.id === 'failed').badge).toBe('{{queue_status?.data?.failed_count ?? 0}}');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('하위 탭 전환은 병합하지 않고 공통 조건(검색어·큐·연결)만 싣고, 결과·기간 입력과 고른 줄은 비운다', () => {
        const navigates = collect(nav, (n) => n.handler === 'navigate');
        expect(navigates).toHaveLength(1);
        expect(navigates[0].params.mergeQuery).toBe(false);
        // 하위 탭 전용 조건(status·기간·정렬·쪽)은 싣지 않는다 — 값 판정 전수는 ui-consistency 테스트(D-25)가 한다
        expect(Object.keys(navigates[0].params.query).sort()).toEqual(['connection', 'queue', 'search_field', 'search_keyword', 'view']);
        const resetFilter = collect(nav, (n) => n.handler === 'setState' && n.params?.queueFilter);
        expect(resetFilter[0].params.queueFilter).toMatchObject({ status: 'all', dateStart: '', dateEnd: '', dateQuick: '' });
        const clearSel = collect(nav, (n) => n.handler === 'setState' && 'selectedFailedJobUuids' in (n.params ?? {}));
        expect(clearSel[0].params.selectedFailedJobUuids).toEqual([]);
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('지금 하위 탭의 표만 그린다', () => {
        expect(byId(tabQueue, 'queue_pending_area').if).toBe("{{(query.view || 'pending') === 'pending'}}");
        expect(byId(tabQueue, 'queue_failed_area').if).toBe("{{query.view === 'failed'}}");
        expect(byId(tabQueue, 'queue_logs_area').if).toBe("{{query.view === 'logs'}}");
    });
});

describe('필터 — 쿠폰 목록형 틀(D6), 입력은 쌓이기만 하고 검색·Enter 에서만 조회', () => {
    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('라디오·선택 상자·날짜는 _local.queueFilter 에 담기만 하고 navigate 하지 않는다', () => {
        const area = byId(tabQueue, 'queue_slot_registration_area');
        expect(collect(area, (n) => n.handler === 'navigate')).toHaveLength(0);
        expect(collect(area, (n) => n.handler === 'apiCall')).toHaveLength(0);
        const input = byId(tabQueue, 'queue_search_input');
        expect(JSON.stringify(input)).toContain('searchQueue');
        expect(JSON.stringify(byId(tabQueue, 'queue_search_button'))).toContain('searchQueue');
        expect(JSON.stringify(byId(tabQueue, 'queue_reset_button_pc'))).toContain('resetQueue');
        expect(JSON.stringify(byId(tabQueue, 'queue_reset_button_mobile'))).toContain('resetQueue');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('검색 대상의 「오류 내용」은 실패·처리 기록에만 있다(대기 목록 서버는 error 를 받지 않는다)', () => {
        const select = byId(tabQueue, 'queue_search_field_select');
        const t = (k: string) => k;
        const values = (view?: string) => evalExpr(select.props.options, { query: { view }, $t: t }).map((o: any) => o.value);
        expect(values(undefined)).toEqual(['all', 'job', 'uuid']);
        expect(values('failed')).toEqual(['all', 'job', 'error', 'uuid']);
        expect(values('logs')).toEqual(['all', 'job', 'error', 'uuid']);
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('결과 상태 행은 처리 기록에만, 기간 행은 실패·처리 기록에만 있고 셋 다 기본/상세 슬롯을 고른다', () => {
        expect(byId(tabQueue, 'queue_status_filter_row').if).toBe("{{query.view === 'logs'}}");
        expect(byId(tabQueue, 'queue_date_filter_row').if).toBe("{{['failed', 'logs'].includes(query.view)}}");
        for (const id of ['queue_name_filter_row', 'queue_status_filter_row', 'queue_date_filter_row']) {
            const slot = byId(tabQueue, id).slot ?? byId(tabQueue, id)['data-slot'];
            expect(String(slot), id).toContain('queue_basic_filters');
            expect(String(slot), id).toContain('queue_detail_filters');
        }
        // 빠른 기간은 핸들러 결과($prev)로만 담는다 — 인라인 날짜 계산 금지
        const presets = collect(byId(tabQueue, 'queue_date_filter_row'), (n) => n.handler === 'setDateRange');
        expect(presets.length).toBe(4);
        expect(JSON.stringify(byId(tabQueue, 'queue_date_filter_row'))).not.toContain('new Date(');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('큐 선택지는 등록 현황(entries)의 큐 이름을 겹치지 않게 반복한다', () => {
        const each = collect(byId(tabQueue, 'queue_name_filter_row'), (n) => n.iteration)[0];
        const list = evalExpr(each.iteration.source, {
            queue_status: { data: { entries: [{ connection: 'database', queue: 'default' }, { connection: 'redis', queue: 'default' }, { connection: 'database', queue: 'long' }] } },
        });
        expect(list).toEqual(['default', 'long']);
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('메인의 searchQueue 는 하위 탭이 받는 키만 싣는다', () => {
        const action = mainLayout.named_actions.searchQueue;
        const navs = collect(action, (n) => n.handler === 'navigate');
        expect(navs.length).toBeGreaterThanOrEqual(1);
        const f = { searchField: 'error', searchKeyword: 'x', queue: 'default', connection: 'all', status: 'failed', dateStart: '2026-10-01', dateEnd: '2026-10-02' };
        // 하위 탭별 갈래(conditions)가 있으면 그 view 에서 참인 갈래의 navigate 를 고른다.
        const pick = (view?: string) => {
            const branches = collect(action, (n) => typeof n.if === 'string' && collect(n, (m) => m.handler === 'navigate').length > 0);
            const hit = branches.find((b) => evalExpr(b.if, { query: { view }, _local: { queueFilter: f } }));
            return hit ? collect(hit, (m) => m.handler === 'navigate')[0] : navs[0];
        };
        const evalAll = (view?: string) => {
            const q = pick(view).params.query;
            return Object.fromEntries(Object.entries(q).map(([k, v]) => [k, typeof v === 'string' && v.startsWith('{{') ? evalExpr(v, { _local: { queueFilter: f }, query: { view } }) : v]));
        };
        const pending = evalAll(undefined);
        expect(pending.search_field).toBe('');
        expect(pending.failed_start_date).toBe('');
        expect(pending.created_start_date).toBe('');
        expect(pending.status).toBe('');
        const failed = evalAll('failed');
        expect(failed.search_field).toBe('error');
        expect(failed.failed_start_date).toBe('2026-10-01');
        expect(failed.created_start_date).toBe('');
        expect(failed.status).toBe('');
        const logs = evalAll('logs');
        expect(logs.status).toBe('failed');
        expect(logs.created_end_date).toBe('2026-10-02');
        expect(logs.failed_end_date).toBe('');
        expect(logs.connection).toBe('');
        expect(logs.page).toBe(1);
    });
});

describe('표 — 대기(조회 전용)·실패(UUID 선택·재시도·삭제)·처리 기록(조회 전용)', () => {
    // @scenario e2e=queue_tab_failed_retry
    // @effects pending_list_unmeasurable_driver
    it('대기 표는 고르는 칸이 없고, database 드라이버가 아니면 표 대신 안내를 띄운다', () => {
        const grid = byId(pendingGrid, 'queue_pending_grid');
        expect(grid.props.selectable).toBe(false);
        expect(byId(pendingGrid, 'queue_pending_unmeasurable').if).toBe('{{queue_pending?.data?.meta?.measurable === false}}');
        expect(grid.props.data).toBe('{{queue_pending?.data?.data}}');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('실패 표는 UUID 로 고르고 지금 쪽 범위이며, 일괄 버튼은 고른 줄·권한이 있어야 풀린다', () => {
        const grid = byId(failedGrid, 'queue_failed_grid');
        expect(grid.props.idField).toBe('uuid');
        expect(grid.props.selectionScope).toBe('page');
        expect(grid.props.selectedIds).toBe('{{_global.selectedFailedJobUuids || []}}');
        const retry = byId(failedGrid, 'queue_bulk_retry_button');
        const del = byId(failedGrid, 'queue_bulk_delete_button');
        const dis = (node: any, g: any, can: any) => evalExpr(node.props.disabled, { _global: g, queue_failed: { data: { abilities: can } } });
        expect(dis(retry, { selectedFailedJobUuids: [] }, { can_retry: true })).toBe(true);
        expect(dis(retry, { selectedFailedJobUuids: ['a'] }, { can_retry: false })).toBe(true);
        expect(dis(retry, { selectedFailedJobUuids: ['a'] }, { can_retry: true })).toBe(false);
        expect(dis(del, { selectedFailedJobUuids: ['a'] }, { can_delete: true })).toBe(false);
        const actions = grid.props.rowActions.map((a: any) => a.id);
        // 삭제 앞 구분선은 스케줄 행 메뉴와 같은 규칙이다
        expect(actions).toEqual(['detail', 'retry', 'divider', 'delete']);
        expect(grid.props.rowActions.find((a: any) => a.id === 'retry').disabledField).toBe('abilities.can_retry');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects failed_detail_hides_serialized_command
    it('실패 상세는 GET /queue/failed/{uuid} 로 전문을 받아 창에 담는다', () => {
        const calls = collect(failedGrid, (n) => n.handler === 'apiCall');
        const detail = calls.find((c) => String(c.target).includes('/api/admin/schedules/queue/failed/'));
        expect(detail.params.method).toBe('GET');
        expect(detail.target).toBe('/api/admin/schedules/queue/failed/{{$args[1].uuid}}');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('쪽 이동·쪽당 개수 이동은 전환 덮개 대상을 갖고 고른 줄을 비운다', () => {
        for (const [grid, id] of [[pendingGrid, 'pending'], [failedGrid, 'failed'], [logsGrid, 'logs']] as const) {
            // 쪽 이동 · 쪽당 개수 · 정렬 셋 — 모두 쪽을 정하고 같은 표 본문을 덮는다
            const navs = collect(grid, (n) => n.handler === 'navigate' && n.params?.query?.page !== undefined);
            expect(navs.length, id).toBe(3);
            for (const n of navs) expect(n.params.transition_overlay_target).toBe(`queue_${id}_grid__body`);
        }
        const clears = collect(failedGrid, (n) => n.handler === 'setState' && Array.isArray(n.params?.selectedFailedJobUuids));
        expect(clears.length).toBeGreaterThanOrEqual(3);
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects logs_list_filters
    it('처리 기록 표는 조회 전용이고 상세는 줄 값으로 연다(서버를 다시 부르지 않음)', () => {
        const grid = byId(logsGrid, 'queue_logs_grid');
        expect(grid.props.selectable).toBe(false);
        expect(collect(logsGrid, (n) => n.handler === 'apiCall')).toHaveLength(0);
    });
});

describe('재시도·삭제 확인 창 — 한 건/일괄 분기와 건별 결과', () => {
    const calls = collect(confirmModal, (n) => n.handler === 'apiCall');
    const retry = calls.find((c) => c.params.method === 'POST');
    const del = calls.find((c) => c.params.method === 'DELETE');

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('한 건이면 /failed/{uuid}(+/retry), 여럿이면 /failed(+/retry) + uuids[] 로 보낸다', () => {
        const single = { queueConfirm: { single: true, uuids: ['u-1'] } };
        const bulk = { queueConfirm: { single: false, uuids: ['u-1', 'u-2'] } };
        expect(evalExpr(retry.target, { _global: single })).toBe('/api/admin/schedules/queue/failed/u-1/retry');
        expect(evalExpr(retry.target, { _global: bulk })).toBe('/api/admin/schedules/queue/failed/retry');
        expect(evalExpr(del.target, { _global: single })).toBe('/api/admin/schedules/queue/failed/u-1');
        expect(evalExpr(del.target, { _global: bulk })).toBe('/api/admin/schedules/queue/failed');
        expect(evalExpr(retry.params.body.uuids, { _global: single })).toBeUndefined();
        expect(evalExpr(retry.params.body.uuids, { _global: bulk })).toEqual(['u-1', 'u-2']);
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('성공·실패 양쪽에서 진행 표시를 끄고 결과를 담으며, 끝나면 실패 목록을 다시 받는다', () => {
        for (const call of [retry, del]) {
            const json = JSON.stringify(call);
            expect(json).toContain('queueConfirmBusy');
            expect(json).toContain('"dataSourceId":"queue_failed"');
            const success = JSON.stringify(call.params.onSuccess ?? call.onSuccess ?? call);
            expect(success).toContain('response.data?.results');
            expect(json).toContain('error.errors?.results');
        }
        const submit = byId(confirmModal, 'queue_confirm_submit');
        expect(evalExpr(submit.props.disabled, { _global: { queueConfirmBusy: true, queueConfirm: { uuids: ['a'] } } })).toBe(true);
        expect(evalExpr(submit.props.disabled, { _global: { queueConfirmBusy: false, queueConfirm: { uuids: [] } } })).toBe(true);
    });
});

describe('번역 키 — 큐 조각이 쓰는 admin.* 키가 ko·en 양쪽에 있다', () => {
    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('누락 0', () => {
        const text = Object.values(QUEUE_FILES).map((f) => JSON.stringify(f)).join('\n');
        const keys = new Set<string>();
        for (const m of text.matchAll(/\$t:(?:defer:)?admin\.([A-Za-z0-9_.]+)/g)) keys.add(m[1].replace(/\.$/, ''));
        for (const m of text.matchAll(/\$t\('admin\.([A-Za-z0-9_.]+)'\)/g)) keys.add(m[1]);
        expect(keys.size).toBeGreaterThan(40);
        const missing = [...keys].filter((k) => resolveKey(ko, k) === undefined || resolveKey(en, k) === undefined);
        expect(missing).toEqual([]);
    });
});

describe('렌더 — 확인 창 결과 단계가 줄마다 성공·실패와 서버 메시지를 그린다', () => {
    let utils: ReturnType<typeof createLayoutTest> | undefined;
    beforeEach(() => {
        const registry: any = ComponentRegistry.getInstance();
        registry.registry = {};
        for (const [name, tag] of Object.entries({ Div: 'div', P: 'p', Ul: 'ul', Li: 'li', Span: 'span' })) {
            registry.registry[name] = {
                component: ({ text, children, ...props }: any) => React.createElement(tag, props, children || text),
                metadata: { name, type: 'basic' },
            };
        }
        registry.registry.Icon = { component: ({ name }: any) => <i data-icon={name} />, metadata: { name: 'Icon', type: 'basic' } };
        registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
    });
    afterEach(() => {
        utils?.cleanup();
        (ComponentRegistry.getInstance() as any).registry = {};
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects bulk_retry_per_item_results
    it('결과 2건(성공 1·실패 1)을 아이콘·UUID·메시지로 그린다', async () => {
        const results = byId(confirmModal, 'queue_confirm_results');
        utils = createLayoutTest({ version: '1.0.0', layout_name: 'queue_confirm_results', components: [results] } as any, {
            translations: {}, locale: 'ko',
            initialState: {
                _global: {
                    queueConfirmMessage: '재시도 완료 — 성공 1건, 실패 1건',
                    queueConfirmResults: [
                        { uuid: 'u-ok', ok: true, message: '다시 큐에 넣었습니다.' },
                        { uuid: 'u-ng', ok: false, message: '원래 연결을 찾을 수 없습니다.' },
                    ],
                },
            },
        } as any);
        const { container } = await utils.render();
        const items = container.querySelectorAll('li');
        expect(items).toHaveLength(2);
        expect(items[0].querySelector('i')?.getAttribute('data-icon')).toBe('circle-check');
        expect(items[1].querySelector('i')?.getAttribute('data-icon')).toBe('circle-xmark');
        expect(items[0].textContent).toContain('u-ok');
        expect(items[1].textContent).toContain('원래 연결을 찾을 수 없습니다.');
        expect(container.textContent).toContain('성공 1건, 실패 1건');
    });
});

describe('시도 횟수 — 모르면 0 이 아니라 「-」', () => {
    /** 「{{a}}{{b}}」 꼴 글을 칸마다 평가해 이어 붙입니다. */
    function renderText(text: string, scope: Record<string, any>): string {
        return text.replace(/\{\{([\s\S]+?)\}\}/g, (_m, body) => String(evalExpr(`{{${body}}}`, scope) ?? ''));
    }

    const attemptTexts = (node: any) =>
        collect(node, (n) => typeof n.text === 'string' && n.text.includes('attempts') && n.text.includes('{{')).map((n) => n.text);

    // @scenario e2e=queue_tab_failed_retry
    // @effects failed_attempts_from_log_or_unknown
    it('실패 목록 칸은 시도 수를 모르면(null) 「-」, 알면 그 값을 보인다', () => {
        const [text] = attemptTexts(failedGrid);
        expect(text).toBeTruthy();
        expect(renderText(text, { row: { attempts: null, max_tries: 3 } })).toBe('- / 3');
        expect(renderText(text, { row: { attempts: 1, max_tries: null } })).toBe('1');
        expect(renderText(text, { row: { attempts: 0, max_tries: null } })).toBe('0');
    });

    // @scenario e2e=queue_tab_failed_retry
    // @effects failed_attempts_from_log_or_unknown
    it('상세 창도 시도 수를 모르면 「-」 다', () => {
        const [text] = attemptTexts(detailModal);
        expect(text).toBeTruthy();
        expect(renderText(text, { _global: { queueJobDetail: { attempts: null, max_tries: null } } })).toBe('-');
        expect(renderText(text, { _global: { queueJobDetail: { attempts: 2, max_tries: 3 } } })).toBe('2 / 3');
    });
});
