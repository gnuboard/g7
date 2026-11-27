/**
 * @file admin_schedule_list-registry-screen.test.tsx
 * @description 스케줄 관리 화면(7.2.0 DB 등록제) — 필터·탭·상태 카드·표·즉시 실행 창의 레이아웃 계약
 *
 * 배경:
 *  - 검색어는 `search` 로 나가 서버가 버렸고(검색 무동작), 모듈 필터는 선택지가 「전체」뿐에 서버가 모르는 `module` 키였다.
 *  - 라디오를 누를 때마다 즉시 조회했고, 초기화는 다른 상태를 지웠고, 헤더 「초기화」는 같은 주소로 이동해 무동작이었다.
 *  - 일괄 「실행」 select 는 담기만 하고 쓰이지 않았다(실행 API 없음).
 *  이 파일은 고친 구조를 정적으로 고정한다 — 화면 data_source 키 ↔ 서버 FormRequest 키 대조는 PHP 가드
 *  (LayoutQueryParamGateParityTest)가 한다.
 */

import { describe, it, expect } from 'vitest';

const mainLayout = require('../../layouts/admin_schedule_list.json');
const filterSection = require('../../layouts/partials/admin_schedule_list/_partial_filter_section.json');
const datagrid = require('../../layouts/partials/admin_schedule_list/_partial_schedule_datagrid.json');
const statusCard = require('../../layouts/partials/admin_schedule_list/_status_card.json');
const runModal = require('../../layouts/partials/admin_schedule_list/_modal_run.json');
const deleteModal = require('../../layouts/partials/admin_schedule_list/_modal_delete.json');

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

/** 핸들러 이름으로 액션을 모읍니다. */
function actionsWithHandler(node: any, handler: string): any[] {
    return collect(node, (n) => n.handler === handler);
}

const LIST_QUERY_KEYS = [
    'search_field', 'search_keyword', 'extension_type', 'extension_identifier', 'type', 'status',
    'last_result', 'frequency', 'dispatch_mode', 'overlap', 'history_mode',
    'created_start_date', 'created_end_date',
];

