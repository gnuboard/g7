/**
 * 확장(모듈·플러그인·템플릿·언어팩) 업데이트 창 — 터미널과 같은 수준의 결과·실패·입력(7.2.1).
 *
 * 잠그는 것:
 * 1. 업데이트 창 넷 모두 고급 선택지 카드(vendor 방식·소스·ZIP·강제)와 진행·실패 안내 조각을 포함하고,
 *    요청 본문에 force·source(·vendor_mode·zip_file)를 싣는다. ZIP 은 고른 경우에만 싣는다.
 *    「고급 설정」 접이식은 창마다 하나다 — 선택지 카드는 레이아웃 설치 소스 카드와 같은 모양으로 그 안에 든다.
 * 1-1. [다시 설치]·[다시 업데이트(강제)]로 연 창은 제목·본문·버튼이 그 상황 문구이고 강제가 켜진 채 잠긴다.
 * 2. 성공 갈래는 진행 시계를 끄고 결과를 담은 뒤 결과 창을 연다. 업데이트할 것이 없으면(no_update) 성공 토스트를 띄우지 않는다.
 * 3. 실패 갈래는 실패 응답 data(outcome·warnings·retry_command)를 담고, 끊김(응답 없음·502~504)이면 서버에서 다시 확인한다.
 * 4. 목록 행: 끝나지 않은 업데이트 배지·[다시 업데이트(강제)]·업데이트가 없을 때 [다시 설치].
 * 5. 결과 창이 응답 필드(from→to·layout_sync·경고·검색 인덱스·정리 결과)를 그리고, 옛 응답(필드 없음)에서도 깨지지 않는다.
 *
 * @scenario-manifest tests/scenarios/extension-update-failure-rollback.yaml
 */

// e2e:allow 실제 확장 업데이트·응답 끊김은 새 판이 든 시험 릴리스와 게이트웨이 시간 초과가 있어야 재현된다 — 레이아웃 계약 단위로 잠그고 서버 재시험 때 브라우저 실측

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';
import moduleUpdate from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdate from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import templateUpdate from '../../layouts/partials/admin_template_list/_modal_update.json';
import lpUpdate from '../../layouts/partials/admin_language_pack_list/_modal_update.json';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';
import advancedCards from '../../layouts/partials/_shared/_extension_update_advanced.json';
import resultModal from '../../layouts/partials/_shared/_modal_extension_update_result.json';

type Json = Record<string, any>;

/** 조건을 만족하는 노드를 모두 모은다. */
function collect(node: unknown, pred: (n: Json) => boolean, out: Json[] = []): Json[] {
    if (Array.isArray(node)) {
        node.forEach((c) => collect(c, pred, out));
    } else if (node && typeof node === 'object') {
        if (pred(node as Json)) out.push(node as Json);
        Object.values(node as Json).forEach((v) => collect(v, pred, out));
    }
    return out;
}

const raw = (rel: string): string => readFileSync(resolve(__dirname, '../../layouts', rel), 'utf-8');

const modals = [
    { name: '모듈', json: moduleUpdate, src: 'partials/admin_module_list/_modal_update.g7.html', vendor: true, zip: true },
    { name: '플러그인', json: pluginUpdate, src: 'partials/admin_plugin_list/_modal_update.g7.html', vendor: true, zip: true },
    { name: '템플릿', json: templateUpdate, src: 'partials/admin_template_list/_modal_update.g7.html', vendor: false, zip: true },
    { name: '언어팩', json: lpUpdate, src: 'partials/admin_language_pack_list/_modal_update.g7.html', vendor: false, zip: false },
];

/** 업데이트 요청(apiCall …/update POST) */
const updateCall = (tree: unknown): Json => collect(tree, (n) => n.handler === 'apiCall' && /\/update$/.test(String(n.target)) && n.params?.method === 'POST')[0];

