/**
 * @file admin_schedule_list-live-defects.test.tsx
 * @description 스케줄·큐 화면 라이브 실측 결함 3건(M1 2026-10-16) 회귀
 *
 * D2 — 데이터소스 `if` 가 `route.path` 를 읽었다. 엔진의 **데이터소스 조건 문맥**은
 *      `{route: 라우트 파라미터, query, _global}` 이라 `route.path` 가 없다(렌더 문맥에만 path 가 붙는다).
 *      그래서 큐 3종 목록은 한 번도 불리지 않고 스케줄 목록이 큐 탭에서도 나갔다.
 *      → 엔진의 실제 판정 함수(DataSourceManager.filterByCondition)에 실제 문맥 모양을 넣어 고정한다.
 *        이전 테스트는 `if` 문자열에 `route.path` 가 **들어 있는지**만 봐서 결함을 오히려 잠갔다.
 * D3 — 필터 카드 `data-key` 아래의 이름 있는 라디오는 자동 바인딩이 value 를 현재 상태값으로 덮는다
 *      (트러블슈팅 form 사례 4 — 라디오 5개 value 가 모두 현재값). 이전 테스트는 라디오의 value 속성만 봤다
 *      (컴파일 산출물에는 올바른 값이 있고, 덮어쓰기는 렌더 시점에 일어난다).
 * D1 — 표 셀 안 `$t:key|minutes={{row.x}}` 는 셀 렌더 전에 번역돼 `row` 가 비어 「분」만 보였다.
 *      반복 셀 문맥의 인자는 `$t:defer:` 로 번역을 미뤄야 한다(data-binding-i18n 「지연 번역」).
 *      이전 테스트는 그 문구 문자열이 있는지만 봤다.
 */

import { describe, it, expect } from 'vitest';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { applyResponsiveClassMerge } from '@core/template-engine/helpers/StyleHelpers';

const mainLayout = require('../../layouts/admin_schedule_list.json');
const filterSection = require('../../layouts/partials/admin_schedule_list/_partial_filter_section.json');
const queueTab = require('../../layouts/partials/admin_schedule_list/_tab_queue.json');
const datagrid = require('../../layouts/partials/admin_schedule_list/_partial_schedule_datagrid.json');

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

/** 엔진의 데이터소스 조건 판정으로 선택되는 id 목록(엔진이 실제로 넘기는 문맥 모양). */
function selectedIds(query: Record<string, string>): string[] {
    const manager = new DataSourceManager();
    // TemplateApp: 진입 시 {route: route.params, query, _global}, navigate 재평가도 같은 모양 — path 가 없다
    const context = { route: {}, query, _global: {} };
    return manager.filterByCondition(mainLayout.data_sources ?? [], context as any).map((d: any) => d.id);
}

