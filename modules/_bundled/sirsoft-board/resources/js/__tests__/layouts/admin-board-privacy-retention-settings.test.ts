/**
 * 게시판 환경설정 — 개인정보 보유기간 섹션 계약 테스트 (이슈 #636 S5)
 *
 * @description
 * 보유기간 파기는 되돌릴 수 없으므로 기본 꺼짐이고, 두 축(작성 IP · 비회원 작성자 정보)을
 * 기간을 따로 두어 나눈다. 화면 바인딩 키가 저장 카테고리·기본값·서버 검증·저장 payload 네
 * 지점과 일치하는지, 삭제 경고가 삭제 방식에서만 보이는지, 대상 건수 뱃지가 미리보기 API 를
 * 읽는지를 고정한다. 한 곳만 어긋나도 저장은 200 인데 값이 조용히 버려진다.
 *
 * @scenario axis=guest, toggle=on, guest_mode=delete
 *
 * @effects board_settings_retention_section_binds_saved_keys, board_settings_delete_warning_is_mode_conditional
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import generalTab from '../../../layouts/admin/partials/admin_board_settings/_tab_general.json';
import privacyTab from '../../../layouts/admin/partials/admin_board_settings/_tab_privacy_retention.json';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';
import targetsModal from '../../../layouts/admin/partials/admin_board_settings/_modal_privacy_targets.json';
import runModalLayout from '../../../layouts/admin/partials/admin_board_settings/_modal_privacy_run.json';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    for (const child of node.children ?? []) {
        const found = findNode(child, predicate);
        if (found) return found;
    }

    return null;
}

const section = findNode(privacyTab, (n) => n.id === 'privacy_retention_section');

describe('게시판 개인정보 보유기간 설정 — 계약', () => {
    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_retention_section_binds_saved_keys
     */
    it('보유기간 섹션은 자기 탭(「개인정보 보유기간」)에 있고 기본 설정 탭에는 없다', () => {
        // 2026-09-26 결정 — 코어 개인정보 탭·쇼핑몰 보유기간 탭·gdpr 통지 탭처럼 개인정보 조치를 자기 탭에 둔다.
        expect(section).not.toBeNull();
        expect(findNode(generalTab, (n) => n.id === 'privacy_retention_section')).toBeNull();

        const raw = JSON.stringify(settingsLayout);
        const tabNav = manualRunNode(settingsLayout, 'tab_navigation');
        const tabIds = (tabNav?.props?.tabs ?? []).map((t: any) => t.id);
        expect(tabIds).toContain('privacy_retention');
        expect(raw).toContain('_tab_privacy_retention.json');
        // 탭 전환만으로 그려지고 사라져야 한다 — 다른 탭에서 카드가 함께 보이면 저장 payload 와 화면이 어긋난다.
        expect(String(manualRunNode(privacyTab, 'tab_content_privacy_retention')?.if ?? '')).toContain("=== 'privacy_retention'");
        for (const id of ['boardPrivacyRetentionPreview', 'privacyManualRuns']) {
            const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === id);
            expect(dataSource.if, id).toContain("=== 'privacy_retention'");
        }
        for (const locale of ['ko', 'en']) {
            expect(typeof settingsLang(locale).tabs?.privacy_retention, locale).toBe('string');
        }
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_retention_section_binds_saved_keys
     */
    it('켜기 스위치는 이름·설명 오른쪽 끝에 있다', () => {
        // 2026-09-26 결정 — 쇼핑몰 보유기간 탭처럼 이름·설명 왼쪽, 스위치 오른쪽.
        const field = findNode(section, (n) => n.id === 'privacy_retention_enabled_field');
        const children = field.children ?? [];

        expect(String(field.props?.className ?? '')).toMatch(/\bflex-between\b/);
        expect(children.at(-1)?.name).toBe('Toggle');
        expect(children.length).toBe(2);
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_retention_section_binds_saved_keys
     */
    it('토글·기간 두 칸·방식이 privacy_retention 카테고리 키를 바인딩한다', () => {
        const toggle = findNode(section, (n) => n.name === 'Toggle');
        const ipInput = findNode(section, (n) => n.name === 'Input' && n.props?.name === 'privacy_retention.ip_retention_days');
        const guestInput = findNode(section, (n) => n.name === 'Input' && n.props?.name === 'privacy_retention.guest_retention_days');
        const mode = findNode(section, (n) => n.name === 'RadioGroup');

        expect(toggle.props.name).toBe('privacy_retention.enabled');
        expect(ipInput.props.min).toContain('privacy_ip_retention_days_min');
        expect(guestInput.props.min).toContain('privacy_guest_retention_days_min');
        expect(mode.props.name).toBe('privacy_retention.guest_mode');
        expect(mode.props.options.map((o: any) => o.value)).toEqual(['anonymize', 'delete']);
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_delete_warning_is_mode_conditional
     */
    it('기간·방식 칸은 토글이 켜져 있을 때만 보인다', () => {
        const axes = findNode(section, (n) => n.id === 'privacy_retention_axes');

        // 꺼진 채로 보이면 값을 고쳐도 아무 일이 없어 설정이 먹히지 않는 것처럼 읽힌다.
        expect(axes.if).toContain('privacy_retention?.enabled');
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_delete_warning_is_mode_conditional
     */
    it('삭제 경고는 삭제 방식을 골랐을 때만 보인다', () => {
        const warning = findNode(section, (n) => n.id === 'privacy_guest_delete_warning');

        expect(warning.if).toContain("guest_mode === 'delete'");
        expect(warning.props.className).toContain('text-error-soft');
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_preview_api_counts_while_off
     */
    it('대상 건수 뱃지가 미리보기 데이터소스를 읽고, 그 데이터소스가 선언·대기·재조회된다', () => {
        const raw = JSON.stringify(section);
        const layoutRaw = JSON.stringify(settingsLayout);
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'boardPrivacyRetentionPreview');

        expect(raw).toContain('boardPrivacyRetentionPreview?.data?.ip');
        expect(raw).toContain('boardPrivacyRetentionPreview?.data?.guest');
        expect(dataSource.endpoint).toBe('/api/modules/sirsoft-board/admin/settings/privacy-retention-preview');
        // 전환 오버레이가 기다리지 않으면 첫 화면에 뱃지가 늦게 튄다.
        expect(layoutRaw).toContain('"boardPrivacyRetentionPreview"');
        // 저장 성공 뒤 다시 읽지 않으면 방금 저장한 설정 기준 건수가 보이지 않는다.
        expect(layoutRaw).toContain('"dataSourceId":"boardPrivacyRetentionPreview"');
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_settings_are_saved
     */
    it('보유기간 탭 저장 payload 에만 privacy_retention 이 실리고 서버가 그 탭 이름을 받는다', () => {
        const raw = JSON.stringify(settingsLayout);
        const branchOf = (tab: string): string => {
            const start = raw.indexOf(`if (tab === '${tab}')`);
            expect(start, tab).toBeGreaterThan(-1);

            return raw.slice(start, raw.indexOf('; }', start));
        };

        expect(branchOf('privacy_retention')).toContain("_tab: 'privacy_retention'");
        expect(branchOf('privacy_retention')).toContain('privacy_retention: form.privacy_retention || {}');
        // 기본 설정 탭이 계속 싣으면 그 탭 저장이 보이지 않는 보유기간 값을 함께 덮어쓴다.
        expect(branchOf('general')).not.toContain('privacy_retention');

        const request = fs.readFileSync(
            path.join(moduleRoot(), 'src/Http/Requests/Admin/StoreBoardSettingsRequest.php'),
            'utf-8',
        );
        expect(request.match(/'_tab' => \[[^\]]*\]/)?.[0] ?? '').toContain('privacy_retention');
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_is_off_by_default
     */
    it('기본값이 꺼짐·IP 365일·비회원 미적용·익명화이고 서버가 같은 키를 검증·저장한다', () => {
        const defaults = JSON.parse(
            fs.readFileSync(path.join(moduleRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );
        const request = fs.readFileSync(
            path.join(moduleRoot(), 'src/Http/Requests/Admin/StoreBoardSettingsRequest.php'),
            'utf-8',
        );
        const validCategories = request.match(/\$validCategories = \[[^\]]*\]/)?.[0] ?? '';

        expect(defaults._meta.categories).toContain('privacy_retention');
        expect(defaults.defaults.privacy_retention).toEqual({
            enabled: false,
            ip_retention_days: 365,
            guest_retention_days: 0,
            guest_mode: 'anonymize',
        });
        expect(validCategories).toContain("'privacy_retention'");
        expect(request).toContain("'privacy_retention.guest_mode' => ['nullable', 'string', 'in:anonymize,delete']");
        expect(request).toContain("\$limits['privacy_ip_retention_days_min']");
    });

    /**
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_settings_retention_section_binds_saved_keys
     */
    it('편집기 샘플이 미리보기 데이터소스를 가진다', () => {
        const spec = JSON.parse(fs.readFileSync(path.join(moduleRoot(), 'editor-spec.json'), 'utf-8'));
        const raw = JSON.stringify(spec);

        // 샘플이 없으면 편집기 캔버스에서만 뱃지가 빈 채로 보이고 오류는 남지 않는다.
        expect(raw).toContain('"boardPrivacyRetentionPreview"');
    });
});

/**
 * 레이아웃 트리에서 조건을 만족하는 노드를 조상 경로와 함께 모두 모은다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @param ancestors 지금까지의 조상 노드
 * @returns [노드, 조상 목록] 쌍 목록
 */
function collectWithAncestors(node: any, predicate: (n: any) => boolean, ancestors: any[] = []): Array<[any, any[]]> {
    if (!node || typeof node !== 'object') return [];
    const found: Array<[any, any[]]> = predicate(node) ? [[node, ancestors]] : [];
    const children = Array.isArray(node) ? node : [...(node.components ?? []), ...(node.children ?? [])];
    for (const child of children) {
        found.push(...collectWithAncestors(child, predicate, Array.isArray(node) ? ancestors : [...ancestors, node]));
    }

    return found;
}

/**
 * 점 경로로 중첩 객체 값을 읽는다.
 *
 * @param source 원본 객체
 * @param dotted 점으로 이은 경로
 * @returns 값 또는 undefined
 */
function readPath(source: any, dotted: string): any {
    return dotted.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), source);
}

/**
 * 로케일별 다국어 묶음을 읽는다.
 *
 * @param locale 로케일
 * @returns 다국어 JSON
 */
function settingsLang(locale: string): any {
    return JSON.parse(fs.readFileSync(path.join(moduleRoot(), `resources/lang/partial/${locale}/admin/settings.json`), 'utf-8'));
}

describe('개인정보 조치 대상 — 건수 상시 노출 + 대상 목록 창 (#636 §1-E)', () => {
    const viewButtons = collectWithAncestors(privacyTab, (n) => /^view_.*targets$/.test(n.id ?? ''));

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('「대상 보기」 버튼이 있고, 권한으로만 게이트되며(0건이어도 보인다) 기능 토글 안에 갇히지 않는다', () => {
        // 모집단은 레이아웃에서 파생한다 — 버튼이 하나도 없으면 이 단언 자체가 공허해진다.
        expect(viewButtons.length).toBe(2);

        for (const [button, ancestors] of viewButtons) {
            expect(button.if, button.id).toContain('can_view_targets');
            // 대상이 0건이어도 버튼은 보인다 — 0건인 까닭을 창에서 확인할 수 있어야 한다.
            expect(button.if, button.id).not.toMatch(/> 0/);
            // 켜기 전에 규모와 명단을 보는 자리다 — 조상에 기능 켜짐 조건이 걸리면 꺼진 상태에서 사라진다.
            for (const ancestor of ancestors) {
                expect(String(ancestor.if ?? ''), `${button.id} 조상 ${ancestor.id ?? ancestor.name}`).not.toMatch(/enabled/);
            }
        }
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('버튼은 목록 상태를 첫 쪽으로 되돌리고 → 제목 → 명단 재조회 → 창 열기 순서로 동작한다', () => {
        for (const [button] of viewButtons) {
            const click = actionsOf(button).find((a: any) => a.type === 'click');
            const steps = actionsOf(click).map((a: any) => a.handler);

            expect(steps, button.id).toEqual(['setState', 'setState', 'refetchDataSource', 'openModal']);
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('currentPage: 1');
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('hasMore: true');
            expect(actionsOf(click)[1].params['privacyTargets.title'], button.id).toMatch(/^\$t:/);
            expect(actionsOf(click)[2].params.dataSourceId).toBe('privacyTargets');
            expect(actionsOf(click)[3].target).toBe('privacy_targets_modal');
        }
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('명단 데이터소스는 스스로 조회하지 않고 한 쪽 20건을 받는다', () => {
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'privacyTargets');

        expect(dataSource).toBeTruthy();
        expect(dataSource.endpoint.startsWith('/api/admin/privacy/')).toBe(true);
        expect(dataSource.auto_fetch).toBe(false);
        expect(dataSource.params.per_page).toBe(20);
        expect(JSON.stringify(settingsLayout)).toContain('_modal_privacy_targets.json');
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('창은 스크롤 끝에서 다음 쪽을 이어 붙이고, 마지막 쪽·진행 중에는 부르지 않는다', () => {
        const [[list]] = collectWithAncestors(targetsModal, (n) => n.id === 'privacy_targets_list');
        const scroll = actionsOf(list).find((a: any) => a.type === 'scroll');
        const sw = actionsOf(scroll).find((a: any) => a.handler === 'switch');
        const steps = actionsOf(sw.cases.true);
        const api = steps.find((a: any) => a.handler === 'apiCall');

        expect(sw.params.value).toContain('has_more_pages === true');
        expect(sw.params.value).toContain('!_global.privacyTargets?.isLoadingMore');
        expect(api.target.startsWith('/api/admin/privacy/')).toBe(true);
        expect(api.params.query.page).toContain('currentPage');
        expect(callbacksOf(api, 'onSuccess')[0]).toMatchObject({ handler: 'appendDataSource', params: { dataSourceId: 'privacyTargets', dataPath: 'data.data' } });
        expect(JSON.stringify(callbacksOf(api, 'onSuccess'))).toContain('has_more_pages');
        // 실패해도 진행 중 표시를 끈다 — 남으면 다시는 다음 쪽을 부르지 않는다.
        expect(JSON.stringify(callbacksOf(api, 'onError'))).toContain('privacyTargets.isLoadingMore');
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('창은 명단 행을 반복하고 상세를 새 탭으로 열며, 비면 빈 상태를 보인다', () => {
        const raw = JSON.stringify(targetsModal);
        const [[row]] = collectWithAncestors(targetsModal, (n) => n.iteration?.source?.includes('privacyTargets?.data?.data'));
        const [[empty]] = collectWithAncestors(targetsModal, (n) => n.id === 'privacy_targets_empty');

        expect(row.iteration.item_var).toBe('target');
        expect(raw).toContain('"target":"_blank"');
        expect(empty.if).toContain('length === 0');
        // 아이콘과 문구는 감싸는 줄이 세로로 쌓는다 — 아이콘 클래스의 mx-auto·mb 는 Font Awesome 의 display(inline-block)에
        // 무시되어 아이콘과 「대상이 없습니다」가 한 줄에 겹쳐 붙었다(2026-09-26 실측).
        expect(String(empty.props?.className ?? '')).toMatch(/\bflex\b/);
        expect(String(empty.props?.className ?? '')).toMatch(/\bflex-col\b/);
        const emptyIcon = (empty.children ?? []).find((c: any) => c.name === 'Icon');
        expect(emptyIcon, 'empty icon').toBeTruthy();
        expect(String(emptyIcon.props?.className ?? '')).not.toMatch(/\b(mx-auto|mb-\d+|block)\b/);
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    it('버튼·창이 쓰는 대상 문구가 ko/en 모두에 있고 편집기 샘플이 명단 데이터소스를 가진다', () => {
        const raw = JSON.stringify(privacyTab) + JSON.stringify(targetsModal);
        const keys = [...new Set([...raw.matchAll(/\$t:(sirsoft-board\.admin\.settings\.privacy_retention\.targets\.[a-z0-9_.]+)/g)].map((m) => m[1]))];

        expect(keys.length).toBeGreaterThan(3);
        for (const locale of ['ko', 'en']) {
            const bundle = settingsLang(locale);
            for (const key of keys) {
                expect(typeof readPath(bundle, key.replace(/^sirsoft-board\.admin\.settings\./, '')), `${locale}:${key}`).toBe('string');
            }
        }

        const spec = fs.readFileSync(path.join(moduleRoot(), 'editor-spec.json'), 'utf-8');
        expect(spec).toContain('"privacyTargets"');
    });
});


/**
 * 직권 실행 블록 전용 — 레이아웃 트리에서 id 로 노드를 찾는다(props 의 id 는 무시).
 *
 * @param node 탐색 시작 노드
 * @param id 노드 id
 * @returns 노드 또는 null
 */
function manualRunNode(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && node.id === id && (node.type || node.name)) return node;
    for (const child of Object.values(node)) {
        const found = manualRunNode(child, id);
        if (found) return found;
    }

    return null;
}

/**
 * 직권 실행 블록 전용 — 이 확장의 다국어 파일에서 점 경로 값을 읽는다.
 *
 * @param locale 로케일
 * @param key 확장 접두사를 뺀 점 경로
 * @returns 값 또는 undefined
 */
function manualRunLang(locale: string, key: string): any {
    let current = path.dirname(fileURLToPath(import.meta.url));
    for (let depth = 0; depth < 10 && !fs.existsSync(path.join(current, 'module.json')); depth++) {
        current = path.dirname(current);
    }
    const lang = JSON.parse(fs.readFileSync(path.join(current, `resources/lang/partial/${locale}/admin/settings.json`), 'utf-8'));

    return key.split('.').reduce((acc: any, part: string) => (acc == null ? undefined : acc[part]), lang);
}

describe('개인정보 조치 직권 실행 — 「지금 실행」 (#636 후속)', () => {
    const buttons: Array<[string, string]> = [['run_ip_retention', 'retention:sirsoft-board.ip'], ['run_guest_retention', 'retention:sirsoft-board.guest']];
    const runModal: any = runModalLayout;

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_manual_run_buttons_open_the_confirm_modal
     */
    it('「지금 실행」은 실행 권한으로만 그리고, 저장 전 변경·진행 중 작업이면 막으며, 확인 창을 연 뒤 건수를 다시 센다', () => {
        for (const [id, key] of buttons) {
            const button = manualRunNode(privacyTab, id);
            expect(button, id).toBeTruthy();
            expect(button.if, id).toContain(`['${key}']?.can_run`);
            expect(button.props.disabled, id).toContain('_local.hasChanges');
            expect(button.props.disabled, id).toContain('?.run?.is_active');

            const click = actionsOf(button).find((a: any) => a.type === 'click');
            const steps = actionsOf(click);
            expect(steps.map((s: any) => s.handler), id).toEqual(['setState', 'openModal', 'apiCall']);
            expect(steps[0].params.privacyRun, id).toContain(`key: '${key}'`);
            expect(steps[1].target).toBe('privacy_run_modal');
            expect(steps[2].target).toBe(`/api/admin/privacy/manual-runs/${key}/preview`);
        }
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_manual_run_buttons_open_the_confirm_modal
     */
    it('대상마다 한 행에 건수·대상 보기·지금 실행·결과 줄이 함께 있고, 실행은 채운 강조색을 쓰지 않는다', () => {
        // 2026-09-26 결정 — 코어 개인정보 탭과 같은 「대상 행」 양식. 실행 버튼이 자기 대상과 떨어지면 짝을 눈으로 맞춰야 한다.
        const rows: Array<[string, string, string, string]> = [
            ['ip_target_row', 'view_ip_targets', 'run_ip_retention', 'ip_manual_run_status'],
            ['guest_target_row', 'view_guest_targets', 'run_guest_retention', 'guest_manual_run_status'],
        ];
        const hasBadge = (node: any): boolean =>
            !!node && typeof node === 'object'
            && (String(node.props?.className ?? '').includes('badge') || Object.values(node).some((child: any) => hasBadge(child)));

        for (const [rowId, viewId, runId, statusId] of rows) {
            const row = manualRunNode(privacyTab, rowId);
            expect(row, rowId).toBeTruthy();
            for (const id of [viewId, runId, statusId]) {
                expect(manualRunNode(row, id), `${rowId} > ${id}`).toBeTruthy();
            }
            expect(hasBadge(row), rowId).toBe(true);
            expect(manualRunNode(row, runId).props.className, runId).not.toMatch(/btn-(warning|primary|danger)\b/);
        }
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_manual_run_buttons_open_the_confirm_modal
     */
    it('상태 소스는 이 화면 대상만 조회하고, 진행 중이면 폴링·끝나면 응답값으로만 건수를 다시 받는다', () => {
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'privacyManualRuns');
        expect(dataSource.endpoint).toBe('/api/admin/privacy/manual-runs');
        expect(dataSource.params.targets.split(',')).toEqual(buttons.map(([, key]) => key));

        const handlers = callbacksOf(dataSource, 'onSuccess');
        expect(handlers.find((h: any) => h.handler === 'startInterval').if).toContain('has_active === true');
        expect(handlers.find((h: any) => h.handler === 'stopInterval').if).toContain('has_active !== true');
        const refetch = handlers.find((h: any) => h.handler === 'refetchDataSource');
        expect(refetch.params.dataSourceId).toBe('boardPrivacyRetentionPreview');
        expect(refetch.if).toContain('finished_since === true');
        for (const handler of handlers) {
            expect(String(handler.if ?? ''), handler.handler).not.toContain('_global');
        }
    });

    /**
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_manual_run_buttons_open_the_confirm_modal
     */
    it('확인 창은 코어 직권 실행 API 로 접수하고 이 확장의 문구(ko·en)만 쓴다', () => {
        const execute = manualRunNode(runModal, 'privacy_run_execute');
        // 더블클릭 이중 접수 방지 — disabled 의 submitting 은 다시 그려진 뒤에야 걸린다(#736 H 실측: 게시판 확인 창 연타 2건 접수).
        expect(execute.props?.lockRepeatClick).toBe(true);
        const call = actionsOf(actionsOf(execute).find((a: any) => a.type === 'click')).find((a: any) => a.handler === 'apiCall');
        expect(call.params.method).toBe('POST');
        expect(call.target).toBe('/api/admin/privacy/manual-runs/{{_global.privacyRun?.key}}');
        expect(callbacksOf(call, 'onSuccess').filter((a: any) => a.handler === 'refetchDataSource').map((a: any) => a.params.dataSourceId))
            .toEqual(['privacyManualRuns', 'boardPrivacyRetentionPreview']);
        expect(JSON.stringify(settingsLayout)).toContain('_modal_privacy_run.json');

        const source = JSON.stringify(runModal) + JSON.stringify(privacyTab);
        expect(source).not.toContain('admin.settings.privacy.manual_run.');
        expect(source).not.toContain('skip_waiting_checkbox');
        const keys = new Set([...source.matchAll(/sirsoft-board\.admin\.settings\.privacy_retention\.manual_run\.([a-z_]+)/g)].map((m) => m[1]));
        if (keys.delete('modes')) {
            ['anonymize', 'delete', 'erase_ip'].forEach((mode) => keys.add(`modes.${mode}`));
        }
        expect(keys.size).toBeGreaterThan(10);
        for (const locale of ['ko', 'en']) {
            for (const key of keys) {
                expect(typeof manualRunLang(locale, `privacy_retention.manual_run.${key}`), `${locale}:${key}`).toBe('string');
            }
        }
    });
});