describe('스케줄 목록 data_source — 서버 쿼리 키(K6)를 그대로 읽는다', () => {
    const ds = (mainLayout.data_sources ?? []).find((d: any) => d.id === 'schedules');

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('필터 키 전부가 같은 이름의 query.* 를 읽고, 옛 키(search·module·created_from)는 없다', () => {
        for (const key of LIST_QUERY_KEYS) {
            expect(ds.params[key], key).toBe(`{{query.${key}}}`);
        }
        for (const dead of ['search', 'search_type', 'module', 'created_from', 'created_to', 'without_overlapping']) {
            expect(ds.params, dead).not.toHaveProperty(dead);
        }
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('스케줄 탭에서만 부른다 — 큐 탭에서는 큐 데이터소스만 돈다', () => {
        // 데이터소스 조건 문맥에는 route.path 가 없다 — 탭은 query.view 하나로 가른다.
        // 실제 판정(엔진 filterByCondition)은 admin_schedule_list-live-defects.test.tsx 가 고정한다.
        expect(ds.if).toBe('{{!query.view}}');
        const byId = Object.fromEntries((mainLayout.data_sources ?? []).map((d: any) => [d.id, d]));
        expect(byId.queue_pending.if).toContain("query.view === 'pending'");
        expect(byId.queue_failed.if).toContain("query.view === 'failed'");
        expect(byId.queue_logs.if).toContain("query.view === 'logs'");
        expect(byId.schedule_owners.endpoint).toBe('/api/admin/schedules/owners');
        expect(byId.schedule_status.endpoint).toBe('/api/admin/schedules/status');
    });
});

describe('검색 — 입력은 쌓이기만 하고 검색 버튼·Enter 에서만 조회한다', () => {
    const search = mainLayout.named_actions.searchSchedules;

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('searchSchedules 는 data_source 가 읽는 키만 싣고 「전체」(all)는 빈 값으로 떨군다', () => {
        expect(search.handler).toBe('navigate');
        expect(search.params.mergeQuery).toBe(true);
        expect(search.params.query.page).toBe(1);
        for (const key of LIST_QUERY_KEYS) {
            expect(search.params.query, key).toHaveProperty(key);
        }
        expect(search.params.query.type).toContain("!== 'all'");
        // 확장 선택은 소유가 모듈·플러그인일 때만 싣는다
        expect(search.params.query.extension_identifier).toContain("['module', 'plugin'].includes(_local.filter.ownerType)");
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('필터 조각 안에서는 어떤 입력도 직접 navigate 하지 않는다(즉시 조회 금지)', () => {
        expect(actionsWithHandler(filterSection, 'navigate')).toEqual([]);
        const refs = collect(filterSection, (n) => n.handler === 'actionRef' || n.actionRef);
        expect(JSON.stringify(refs)).toContain('searchSchedules');
        expect(JSON.stringify(refs)).toContain('resetSchedules');
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('초기화는 입력값을 처음 값으로 되돌리고 병합 없이 조건 없는 주소로 간다', () => {
        const reset = mainLayout.named_actions.resetSchedules;
        const nav = actionsWithHandler(reset, 'navigate');
        expect(nav).toHaveLength(1);
        expect(nav[0].params.path).toBe('/admin/schedules');
        expect(nav[0].params.mergeQuery).toBe(false);
        const set = actionsWithHandler(reset, 'setState');
        expect(JSON.stringify(set)).toContain('"ownerType":"all"');
        // 실행 이력 축만 「전체」= 빈 값(Enum all = 모든 실행 기록과 겹침) — C1
        expect(JSON.stringify(set)).toContain('"historyMode":""');
    });
});

describe('소유 필터 — 라디오 + 확장 선택(owners API)', () => {
    const dropdown = collect(filterSection, (n) => n.id === 'owner_identifier_dropdown')[0];

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('확장 선택은 모듈·플러그인일 때만 보이고 그 종류의 확장만 보인다', () => {
        expect(dropdown.name).toBe('SearchableDropdown');
        // 비활성으로 남기지 않고 감싼 칸이 조건부로 그린다 — 판정 전수는 ui-consistency 테스트가 한다
        expect(dropdown.props.disabled).toBeUndefined();
        expect(collect(filterSection, (n) => n.id === 'owner_identifier_holder')[0].if).toBe("{{['module', 'plugin'].includes(_local.filter.ownerType)}}");
        expect(dropdown.props.options).toContain('schedule_owners?.data');
        expect(dropdown.props.options).toContain('o.type === _local.filter.ownerType');
        // 옵션 label = 확장 이름, description = 식별자 · 스케줄 N
        expect(dropdown.props.options).toContain('label: o.name');
        expect(dropdown.props.options).toContain("description: o.identifier + ' · '");
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('라디오 다섯(전체·코어·모듈·플러그인·직접 등록)이 있고 종류를 바꾸면 고른 확장을 비운다', () => {
        const radios = collect(filterSection, (n) => n.name === 'Input' && n.props?.type === 'radio' && n.props?.name === 'ownerType');
        expect(radios.map((r: any) => r.props.value)).toEqual(['all', 'core', 'module', 'plugin', 'custom']);
        for (const radio of radios) {
            expect(JSON.stringify(radio.actions), radio.props.value).toContain('"filter.ownerIdentifier":""');
        }
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('실행 이력 축은 「전체」가 빈 값이다 — Enum 값 all(모든 실행 기록)과 겹치지 않는다', () => {
        const radios = collect(filterSection, (n) => n.name === 'Input' && n.props?.type === 'radio' && n.props?.name === 'historyMode');
        expect(radios.map((r: any) => r.props.value)).toEqual(['', 'all', 'failures_only']);
    });
});

describe('헤더·탭', () => {
    const refresh = collect(mainLayout, (n) => n.id === 'refresh_button')[0];

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('헤더 새로고침은 같은 주소로 navigate 하지 않고 지금 탭의 목록과 상태 카드를 다시 받는다', () => {
        expect(actionsWithHandler(refresh, 'navigate')).toEqual([]);
        const refetched = actionsWithHandler(refresh, 'refetchDataSource').map((a: any) => a.params?.dataSourceId ?? a.dataSourceId);
        expect(refetched).toEqual(expect.arrayContaining(['schedule_status', 'schedules', 'queue_pending', 'queue_failed', 'queue_logs']));
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('큐 탭이 열려 있고, 탭을 오가면 떠나는 탭의 조건을 기억해 돌아올 때 되살린다', () => {
        const tabs = collect(mainLayout, (n) => n.id === 'tab_navigation')[0];
        const queueTab = tabs.props.tabs.find((t: any) => t.id === 'queue');
        expect(queueTab.disabled).toBeUndefined();
        const json = JSON.stringify(tabs.actions);
        expect(json).toContain('queueTabQuery');
        expect(json).toContain('scheduleTabQuery');
    });
});

describe('상태 카드 — 하트비트·큐 요약', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('하트비트가 정상이 아니면 cron 등록 안내를 보인다', () => {
        const json = JSON.stringify(statusCard);
        expect(json).toContain('schedule_status?.data?.heartbeat?.healthy');
        expect(json).toContain('admin.schedules.status_card.cron_hint');
        expect(json).toContain('php artisan schedule:run');
        // 대기 건수는 database 드라이버에서만 센다
        expect(json).toContain('schedule_status?.data?.queue?.measurable');
    });
});

describe('표 — 소유·조건·중복 방지·일괄 작업', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('죽은 일괄 「실행」 select 가 없다', () => {
        expect(collect(datagrid, (n) => n.props?.name === 'bulk_action')).toEqual([]);
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('일괄 삭제는 코어·확장 소유 행이 섞이면 잠기고, 단건 삭제와 같은 확인 창을 대상 목록으로 연다', () => {
        const button = collect(datagrid, (n) => n.id === 'bulk_delete_button')[0];
        expect(button.props.disabled).toContain('r.is_owned');
        expect(JSON.stringify(button.actions)).toContain('deleteTargetIds');
        const api = actionsWithHandler(deleteModal, 'apiCall')[0];
        expect(api.target ?? api.params?.target).toContain('/api/admin/schedules/bulk');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('작업명은 표시 명칭이 주이고, 중복 방지는 분 단위로 보인다', () => {
        const json = JSON.stringify(datagrid);
        expect(json).toContain('{{row.label_localized}}');
        expect(json).toContain('admin.schedules.overlap_minutes|minutes={{row.without_overlapping}}');
        expect(json).toContain('row.unmet_conditions');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('수정 창을 열 때 중복 방지 켜짐과 분 값을 따로 담는다(끈 행은 기본 1440)', () => {
        const json = JSON.stringify(collect(datagrid, (n) => n.event === 'onRowAction'));
        expect(json).toContain('overlapEnabled');
        expect(json).toContain(': 1440');
    });
});

describe('즉시 실행 창 — 200(이력)·202(큐 접수)·422(사유) 분기', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('큐 접수(queued)와 실행 결과를 다른 영역으로 보이고, 미충족 조건을 실행 전에 경고한다', () => {
        const ids = collect(runModal, (n) => typeof n.id === 'string').map((n: any) => n.id);
        expect(ids).toEqual(expect.arrayContaining(['run_queued_result', 'run_result', 'run_unmet_condition_warning']));
        const queued = collect(runModal, (n) => n.id === 'run_queued_result')[0];
        expect(queued.if).toContain('_global.runResult?.queued === true');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('실패 토스트는 서버가 준 사유(errors.general)를 먼저 보인다', () => {
        const api = actionsWithHandler(runModal, 'apiCall')[0];
        expect(JSON.stringify(api)).toContain('error.errors?.general?.[0]');
    });
});
