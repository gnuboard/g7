/**
 * @file admin_schedule_list-ui-consistency.test.tsx
 * @description 스케줄·큐 화면 UI 일관성 정리(후속) — 동작이 바뀌는 네 갈래와 새 구조의 계약
 *
 * 배경:
 *  - A-4 일괄 상태 변경이 확인 창 없이 바로 PATCH 했다 — 기준 목록 다섯 곳(쿠폰·회원·배송정책…)은 모두 확인 창을 거친다.
 *  - 서버가 Shell 실행을 꺼 두어도 등록 창이 Shell 유형을 고르게 두어 저장하면 422 였다.
 *  - C-14 소유가 모듈·플러그인이 아닐 때도 확장 선택 상자가 비활성으로 남아 자리를 차지했다.
 *  - D-25 큐 하위 탭을 바꾸면 검색어·큐·연결까지 모두 비웠다 — 하위 탭이 같이 받는 조건은 유지해야 한다.
 *  - 스케줄 보안 정책(허용·거부 목록, Shell)을 화면에서 볼 길이 없었다 — 읽기 전용 창.
 */

import { describe, it, expect } from 'vitest';

const mainLayout = require('../../layouts/admin_schedule_list.json');
const datagrid = require('../../layouts/partials/admin_schedule_list/_partial_schedule_datagrid.json');
const filterSection = require('../../layouts/partials/admin_schedule_list/_partial_filter_section.json');
const formModal = require('../../layouts/partials/admin_schedule_list/_modal_form.json');
const tabQueue = require('../../layouts/partials/admin_schedule_list/_tab_queue.json');
const ko = require('../../lang/partial/ko/admin.json');
const en = require('../../lang/partial/en/admin.json');

/** 조건에 맞는 노드를 모읍니다. */
function collect(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => collect(n, predicate, out));
        return out;
    }
    if (predicate(node)) out.push(node);
    Object.values(node).forEach((v) => collect(v, predicate, out));
    return out;
}

/** id 로 노드 하나를 찾습니다. */
function byId(node: any, id: string): any {
    return collect(node, (n) => n.id === id)[0];
}