describe('D2 — 데이터소스가 탭(경로·하위 탭)별로 실제로 갈린다', () => {
    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('스케줄 탭(view 없음)은 스케줄 목록·소유자를 부르고 큐 목록은 부르지 않는다', () => {
        const ids = selectedIds({});
        expect(ids).toEqual(expect.arrayContaining(['schedules', 'schedule_owners', 'schedule_status']));
        expect(ids).not.toContain('queue_pending');
        expect(ids).not.toContain('queue_failed');
        expect(ids).not.toContain('queue_logs');
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it.each([
        ['pending', 'queue_pending'],
        ['failed', 'queue_failed'],
        ['logs', 'queue_logs'],
    ])('큐 탭 하위 탭 %s 는 그 목록 하나만 부르고 스케줄 목록·소유자는 부르지 않는다', (view, id) => {
        const ids = selectedIds({ view });
        expect(ids).toContain(id);
        expect(ids.filter((x) => x.startsWith('queue_') && x !== 'queue_status')).toEqual([id]);
        expect(ids).not.toContain('schedules');
        expect(ids).not.toContain('schedule_owners');
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('데이터소스 조건은 데이터소스 문맥에 없는 route.path 를 읽지 않는다', () => {
        for (const ds of mainLayout.data_sources ?? []) {
            expect(String(ds.if ?? ''), ds.id).not.toContain('route.path');
        }
    });

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it('큐 탭으로 가는 길은 언제나 view 를 싣는다 — 탭 전환 기본값과 맨 주소 진입 보정', () => {
        const tabs = collect(mainLayout, (n) => n.id === 'tab_navigation')[0];
        const nav = collect(tabs.actions, (n) => n.handler === 'navigate')[0];
        expect(String(nav.params.query)).toContain("view: 'pending'");
        // /admin/schedules/queue 를 view 없이 열면 init 이 view=pending 으로 보정한다
        const initNavs = collect(mainLayout.init_actions ?? [], (n) => n.handler === 'navigate');
        const fix = initNavs.find((n: any) => n.params?.path === '/admin/schedules/queue');
        expect(fix, 'init 의 view 보정 navigate').toBeDefined();
        expect(fix.params.query.view).toBe('pending');
        expect(String(fix.if ?? fix.params?.if ?? JSON.stringify(fix))).toContain('!query.view');
    });
});

describe('D3 — 필터 라디오의 value 가 자동 바인딩으로 덮이지 않는다', () => {
    /** dataKey 컨테이너 아래의 이름 있는 라디오 중 자동 바인딩을 끄지 않은 것. */
    function boundRadios(node: any, insideDataKey = false, out: string[] = []): string[] {
        if (!node || typeof node !== 'object') return out;
        if (Array.isArray(node)) {
            node.forEach((n) => boundRadios(n, insideDataKey, out));
            return out;
        }
        const here = insideDataKey || typeof node.dataKey === 'string';
        if (here && node.name === 'Input' && node.props?.type === 'radio' && node.props?.name && node.props?.autoBinding !== false) {
            out.push(`${node.props.name}=${node.props.value}`);
        }
        Object.values(node).forEach((v) => boundRadios(v, here, out));
        return out;
    }

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('스케줄 필터 — dataKey 아래 자동 바인딩되는 이름 있는 라디오가 없다', () => {
        expect(boundRadios(filterSection)).toEqual([]);
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('큐 필터 — dataKey 아래 자동 바인딩되는 이름 있는 라디오가 없다', () => {
        expect(boundRadios(queueTab)).toEqual([]);
    });
});

describe('D1 — 표 셀 번역 인자는 셀 문맥(row)에서 풀린다', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('row 를 인자로 넘기는 셀 번역은 모두 $t:defer: 다', () => {
        const texts = collect(datagrid, (n) => typeof n.text === 'string' && n.text.includes('$t:') && /\{\{\s*row\./.test(n.text))
            .map((n) => n.text as string);
        expect(texts.length).toBeGreaterThan(0);
        for (const t of texts) {
            expect(t, t).toMatch(/^\$t:defer:/);
        }
    });
});

describe('D4 — 표 흐림은 그 표의 데이터소스만 기다린다', () => {
    const grids = [
        ['_partial_schedule_datagrid', 'schedules'],
        ['_queue_pending_grid', 'queue_pending'],
        ['_queue_failed_grid', 'queue_failed'],
        ['_queue_logs_grid', 'queue_logs'],
    ] as const;

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it.each(grids)('%s 의 DataGrid 는 blur_until_loaded 를 자기 데이터소스(%s)로 한정한다', (file, source) => {
        // `true` 는 데이터 문맥의 모든 데이터소스가 올 때까지 흐린다 — 다른 탭(이 탭에서는 받지 않는) 데이터소스가
        // undefined 로 남아 표가 흐림·클릭 불가로 굳었다(M1 D4). 객체 형태 data_sources 는 지정한 것만 본다.
        const partial = require(`../../layouts/partials/admin_schedule_list/${file}.json`);
        const grid = collect(partial, (n) => n.name === 'DataGrid')[0];
        expect(grid, file).toBeDefined();
        const blur = grid.blur_until_loaded;
        expect(blur, `${file}: blur_until_loaded 가 true(전체 대기)다`).not.toBe(true);
        expect(blur?.enabled).toBe(true);
        const sources = Array.isArray(blur?.data_sources) ? blur.data_sources : [blur?.data_sources];
        expect(sources).toEqual([source]);
        // 그 표가 실제로 그리는 데이터소스와 같아야 한다
        expect(String(grid.props?.data)).toContain(`${source}?.data`);
    });
});

describe('D5 — 편집 창 저장은 숨은 언어 탭의 필수 칸에 막히지 않고 서버 오류를 칸 아래 보인다', () => {
    const modalForm = require('../../layouts/partials/admin_schedule_list/_modal_form.json');
    const form = collect(modalForm, (n) => n.name === 'Form' && n.props?.id === 'schedule_form_modal')[0];

    // @scenario case=form_modal
    // @effects schedule_screen_renders_registry_fields
    it('탭형 다국어 필수 칸이 있는 폼은 브라우저 검증을 끈다(noValidate)', () => {
        // MultilingualInput layout="tabs" 는 모든 언어 칸을 그리고 현재 탭이 아닌 칸을 hidden 으로 숨긴다.
        // 기본 언어 칸의 required 를 브라우저가 숨은 탭에서 잡으면 「not focusable」 로 제출이 조용히 멈춘다
        // (요청 0·안내 0 — M1 D5). 검증은 서버 422 와 칸 아래 오류로 맡긴다.
        expect(form, 'schedule_form_modal').toBeDefined();
        const tabbedRequired = collect(form, (n) => n.name === 'MultilingualInput' && n.props?.layout === 'tabs' && n.props?.required !== undefined && n.props?.required !== false);
        expect(tabbedRequired.length).toBeGreaterThan(0);
        expect(form.props?.noValidate).toBe(true);
    });

    // @scenario case=form_modal
    // @effects schedule_screen_renders_registry_fields
    it('브라우저 범위 검증(min·max)을 받던 숫자 칸은 서버 오류를 칸 아래 보인다', () => {
        const numbers = collect(form, (n) => n.name === 'Input' && n.props?.type === 'number' && n.props?.name);
        expect(numbers.length).toBeGreaterThan(0);
        const errorTexts = collect(form, (n) => typeof n.if === 'string' && n.if.includes('scheduleFormErrors?.')).map((n) => n.if as string);
        for (const input of numbers) {
            expect(errorTexts.some((t) => t.includes(`scheduleFormErrors?.${input.props.name}`)), input.props.name).toBe(true);
        }
    });
});

describe('D7 — 클릭으로 쓰기 요청을 보내는 버튼은 연타해도 요청 1건이다', () => {
    const fs = require('fs');
    const path = require('path');
    const dir = path.resolve(__dirname, '../../layouts/partials/admin_schedule_list');
    const partials = fs.readdirSync(dir).filter((f: string) => f.endsWith('.json'));
    const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** click 액션 중 하위에 쓰기 apiCall 이 있는데 debounce 가 없는 것. */
    function unguarded(json: any): string[] {
        const out: string[] = [];
        const clicks = collect(json, (n) => n.type === 'click' && typeof n.handler === 'string');
        for (const click of clicks) {
            const writes = collect(click, (n) => n.handler === 'apiCall' && WRITE.includes(String(n.params?.method ?? '').toUpperCase()));
            if (writes.length && !click.debounce) {
                out.push(writes.map((w: any) => `${w.params.method} ${w.target ?? w.params?.target ?? ''}`).join(' | '));
            }
        }
        return out;
    }

    // @scenario case=screen_tabs
    // @effects schedule_screen_tabs_scoped_data_sources
    it.each(partials)('%s — 쓰기 요청 클릭에 debounce 가 걸려 있다', (file: string) => {
        // 버튼 disabled 는 첫 클릭의 상태 변경이 렌더된 뒤에야 걸려 같은 틱의 두 번째 클릭을 막지 못한다
        // (M1 D7: 재시도 더블클릭 → POST 2건, 두 번째 404 가 성공 문구를 덮음). 폼 submit 이 아닌 click 은
        // `sequence :debounce` 로 모은다(troubleshooting-state-advanced 「저장 버튼 연타」).
        const json = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
        expect(unguarded(json)).toEqual([]);
    });
});

describe('D10 — 좁은 화면에서 탭 바가 본문 안쪽 여백과 같은 폭으로 붙는다', () => {
    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('본문 여백과 탭 바 음수 여백이 폭마다 같은 단계다(좁은 화면 p-4 ↔ -mx-4)', () => {
        // sticky-tab-nav 는 -mx-6(24px) 고정이라 portable(p-4 = 16px)에서 8px 가로로 넘쳤다(M1 D10, 390 폭).
        // 본문 여백(p-4 → 640 이상 p-6 → 1024 이상 p-8)과 탭 바 음수 여백·안쪽 여백(-mx-4/px-4 → -mx-6/px-6 → -mx-8/px-8)은
        // 엔진 responsive 구간 값이다 — 폭마다 엔진이 고르는 클래스로 두 단계가 같은지 잰다.
        const presets: Record<string, [number, number]> = {
            mobile: [0, 767], tablet: [768, 1023], desktop: [1024, Infinity], portable: [0, 1023],
        };
        const rangeOf = (key: string): [number, number] => {
            if (presets[key]) return presets[key];
            const [lo, hi] = key.split('-');
            return [lo ? Number(lo) : 0, hi ? Number(hi) : Infinity];
        };
        // 엔진 규칙: 맞는 키 중 커스텀 구간이 프리셋보다 먼저, 같은 종류끼리는 좁은 범위가 먼저
        const classAt = (node: any, width: number): string[] => {
            const keys = Object.keys(node.responsive ?? {}).filter((k) => {
                const [lo, hi] = rangeOf(k);
                return lo <= width && width <= hi;
            });
            keys.sort((a, b) => Number(a in presets) - Number(b in presets)
                || (rangeOf(a)[1] - rangeOf(a)[0]) - (rangeOf(b)[1] - rangeOf(b)[0]));
            // 고른 구간의 실효값 — 구간 class(통째 교체, 없으면 기본 class) 위에 구간 class-merge 를 엔진 규칙으로 덧붙인다
            const value = keys.length
                ? applyResponsiveClassMerge(node.responsive[keys[0]]?.props?.className ?? node.props?.className, node.responsive[keys[0]]?.classMerge)
                : node.props?.className;
            return String(value ?? '').split(/\s+/);
        };
        const step = (tokens: string[], re: RegExp) => tokens.map((t) => re.exec(t)?.[1]).find(Boolean);
        const root = collect(mainLayout, (n) => n.id === 'schedule_list_content')[0];
        const wrapper = collect(mainLayout, (n) => n.id === 'tab_navigation_wrapper')[0];
        for (const [width, expected] of [[390, '4'], [700, '6'], [900, '6'], [1280, '8']] as const) {
            expect(step(classAt(root, width), /^p-(\d+)$/), `${width}px 본문 여백`).toBe(expected);
            expect(step(classAt(wrapper, width), /^-mx-(\d+)$/), `${width}px 탭 바 음수 여백`).toBe(expected);
            expect(step(classAt(wrapper, width), /^px-(\d+)$/), `${width}px 탭 바 안쪽 여백`).toBe(expected);
        }
    });
});

describe('관찰A — 휴대폰 폭은 상태를 한 줄로 요약하고, 자세히를 눌러야 카드가 펼쳐진다', () => {
    const statusCard = require('../../layouts/partials/admin_schedule_list/_status_card.json');
    const ko = require('../../lang/partial/ko/admin.json');
    const en = require('../../lang/partial/en/admin.json');
    // 파일이 `admin` 부분 사전이라 키의 첫 칸(admin.)을 떼고 찾는다
    const get = (dict: any, key: string) => key.replace(/^admin\./, '').split('.').reduce((o: any, k: string) => (o == null ? undefined : o[k]), dict);

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('요약 줄은 휴대폰에서만 보이고 카드 묶음은 태블릿 2칸·휴대폰은 펼쳤을 때만 보인다', () => {
        const line = collect(statusCard, (n) => n.id === 'schedule_status_line')[0];
        expect(line, 'schedule_status_line').toBeDefined();
        expect(line.if).toBe('{{false}}');
        expect(line.responsive?.mobile?.if).toBe('{{true}}');

        const cards = collect(statusCard, (n) => n.id === 'schedule_status_cards')[0];
        expect(String(cards.responsive?.tablet?.props?.className)).toContain('grid-cols-2');
        expect(String(cards.responsive?.mobile?.if)).toContain('_local.statusDetailOpen');
        expect(cards.responsive?.portable).toBeUndefined();

        // 자세히/접기 버튼이 펼침 상태를 뒤집는다
        const toggle = collect(line, (n) => n.handler === 'setState' && n.params && 'statusDetailOpen' in n.params)[0];
        expect(toggle, 'statusDetailOpen 토글').toBeDefined();
        expect(String(toggle.params.statusDetailOpen)).toContain('!_local.statusDetailOpen');
    });

    // @scenario case=screen_render
    // @effects schedule_screen_renders_registry_fields
    it('요약 줄 문구 키가 ko·en 양쪽에 있다', () => {
        const keys = collect(line(), (n) => typeof n.text === 'string' && n.text.includes('$t:'))
            .flatMap((n) => [...String(n.text).matchAll(/\$t:(?:defer:)?([a-z0-9_.]+)/gi)].map((m) => m[1]));
        expect(keys.length).toBeGreaterThan(0);
        for (const key of keys) {
            expect(get(ko, key), `ko ${key}`).toBeTypeOf('string');
            expect(get(en, key), `en ${key}`).toBeTypeOf('string');
        }
        function line() { return collect(statusCard, (n) => n.id === 'schedule_status_line')[0] ?? {}; }
    });
});

describe('C1 — 실행 이력 필터는 「전체」가 빈 값이고 「모든 실행 기록」(all)은 그대로 서버로 간다', () => {
    // history_mode 의 Enum 값 all(모든 실행 기록)이 다른 축의 「전체」 규약과 겹쳐, 이 축만 「전체」= 빈 값이다.
    // 라디오만 그 규약을 따르고 초기값·초기화·주소 복원·검색 액션은 옛 'all'=전체 를 써서, 처음 열면 「모든 실행 기록」이
    // 골라져 보이고 그걸 골라 검색하면 history_mode 가 빈 값으로 나가 모든 행이 나왔다(validate-plan C1).
    // 이전 테스트는 초기화 setState 에 "historyMode":"all" 이 **있는지** 단언해 결함을 잠갔다.
    const evalExpr = (expr: string, scope: Record<string, any>) => {
        const body = String(expr).replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
        // eslint-disable-next-line no-new-func
        return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
    };
    const value = (v: any, scope: Record<string, any>) => (typeof v === 'string' && v.startsWith('{{') ? evalExpr(v, scope) : v);

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it('처음 열기·초기화는 「전체」(빈 값)를 고른다', () => {
        expect(mainLayout.initLocal.filter.historyMode).toBe('');
        const reset = asArray(mainLayout.named_actions.resetSchedules.actions).find((a: any) => a.params?.filter && 'historyMode' in a.params.filter);
        expect(reset.params.filter.historyMode).toBe('');
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it.each([
        [{}, ''],
        [{ history_mode: 'all' }, 'all'],
        [{ history_mode: 'failures_only' }, 'failures_only'],
    ])('주소 %j 로 들어오면 라디오 값 %j 를 되살린다', (query, expected) => {
        const init = asArray(mainLayout.init_actions).find((a: any) => a.params?._local?.filter && 'historyMode' in a.params._local.filter);
        expect(value(init.params._local.filter.historyMode, { query })).toBe(expected);
    });

    // @scenario case=screen_filter_submit
    // @effects schedule_screen_filters_submit_only
    it.each([
        ['', ''],
        ['all', 'all'],
        ['failures_only', 'failures_only'],
    ])('라디오 %j 로 검색하면 history_mode=%j 를 보낸다', (historyMode, expected) => {
        const expr = mainLayout.named_actions.searchSchedules.params.query.history_mode;
        expect(value(expr, { _local: { filter: { historyMode } } })).toBe(expected);
    });
});