describe('확장 업데이트 창 — 입력 선택지', () => {
    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_advanced_options_sent
     */
    it.each(modals)('$name: 고급·진행 안내 조각을 포함한다', ({ src }) => {
        const html = raw(src);
        expect(html).toContain('partials/_shared/_extension_update_advanced.g7.html');
        expect(html).toContain('partials/_shared/_extension_update_status.g7.html');
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_advanced_options_sent
     */
    it.each(modals)('$name: 요청 본문에 force·source 를 싣는다(vendor_mode·zip_file 은 해당 종류만)', ({ json, vendor, zip }) => {
        const body = updateCall(json)?.params?.body ?? {};
        expect(String(body.force)).toContain('extUpdateForce');
        expect(String(body.source)).toContain('extUpdateSource');
        expect('vendor_mode' in body).toBe(vendor);
        expect('zip_file' in body).toBe(zip);
        if (zip) {
            // 빈 값을 보내면 서버가 지정으로 읽는다 — 고른 경우에만 싣는다
            expect(String(body.zip_file)).toContain(': null');
        }
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_install_force_sent
     */
    it('설치 창: 강제 다시 설치를 본문 force 로 싣는다', () => {
        const call = collect(moduleInstall, (n) => n.handler === 'apiCall' && String(n.target).endsWith('/install'))[0];
        expect(String(call.params.body.force)).toContain('extInstallForce');
    });
});

describe('확장 업데이트 창 — 성공·실패 갈래', () => {
    /**
     * @scenario source=module, path=web_update_success, unit=web_response
     * @effects ext_update_result_modal_opened
     */
    it.each(modals)('$name: 성공하면 시계를 끄고 결과를 담아 결과 창을 연다', ({ json }) => {
        const success = updateCall(json).onSuccess as Json[];
        expect(collect(success, (n) => n.handler === 'stopExtensionUpdateClock').length).toBe(1);
        expect(success.some((a) => a.handler === 'setState' && 'extUpdateResult' in (a.params ?? {}))).toBe(true);
        expect(success.some((a) => a.handler === 'openModal' && (a.target ?? a.params?.id) === 'extension_update_result_modal')).toBe(true);
        // 업데이트할 것이 없으면 성공 토스트를 띄우지 않는다
        const successToast = success.find((a) => a.handler === 'toast' && a.params?.type === 'success' && !String(a.params?.message).includes('package_integrity'));
        expect(String(successToast?.if)).toContain('no_update !== true');
    });

    /**
     * @scenario source=module, path=web_update_success, unit=web_response
     * @effects ext_update_result_modal_opened
     */
    it.each(modals)('$name: 검색 인덱스 결과는 응답 최상위(search_index)에서도 담는다 — 서버가 data 밖에 싣는다', ({ json }) => {
        const success = updateCall(json).onSuccess as Json[];
        const store = success.find((a) => a.handler === 'setState' && 'extUpdateResult' in (a.params ?? {}));
        expect(String(store?.params?.extUpdateResult)).toContain('response?.search_index');
    });

    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     * @effects ext_update_disconnect_reconciles_with_server
     */
    it.each(modals)('$name: 실패하면 실패 data 를 담고 끊김이면 서버에서 다시 확인한다', ({ json }) => {
        const fail = updateCall(json).onError as Json[];
        expect(collect(fail[0], (n) => n.handler === 'stopExtensionUpdateClock').length).toBe(1);
        // 실패 data 는 엔진 오류 문맥의 error.data 에 온다 — 레이아웃은 그 경로를 직접 적지 않고(감사 규칙) 핸들러가 꺼낸다.
        // 템플릿 번들이 옛 판이어도 흐름이 끊기지 않게 <parallel> 안에서 부르고, 오류 객체를 인자로 넘긴다.
        const capture = collect(fail, (a) => a.handler === 'captureExtensionUpdateFailure');
        expect(capture.length).toBe(1);
        expect(String(capture[0].params?.error ?? capture[0].error)).toContain('{{error}}');
        expect(JSON.stringify(fail)).not.toContain('error.data');
        const reconcile = collect(fail, (a) => a.handler === 'reconcileExtensionUpdate')[0];
        expect(reconcile).toBeDefined();
        expect(String(reconcile!.if)).toContain('504');
        // JSON 으로 온 503(작업 잠금 저장소 오류)은 끊김이 아니다
        expect(String(reconcile!.if)).toContain('!error.errors');
        expect(fail.some((a) => a.handler === 'openModal' && String(a.if).includes('504'))).toBe(true);
    });
});

describe('확장 업데이트 창 — 템플릿 핸들러가 없어도 업데이트가 멈추지 않는다', () => {
    const CUSTOM = ['startExtensionUpdateClock', 'stopExtensionUpdateClock', 'reconcileExtensionUpdate', 'captureExtensionUpdateFailure'];

    /**
     * 템플릿 핸들러 번들이 아직 새 판이 아니면(미등록 핸들러) sequence 가 그 자리에서 멈춘다 — 업데이트 요청 자체가 나가지 않는다.
     * 그래서 진행 시계·끊김 확인은 실패를 삼키는 parallel 안에서만 부른다.
     *
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_survives_missing_template_handlers
     */
    it.each([...modals.map((m) => [m.name, m.json] as const), ['모듈 설치', moduleInstall] as const])('%s: 새 핸들러는 모두 parallel 안에 있다', (_name, json) => {
        const parallels = collect(json, (n) => n.handler === 'parallel');
        const wrapped = parallels.flatMap((p) => collect(p.actions ?? p.params?.actions ?? [], (n) => CUSTOM.includes(n.handler)));
        const all = collect(json, (n) => CUSTOM.includes(n.handler));
        expect(all.length).toBeGreaterThan(0);
        expect(wrapped.length).toBe(all.length);
    });
});

describe('확장 목록 행 — 끝나지 않은 업데이트·다시 설치', () => {
    const lists = [
        { name: '모듈', json: moduleList, force: 'moduleForceUpdate' },
        { name: '플러그인', json: pluginList, force: 'pluginForceUpdate' },
        { name: '템플릿', json: templateTabAdmin, force: 'templateForceUpdate' },
        { name: '언어팩', json: languagePackContent, force: null },
    ];

    /**
     * @scenario source=module, path=web_update_list, unit=web_response
     * @effects ext_list_unfinished_update_badge
     */
    it.each(lists)('$name: 행에 끝나지 않은 업데이트 배지·강제 재업데이트·다시 설치가 있다', ({ json, force }) => {
        const text = JSON.stringify(json);
        expect(text).toContain('admin.extension_update.unfinished_badge');
        expect(text).toContain('admin.extension_update.retry_force');
        expect(text).toContain('admin.extension_update.reinstall');
        if (force) {
            // 끝나지 않은 업데이트 행으로 연 업데이트 창은 강제를 켜 둔다
            const seeds = collect(json, (n) => n.handler === 'setState' && typeof n.params?.[force] === 'string' && n.params[force].includes('unfinished_update'));
            expect(seeds.length).toBeGreaterThan(0);
        }
    });
});

describe('확장 업데이트 결과 창 — 렌더', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;

    const translations = {
        common: { close: '닫기' },
        admin: {
            extension_update: {
                result_title: '업데이트 결과', success: '업데이트했습니다.', no_update: '업데이트할 내용이 없습니다.',
                target: '대상', version: '판', layout_sync_title: '화면별 레이아웃 처리 ({{count}})', layout_sync_screen: '화면', layout_sync_verdict: '처리',
                layout_warnings_title: '깨진 조각 ({{count}})', dependency_warnings_title: '의존 경고', search_index_title: '검색 인덱스',
                search_index_rebuilt: '확인 {{inspected}} · 다시 만듦 {{repaired}} · 실패 {{failed}}', search_index_stale: ':count개', search_index_ok: '없음',
                recovered_title: '멈춘 업데이트를 정리했습니다', recovered_help: '다시 켜세요', recovered_unfinished: '꺼진 채 남음', recovered_status: '상태: {{status}}',
                disconnected_title: '응답이 끊겼습니다', reconcile_completed: '끝났습니다 {{version}}', reconcile_unfinished: '끝나지 않았습니다',
                reconcile_not_applied: '반영 안 됨 {{version}}', reconcile_unknown: '확인 못 함',
            },
            layout_sync_preview: { verdict: { merged: '합침' } },
        },
    };

    const layout = (result: Json): Json => ({
        version: '1.0.0',
        layout_name: 'ext_update_result_test',
        initGlobal: { extUpdateResult: result },
        components: [(resultModal as Json).children[0]],
    });

    /** 기본 태그를 그대로 그리는 시험용 컴포넌트 */
    const tag = (Tag: string): React.FC<any> => ({ className, children, text }) => React.createElement(Tag, { className }, children || text);

    beforeEach(() => {
        const registry = ComponentRegistry.getInstance();
        const reg: Json = {};
        for (const [name, t] of Object.entries({ Div: 'div', Span: 'span', P: 'p', Pre: 'pre', Ul: 'ul', Li: 'li', Table: 'table', Thead: 'thead', Tbody: 'tbody', Tr: 'tr', Th: 'th', Td: 'td', Button: 'button' })) {
            reg[name] = { component: tag(t), metadata: { name, type: 'basic' } };
        }
        reg.Icon = { component: () => null, metadata: { name: 'Icon', type: 'basic' } };
        reg.Fragment = { component: ({ children }: any) => React.createElement(React.Fragment, null, children), metadata: { name: 'Fragment', type: 'layout' } };
        (registry as any).registry = reg;
    });
    afterEach(() => {
        if (testUtils) testUtils.cleanup();
    });

    /**
     * @scenario source=module, path=web_update_success, unit=web_response
     * @effects ext_update_result_modal_opened
     */
    it('판·레이아웃 처리·의존 경고·정리 결과를 그린다', async () => {
        testUtils = createLayoutTest(layout({
            name: '게시판', message: '게시판을 1.2.0 으로 업데이트했습니다.', from_version: '1.1.0', to_version: '1.2.0',
            layout_sync: { entries: [{ template: 'sirsoft-admin_basic', name: 'board_list', verdict: 'merged' }] },
            dependency_warnings: [{ identifier: 'sirsoft-ecommerce', message: '이커머스 2.0 이상이 필요합니다' }],
            recovered: [{ type: 'module', identifier: 'sirsoft-board', status: 'inactive', unfinished: true, activate_command: 'php artisan module:activate sirsoft-board' }],
        }) as any, { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() } as any);
        await testUtils.render();

        expect(screen.getByText('1.1.0 → 1.2.0')).toBeTruthy();
        expect(screen.getByText('sirsoft-admin_basic · board_list')).toBeTruthy();
        expect(screen.getByText(/이커머스 2.0 이상이 필요합니다/)).toBeTruthy();
        expect(screen.getByText('php artisan module:activate sirsoft-board')).toBeTruthy();
        expect(screen.getByText(/module sirsoft-board — 꺼진 채 남음/)).toBeTruthy();
    });

    /**
     * @scenario source=module, path=web_update_success, unit=web_response
     * @effects ext_update_result_tolerates_old_response
     */
    it('옛 응답(필드 없음)·업데이트 없음이면 서버 문구만 정보로 보인다', async () => {
        testUtils = createLayoutTest(layout({ message: '업데이트할 내용이 없습니다.', no_update: true }) as any, { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() } as any);
        await testUtils.render();

        expect(screen.getByText('업데이트할 내용이 없습니다.')).toBeTruthy();
        expect(screen.queryByText('업데이트했습니다.')).toBeNull();
    });
});

describe('확장 업데이트 창 — 「고급 설정」 하나', () => {
    /** id 로 노드를 찾는다 */
    const byId = (tree: unknown, id: string): Json | undefined => collect(tree, (n) => n.id === id)[0];
    /** 조각 참조 노드 */
    const advancedRefs = (tree: unknown): Json[] => collect(tree, (n) => n.partial === 'partials/_shared/_extension_update_advanced.json');

    const sections = [
        { name: '모듈', json: moduleUpdate, src: 'partials/admin_module_list/_modal_update.g7.html', section: 'module_update_layout_source_section' },
        { name: '플러그인', json: pluginUpdate, src: 'partials/admin_plugin_list/_modal_update.g7.html', section: 'plugin_update_layout_source_section' },
        { name: '템플릿', json: templateUpdate, src: 'partials/admin_template_list/_modal_update.g7.html', section: 'template_update_layout_source_section' },
        { name: '언어팩', json: lpUpdate, src: 'partials/admin_language_pack_list/_modal_update.g7.html', section: 'lp_update_advanced_section' },
    ];

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_single_advanced_section
     */
    it.each(sections)('$name: 고급 선택지 카드는 하나의 「고급 설정」 접이식 안에만 든다', ({ json, src, section }) => {
        expect(advancedRefs(json)).toHaveLength(1);
        const sec = byId(json, section);
        expect(sec).toBeTruthy();
        expect(advancedRefs(sec)).toHaveLength(1);
        // 별도 접이식(코어 탭 모양) 흔적이 없다
        expect(raw(src)).not.toContain('admin-card-accordion');
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_single_advanced_section
     */
    it('공용 카드는 접이식을 만들지 않고 라디오·체크박스 카드로 그린다(셀렉트 없음, 반복 안 id 없음)', () => {
        const html = raw('partials/_shared/_extension_update_advanced.g7.html');
        expect(html).not.toContain('admin-card-accordion');
        expect(html).not.toContain('<select');
        for (const id of ['ext_update_vendor_mode', 'ext_update_source', 'ext_update_zip', 'ext_update_force']) {
            const card = byId(advancedCards, id);
            expect(card, id).toBeTruthy();
            // 레이아웃 설치 소스 카드와 같은 카드 클래스
            expect(String(card!.props.className)).toContain('p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg');
        }
        const radios = collect(advancedCards, (n) => n.name === 'Input' && n.props?.type === 'radio');
        // vendor 3 + 소스 3 + ZIP(사용 안 함 1 + 후보 반복 1)
        expect(radios).toHaveLength(8);
        const zipEach = collect(advancedCards, (n) => typeof n.iteration?.source === 'string' && n.iteration.source.includes('extUpdateManualSources'))[0];
        expect(zipEach).toBeTruthy();
        expect(collect(zipEach, (n) => typeof n.id === 'string')).toHaveLength(0);
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_reinstall_mode_labeled
     */
    it('강제 체크는 다시 설치·다시 실행에서 켜진 채 잠긴다', () => {
        const box = collect(byId(advancedCards, 'ext_update_force'), (n) => n.name === 'Input' && n.props?.type === 'checkbox')[0];
        expect(String(box.props.checked)).toContain('extUpdateReinstall === true');
        expect(String(box.props.checked)).toContain('extUpdateRetry === true');
        expect(String(box.props.disabled)).toContain('extUpdateReinstall === true');
        expect(String(box.props.disabled)).toContain('extUpdateRetry === true');
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_install_force_sent
     */
    it.each([
        { name: '모듈', json: moduleInstall, k: 'module' },
        { name: '플러그인', json: pluginInstall, k: 'plugin' },
        { name: '템플릿', json: templateInstall, k: 'template' },
    ])('$name 설치 창: 강제 다시 설치 카드가 「고급 설정」 접이식 안에 든다', ({ json, k }) => {
        const sec = byId(json, `${k}_install_layout_source_section`);
        expect(byId(sec, `${k}_install_force`)).toBeTruthy();
        expect(collect(json, (n) => n.id === `${k}_install_force`)).toHaveLength(1);
    });
});

describe('확장 업데이트 창 — 다시 설치·다시 실행 문구', () => {
    const kinds = [
        { k: 'module', json: moduleUpdate },
        { k: 'plugin', json: pluginUpdate },
        { k: 'template', json: templateUpdate },
        { k: 'lang_pack', json: lpUpdate },
    ];

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_reinstall_mode_labeled
     */
    it.each(kinds)('$k: 제목·버튼이 다시 설치·다시 실행 문구로 바뀌고 본문 force 가 늘 참이 된다', ({ k, json }) => {
        const modal = (json as Json).children?.[0] ?? json;
        const title = String(collect(json, (n) => n.name === 'Modal')[0]?.props?.title ?? modal.props?.title);
        expect(title).toContain(`extension_update.reinstall_title_${k}`);
        expect(title).toContain(`extension_update.retry_title_${k}`);
        const texts = collect(json, (n) => typeof n.text === 'string').map((n) => n.text as string).join('\n');
        expect(texts).toContain("'$t:admin.extension_update.reinstall'");
        expect(texts).toContain("'$t:admin.extension_update.retry_force'");
        expect(texts).toContain('$t:admin.extension_update.reinstall_body');
        const body = updateCall(json)?.params?.body ?? {};
        expect(String(body.force)).toContain('extUpdateReinstall === true');
        expect(String(body.force)).toContain('extUpdateRetry === true');
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_reinstall_mode_labeled
     */
    it.each([
        { name: '모듈', json: moduleList },
        { name: '플러그인', json: pluginList },
        { name: '템플릿', json: templateTabAdmin },
        { name: '언어팩', json: languagePackContent },
    ])('$name 목록: [다시 설치]는 다시 실행을 끄고, 업데이트 창은 끝나지 않은 업데이트면 다시 실행으로 연다', ({ json }) => {
        const reinstall = collect(json, (n) => n.handler === 'setState' && n.params?.extUpdateReinstall === true);
        expect(reinstall.length).toBeGreaterThan(0);
        reinstall.forEach((n) => expect(n.params.extUpdateRetry).toBe(false));
        const update = collect(json, (n) => n.handler === 'setState' && n.params?.extUpdateReinstall === false);
        expect(update.length).toBeGreaterThan(0);
        update.forEach((n) => expect(String(n.params.extUpdateRetry)).toContain('unfinished_update'));
    });
});