/** `{{ 식 }}` 하나를 주어진 범위로 평가합니다(테스트 전용). */
function evalExpr(expr: string, scope: Record<string, any>): any {
    const body = String(expr).replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

/** 값이 식이면 평가하고 아니면 그대로 돌려줍니다. */
function value(v: any, scope: Record<string, any>): any {
    return typeof v === 'string' && v.startsWith('{{') ? evalExpr(v, scope) : v;
}

/** 점 경로로 사전 값을 읽습니다(파일이 admin 부분 사전이라 첫 칸 admin. 을 뗀다). */
function t(dict: any, key: string): any {
    return key.replace(/^admin\./, '').split('.').reduce((o: any, k: string) => (o == null ? undefined : o[k]), dict);
}

/** 모든 모달 정의(메인 레이아웃 modals + partial)를 한 배열로 모읍니다. */
function allModals(): any[] {
    const fs = require('fs');
    const path = require('path');
    const dir = path.resolve(__dirname, '../../layouts/partials/admin_schedule_list');
    return fs.readdirSync(dir)
        .filter((f: string) => f.startsWith('_modal_') && f.endsWith('.json'))
        .map((f: string) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')));
}

describe('A-4 일괄 상태 변경 — 확인 창을 거친다', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('일괄 적용 버튼은 PATCH 를 직접 보내지 않고 확인 창을 연다', () => {
        const apply = byId(datagrid, 'bulk_status_apply');
        expect(apply, 'bulk_status_apply').toBeDefined();
        expect(collect(apply, (n) => n.handler === 'apiCall')).toEqual([]);
        const opens = collect(apply, (n) => n.handler === 'openModal').map((n) => n.target ?? n.params?.target);
        expect(opens).toContain('schedule_bulk_status_modal');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('확인 창이 등록되어 있고, 창 안에서 PATCH bulk-status 를 보내며 성공 토스트·선택 비우기를 한다', () => {
        expect(JSON.stringify(mainLayout)).toContain('_modal_bulk_status');
        const modal = allModals().find((m) => byId(m, 'schedule_bulk_status_modal'));
        expect(modal, 'schedule_bulk_status_modal').toBeDefined();
        const api = collect(modal, (n) => n.handler === 'apiCall')[0];
        expect(api.target ?? api.params?.target).toBe('/api/admin/schedules/bulk-status');
        expect(api.params.method).toBe('PATCH');
        const json = JSON.stringify(api);
        expect(json).toContain('selectedScheduleIds');
        expect(json).toContain('"handler":"toast"');
        // 확인 문구는 고른 개수를 싣는다(활성·비활성 갈래 모두)
        const texts = collect(modal, (n) => typeof n.text === 'string' && n.text.includes('bulk_status_confirm')).map((n) => n.text);
        expect(texts.length).toBe(2);
        for (const text of texts) expect(text).toContain('|count=');
    });
});

describe('꺼진 Shell 유형은 고를 수 없다', () => {
    const select = collect(formModal, (n) => n.name === 'Select' && n.props?.name === 'type')[0];
    const scope = (shell: boolean | undefined) => ({
        schedule_status: { data: { shell_enabled: shell } },
        _global: { scheduleForm: { type: 'artisan' }, selectedSchedule: null },
        $t: (k: string) => k,
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('서버가 Shell 을 끄면(shell_enabled=false) Shell 선택지가 disabled 다', () => {
        const options = evalExpr(select.props.options, scope(false));
        const shell = options.find((o: any) => o.value === 'shell');
        expect(shell.disabled).toBe(true);
        for (const other of options.filter((o: any) => o.value !== 'shell')) expect(other.disabled).toBeFalsy();
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('켜져 있거나 아직 모르면(값 없음) 막지 않는다', () => {
        expect(evalExpr(select.props.options, scope(true)).find((o: any) => o.value === 'shell').disabled).toBeFalsy();
        expect(evalExpr(select.props.options, scope(undefined)).find((o: any) => o.value === 'shell').disabled).toBeFalsy();
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('꺼져 있으면 유형 칸 아래에 그 이유를 보인다', () => {
        const notice = byId(formModal, 'schedule_form_shell_disabled');
        expect(notice, 'schedule_form_shell_disabled').toBeDefined();
        expect(evalExpr(notice.if, scope(false))).toBe(true);
        expect(evalExpr(notice.if, scope(true))).toBe(false);
        expect(JSON.stringify(notice)).toContain('admin.schedules.form.shell_disabled');
        expect(t(ko, 'admin.schedules.form.shell_disabled')).toBeTypeOf('string');
        expect(t(en, 'admin.schedules.form.shell_disabled')).toBeTypeOf('string');
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('꺼짐 안내는 켜는 방법(.env 두 키)까지 알려 준다', () => {
        for (const bundle of [ko, en]) {
            const text = String(t(bundle, 'admin.schedules.form.shell_disabled'));
            expect(text).toContain('SCHEDULE_SHELL_ENABLED=true');
            expect(text).toContain('SCHEDULE_SHELL_ALLOWED_BINARIES');
        }
    });
});

describe('C-14 확장 선택 상자는 소유가 모듈·플러그인일 때만 보인다', () => {
    const dropdown = byId(filterSection, 'owner_identifier_dropdown');

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('비활성으로 남기지 않고 조건부로 그린다', () => {
        expect(dropdown.props?.disabled).toBeUndefined();
        // 선택 상자를 감싼 칸이 조건을 진다(지우기 버튼도 함께 사라진다)
        const holder = collect(filterSection, (n) => typeof n.if === 'string' && byId(n, 'owner_identifier_dropdown'))[0];
        expect(holder, '확장 선택 칸의 if').toBeDefined();
        for (const [type, shown] of [['all', false], ['core', false], ['custom', false], ['module', true], ['plugin', true]] as const) {
            expect(evalExpr(holder.if, { _local: { filter: { ownerType: type } } }), type).toBe(shown);
        }
    });
});

describe('D-25 큐 하위 탭을 바꿔도 공통 조건(검색어·큐·연결)은 남는다', () => {
    const nav = byId(tabQueue, 'queue_subtab_navigation');
    const navigate = collect(nav, (n) => n.handler === 'navigate')[0];
    const applied = {
        view: 'pending', search_field: 'job', search_keyword: 'sitemap', queue: 'default', connection: 'redis',
        status: 'failed', created_start_date: '2026-10-01', sort_by: 'created_at', sort_order: 'asc', page: '3',
    };
    const go = (to: string, query: Record<string, any> = applied) => Object.fromEntries(
        Object.entries(navigate.params.query).map(([k, v]) => [k, value(v, { $args: [to], query })]),
    );

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('검색어·큐·연결을 싣고 하위 탭 전용 조건(결과·기간·정렬·쪽)은 버린다', () => {
        expect(navigate.params.mergeQuery).toBe(false);
        const q = go('failed');
        expect(q.view).toBe('failed');
        expect(q.search_field).toBe('job');
        expect(q.search_keyword).toBe('sitemap');
        expect(q.queue).toBe('default');
        expect(q.connection).toBe('redis');
        for (const dropped of ['status', 'created_start_date', 'failed_start_date', 'sort_by', 'sort_order', 'page']) {
            expect(q[dropped] ?? '', dropped).toBe('');
        }
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('대기 탭이 받지 않는 검색 대상(오류 내용)은 대기로 갈 때만 버린다', () => {
        const query = { ...applied, view: 'failed', search_field: 'error' };
        expect(go('pending', query).search_field).toBe('');
        expect(go('logs', query).search_field).toBe('error');
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('입력칸도 같은 값으로 맞추고 결과·기간 입력만 비운다', () => {
        const reset = collect(nav, (n) => n.handler === 'setState' && n.params?.queueFilter)[0];
        const scope = { $args: ['failed'], query: applied };
        const f = Object.fromEntries(Object.entries(reset.params.queueFilter).map(([k, v]) => [k, value(v, scope)]));
        expect(f).toMatchObject({ searchField: 'job', searchKeyword: 'sitemap', queue: 'default', connection: 'redis', status: 'all', dateStart: '', dateEnd: '' });
    });
});

describe('스케줄 보안 정책 — 읽기 전용 창', () => {
    const security = require('../../layouts/partials/admin_schedule_list/_modal_security.json');

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('헤더 버튼이 지난 값을 비우고 창을 연 뒤 GET /schedules/security 를 받아 전역에 담는다(실패하면 사유)', () => {
        const button = byId(mainLayout, 'security_policy_button');
        expect(button, 'security_policy_button').toBeDefined();
        const json = JSON.stringify(button.actions);
        expect(json).toContain('"scheduleSecurity":null');
        expect(json).toContain('schedule_security_modal');
        const api = collect(button, (n) => n.handler === 'apiCall')[0];
        expect(api.target ?? api.params?.target).toBe('/api/admin/schedules/security');
        expect(api.params.method).toBe('GET');
        expect(JSON.stringify(api)).toContain('scheduleSecurityError');
        expect(JSON.stringify(mainLayout)).toContain('_modal_security');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('항목(덮어쓰기 파일·Shell·Artisan 허용/거부/접두어·확장 자동 허용·Job 클래스)을 읽고, 바꾸는 요청은 하나도 없다', () => {
        const json = JSON.stringify(security);
        for (const path of ['override_file?.path', 'override_file?.valid', 'shell?.enabled', 'shell?.allowed_binaries', 'artisan?.allowlist', 'artisan?.denylist', 'artisan?.denylist_prefixes', 'artisan?.allow_extension_commands', 'job?.allowed_classes']) {
            expect(json, path).toContain(`_global.scheduleSecurity?.${path}`);
        }
        expect(collect(security, (n) => n.handler === 'apiCall')).toEqual([]);
        // 출처 배지(코어/추가)와 서버 관리자 안내(덮어쓰기 파일 경로·.env 키)
        expect(json).toContain("item.source === 'override'");
        expect(String(t(ko, 'admin.schedules.security.guide'))).toContain('storage/app/schedule_security.json');
        expect(String(t(ko, 'admin.schedules.security.guide'))).toContain('SCHEDULE_SHELL_ENABLED');
    });
});

describe('설정 탭의 스케줄 작업 안내 — 고정 시각 대신 다음 실행 일시 + 스케줄 관리 링크', () => {
    const fs = require('fs');
    const path = require('path');
    const settingsDir = path.resolve(__dirname, '../../layouts/partials/admin_settings');
    const cases = [
        ['_tab_seo.json', 'field_sitemap_schedule_link', 'sitemap-generate'],
        ['_tab_drivers.json', 'queue_records_schedule_notice', 'queue-prune-records'],
        ['_tab_security_password_policy.json', 'password_expiry_schedule_notice', 'auth-process-password-expiry'],
        ['_tab_security_activity_log.json', 'activity_log_schedule_notice', 'activity-log-prune'],
        ['_tab_advanced.json', 'geoip_schedule_notice', 'geoip-update'],
        ['_tab_privacy.json', 'withdrawn_schedule_notice', 'privacy-process-retention'],
        ['_tab_upload.json', 'orphan_cleanup_schedule_notice', 'attachments-prune-orphans'],
    ] as const;

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it.each(cases)('%s 의 %s 는 %s 행의 다음 실행 일시와 링크를 보인다', (file, id, name) => {
        const partial = JSON.parse(fs.readFileSync(path.join(settingsDir, file), 'utf8'));
        const notice = byId(partial, id);
        expect(notice, id).toBeDefined();
        expect(notice.props.className).toBe('alert-info');
        const json = JSON.stringify(notice);
        expect(json).toContain(`r.name === '${name}'`);
        expect(json).toContain('next_run_at | datetime');
        expect(json).toContain(`search_keyword=${name}`);
        expect(json).toContain('$t:admin.settings.schedule_link.change');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('환경설정이 코어 스케줄 목록을 그 탭에서만 받고(403 은 조용히) 전환 덮개가 기다린다', () => {
        const settings = require('../../layouts/admin_settings.json');
        const ds = (settings.data_sources ?? []).find((d: any) => d.id === 'coreSchedules');
        expect(ds.endpoint).toBe('/api/admin/schedules');
        expect(ds.params.extension_type).toBe('core');
        for (const tab of ['seo', 'drivers', 'security', 'advanced', 'privacy', 'upload']) {
            expect(evalExpr(ds.if, { query: { tab }, _global: {} }), tab).toBe(true);
        }
        expect(evalExpr(ds.if, { query: { tab: 'general' }, _global: {} })).toBe(false);
        expect(JSON.stringify(ds.errorHandling ?? ds.error_handling)).toContain('403');
        expect(JSON.stringify(settings.transition_overlay)).toContain('coreSchedules');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('설정·방문자 통계 문구에 고정 실행 시각(HH:MM)이 남지 않는다', () => {
        const leaves = (o: any, p = ''): [string, string][] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' && v ? leaves(v, `${p}.${k}`) : [[`${p}.${k}`, String(v)]] as [string, string][]));
        for (const dict of [ko, en]) {
            const offenders = [...leaves(dict.settings, 'settings'), ...leaves(dict.analytics?.settings?.reports ?? {}, 'analytics.settings.reports')]
                .filter(([k, v]) => /\b\d{1,2}:\d{2}\b/.test(v) && !/proxy|placeholder/.test(k));
            expect(offenders).toEqual([]);
        }
    });
});
