/**
 * 연간 통지 폼의 저장 경로와 시험 발송 수명주기, 이력 페이지 계약.
 * @scenario flow=layout
 * @effects settings_validated, self_test_only, history_paginated
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { describe, expect, it } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import settings from '../../../layouts/admin/plugin_settings.json';
import history from '../../../layouts/admin/gdpr_notice_log.json';
import targetsModal from '../../../layouts/admin/partials/plugin_settings/_modal_privacy_targets.json';
import runModalLayout from '../../../layouts/admin/partials/plugin_settings/_modal_privacy_run.json';
import { findById, type AnyNode } from './helpers';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

describe('개인정보 연간 통지', () => {
    // @scenario flow=layout
    // @effects settings_validated, self_test_only, history_paginated
    it('기존 설정 폼에 통지 카드와 저장 가능한 세 필드를 둔다', () => {
        const card = findById(settings as unknown as AnyNode, 'card_annual_notice');
        expect(card).toBeTruthy();
        const serialized = JSON.stringify(card);
        for (const field of ['annual_notice.enabled', 'annual_notice.batch_size', 'annual_notice.introduction']) {
            expect(serialized).toContain(field);
        }
        expect(serialized).toContain('form.annual_notice.channels');
        expect(serialized).toContain('$event?.target?.value');
    });

    // @scenario flow=layout
    // @effects disabled_toggle_releases_dependent_fields
    it('자동 통지를 끄면 채널·상한·서문 입력이 비활성화된다', () => {
        const card = findById(settings as unknown as AnyNode, 'card_annual_notice');
        const serialized = JSON.stringify(card);
        // 서버(UpdateAdminSettingsRequest)도 같은 조건에서 강제하지 않는다 — 한쪽만 고치면 끌 수 없는 설정이 된다.
        const guard = "!_local.form?.annual_notice?.enabled || _local.form?.annual_notice?.enabled === '0'";
        expect(serialized.split(guard).length - 1).toBe(3);
        expect(serialized).toContain('annual_notice_channels');
    });

    // @scenario flow=layout
    // @effects notification_templates_are_editable_in_plugin_settings
    it('알림 템플릿을 이 화면에서 편집한다 — 코어 화면은 코어 정의만 보여 준다', () => {
        const serialized = JSON.stringify(settings);
        // 확장 정의는 자기 설정 화면이 유일한 진입점이다(형제 확장 3종과 같은 구성).
        expect(serialized).toContain('extension_identifier=sirsoft-gdpr');
        expect(serialized).toContain('/api/admin/notification-definitions');
        expect(serialized).toContain('/api/admin/notification-channels');
        for (const partial of [
            '_modal_notification_template_edit.json',
            '_modal_notification_template_preview.json',
            '_modal_notification_definition_reset.json',
        ]) {
            expect(serialized).toContain(partial);
        }
        expect(findById(settings as unknown as AnyNode, 'card_gdpr_notifications')).toBeTruthy();
    });

    // @scenario flow=layout
    // @effects notification_templates_are_editable_in_plugin_settings
    it('알림 카드가 형제 확장과 같은 계약을 갖춘다 — 두 데이터소스 fallback·0건 안내·진입 대기', () => {
        const sources = (settings as unknown as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? [];
        const byId = (id: string) => sources.find((source) => source.id === id);

        // 채널·정의 조회가 실패해도 화면이 undefined 를 훑지 않도록 형제(koreaexim)와 같은 빈 값을 준다.
        expect(JSON.stringify(byId('gdprNotificationChannels')?.fallback)).toContain('"channels":[]');
        expect(JSON.stringify(byId('gdprNotificationDefinitions')?.fallback)).toContain('"data":[]');

        // 정의가 0건이면 카드가 말없이 비는데, 그 침묵은 "기능이 없다" 와 구분되지 않는다.
        expect(findById(settings as unknown as AnyNode, 'gdpr_notification_empty')).toBeTruthy();
        expect(JSON.stringify(settings)).toContain('sirsoft-gdpr.notification_definitions.empty_message');
        expect(ko.notification_definitions.empty_message).toBeTruthy();
        expect(en.notification_definitions.empty_message).toBeTruthy();

        // 설정만 기다리면 알림 카드가 데이터 도착 전에 빈 채로 먼저 그려진다(형제 2종이 같은 대기를 건다).
        const overlay = JSON.stringify((settings as unknown as Record<string, unknown>).transition_overlay ?? '');
        for (const id of ['gdprSettings', 'gdprNotificationDefinitions', 'gdprNotificationChannels']) {
            expect(overlay).toContain(id);
        }
    });

    // @scenario flow=layout
    // @effects settings_validated, self_test_only, history_paginated
    it('시험 발송은 본인 전용 API이며 성공·실패 양쪽에서 진행 상태를 해제한다', () => {
        const button = findById(settings as unknown as AnyNode, 'annual_notice_test');
        const serialized = JSON.stringify(button);
        expect(serialized).toContain('/api/plugins/sirsoft-gdpr/admin/annual-notice/test');
        expect(serialized).not.toContain('user_id');
        expect(serialized.match(/"noticeSending":false/g)).toHaveLength(2);
        expect(serialized).toContain('_local.hasChanges');
    });

    // @scenario flow=layout
    // @effects settings_validated, self_test_only, history_paginated
    it('이력은 서버 페이지와 전체 건수를 사용하고 채널 열을 모바일에서 숨긴다', () => {
        const grid = findById(history as unknown as AnyNode, 'gdpr_notice_log_grid');
        const serialized = JSON.stringify(grid);
        expect(serialized).toContain('serverSidePagination');
        expect(serialized).toContain('pagination?.last_page');
        expect(serialized).toContain('visibleColumns');
        expect(serialized).toContain('sirsoft-gdpr.annual_notice.empty');
        expect(JSON.stringify(history)).toContain('pagination?.total');
    });

    // @scenario flow=layout
    // @effects settings_validated, self_test_only, history_paginated
    it('모든 통지 화면 문구에 한국어와 영어가 있다', () => {
        const keys = [...JSON.stringify([settings, history]).matchAll(/sirsoft-gdpr\.annual_notice\.([a-z_]+)/g)].map(match => match[1]);
        for (const key of keys) {
            expect((ko.annual_notice as Record<string, string>)[key], key).toBeTruthy();
            expect((en.annual_notice as Record<string, string>)[key], key).toBeTruthy();
        }
    });
});

// @scenario flow=layout
// @effects history_paginated
it('이력 API 응답의 행과 서버 페이지 수를 실제 렌더러에 전달한다', async () => {
    const registry = ComponentRegistry.getInstance();
    const target = registry as unknown as { registry: Record<string, unknown> };
    const original = target.registry;
    const Box = ({ children }: { children?: React.ReactNode }) => <div>{children}</div>;
    const Grid = ({ data, serverCurrentPage, serverTotalPages }: { data: { status_label: string }[]; serverCurrentPage: number; serverTotalPages: number }) =>
        <section data-testid="notice-grid">{data.map((row, index) => <span key={index}>{row.status_label}</span>)}<b>{serverCurrentPage}/{serverTotalPages}</b></section>;
    target.registry = Object.fromEntries(Object.entries({ Fragment: Box, Div: Box, Span: Box, Button: Box, PageHeader: Box, DataGrid: Grid }).map(([name, component]) => [name, { component, metadata: { name, type: 'composite' } }]));
    const helper = createLayoutTest(history, { componentRegistry: registry, translations: { 'sirsoft-gdpr': ko }, locale: 'ko' });
    try {
        helper.mockApi('gdprNoticeLog', { response: { data: { data: [{ status_label: '발송 완료' }], pagination: { total: 21, current_page: 2, last_page: 2 } } } });
        await helper.render();
        expect(screen.getByTestId('notice-grid').textContent).toContain('발송 완료');
        expect(screen.getByTestId('notice-grid').textContent).toContain('2/2');
        helper.assertNoValidationErrors();
    } finally {
        helper.cleanup();
        target.registry = original;
    }
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
 * plugin.json 을 가진 확장 루트를 위로 훑어 찾는다.
 *
 * @returns 확장 루트 절대경로
 */
function pluginRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'plugin.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('plugin.json 을 가진 루트를 찾지 못했습니다.');
}

/**
 * 로케일별 다국어 묶음을 돌려준다.
 *
 * @param locale 로케일
 * @returns 다국어 JSON
 */
function gdprLang(locale: string): any {
    return locale === 'ko' ? ko : en;
}

const annualCard = findById(settings as AnyNode, 'card_annual_notice');

describe('개인정보 조치 대상 — 건수 상시 노출 + 대상 목록 창 (#636 §1-E)', () => {
    const viewButtons = collectWithAncestors(annualCard, (n) => /^view_.*targets$/.test(n.id ?? ''));

    /**
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
     */
    it('「대상 보기」 버튼이 있고, 권한으로만 게이트되며(0건이어도 보인다) 기능 토글 안에 갇히지 않는다', () => {
        // 모집단은 레이아웃에서 파생한다 — 버튼이 하나도 없으면 이 단언 자체가 공허해진다.
        expect(viewButtons.length).toBe(1);

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
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
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
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
     */
    it('명단 데이터소스는 스스로 조회하지 않고 한 쪽 20건을 받는다', () => {
        const dataSource = (settings as any).data_sources.find((d: any) => d.id === 'privacyTargets');

        expect(dataSource).toBeTruthy();
        expect(dataSource.endpoint.startsWith('/api/plugins/sirsoft-gdpr/admin/annual-notice/targets')).toBe(true);
        expect(dataSource.auto_fetch).toBe(false);
        expect(dataSource.params.per_page).toBe(20);
        expect(JSON.stringify(settings)).toContain('_modal_privacy_targets.json');
    });

    /**
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
     */
    it('창은 스크롤 끝에서 다음 쪽을 이어 붙이고, 마지막 쪽·진행 중에는 부르지 않는다', () => {
        const [[list]] = collectWithAncestors(targetsModal, (n) => n.id === 'privacy_targets_list');
        const scroll = actionsOf(list).find((a: any) => a.type === 'scroll');
        const sw = actionsOf(scroll).find((a: any) => a.handler === 'switch');
        const steps = actionsOf(sw.cases.true);
        const api = steps.find((a: any) => a.handler === 'apiCall');

        expect(sw.params.value).toContain('has_more_pages === true');
        expect(sw.params.value).toContain('!_global.privacyTargets?.isLoadingMore');
        expect(api.target.startsWith('/api/plugins/sirsoft-gdpr/admin/annual-notice/targets')).toBe(true);
        expect(api.params.query.page).toContain('currentPage');
        expect(callbacksOf(api, 'onSuccess')[0]).toMatchObject({ handler: 'appendDataSource', params: { dataSourceId: 'privacyTargets', dataPath: 'data.data' } });
        expect(JSON.stringify(callbacksOf(api, 'onSuccess'))).toContain('has_more_pages');
        // 실패해도 진행 중 표시를 끈다 — 남으면 다시는 다음 쪽을 부르지 않는다.
        expect(JSON.stringify(callbacksOf(api, 'onError'))).toContain('privacyTargets.isLoadingMore');
    });

    /**
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
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
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
     */
    it('버튼·창이 쓰는 대상 문구가 ko/en 모두에 있고 편집기 샘플이 명단 데이터소스를 가진다', () => {
        const raw = JSON.stringify(annualCard) + JSON.stringify(targetsModal);
        const keys = [...new Set([...raw.matchAll(/\$t:(sirsoft-gdpr\.annual_notice\.targets\.[a-z0-9_.]+)/g)].map((m) => m[1]))];

        expect(keys.length).toBeGreaterThan(3);
        for (const locale of ['ko', 'en']) {
            const bundle = gdprLang(locale);
            for (const key of keys) {
                expect(typeof readPath(bundle, key.replace(/^sirsoft-gdpr\./, '')), `${locale}:${key}`).toBe('string');
            }
        }

        const spec = fs.readFileSync(path.join(pluginRoot(), 'editor-spec.json'), 'utf-8');
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
    for (let depth = 0; depth < 10 && !fs.existsSync(path.join(current, 'plugin.json')); depth++) {
        current = path.dirname(current);
    }
    const lang = JSON.parse(fs.readFileSync(path.join(current, `resources/lang/${locale}.json`), 'utf-8'));

    return key.split('.').reduce((acc: any, part: string) => (acc == null ? undefined : acc[part]), lang);
}

describe('개인정보 조치 직권 실행 — 「지금 실행」 (#636 후속)', () => {
    const buttons: Array<[string, string]> = [['run_annual_notice', 'sirsoft-gdpr:annual_notice']];
    const runModal: any = runModalLayout;

    /**
     * @scenario flow=layout
     *
     * @effects manual_send_button_opens_the_confirm_modal
     */
    it('「지금 실행」은 실행 권한으로만 그리고, 저장 전 변경·진행 중 작업이면 막으며, 확인 창을 연 뒤 건수를 다시 센다', () => {
        for (const [id, key] of buttons) {
            const button = manualRunNode(settings, id);
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
     * @scenario flow=layout
     *
     * @effects manual_send_button_opens_the_confirm_modal
     */
    it('대상마다 한 행에 건수·대상 보기·지금 실행·결과 줄이 함께 있고, 실행은 채운 강조색을 쓰지 않는다', () => {
        // 2026-09-26 결정 — 코어 개인정보 탭과 같은 「대상 행」 양식. 실행 버튼이 자기 대상과 떨어지면 짝을 눈으로 맞춰야 한다.
        const rows: Array<[string, string, string, string]> = [
            ['annual_notice_target_row', 'view_annual_notice_targets', 'run_annual_notice', 'annual_notice_manual_run_status'],
        ];
        const hasBadge = (node: any): boolean =>
            !!node && typeof node === 'object'
            && (String(node.props?.className ?? '').includes('badge') || Object.values(node).some((child: any) => hasBadge(child)));

        for (const [rowId, viewId, runId, statusId] of rows) {
            const row = manualRunNode(settings, rowId);
            expect(row, rowId).toBeTruthy();
            for (const id of [viewId, runId, statusId]) {
                expect(manualRunNode(row, id), `${rowId} > ${id}`).toBeTruthy();
            }
            expect(hasBadge(row), rowId).toBe(true);
            expect(manualRunNode(row, runId).props.className, runId).not.toMatch(/btn-(warning|primary|danger)\b/);
        }
    });

    /**
     * @scenario flow=layout
     *
     * @effects manual_send_button_opens_the_confirm_modal
     */
    it('상태 소스는 이 화면 대상만 조회하고, 진행 중이면 폴링·끝나면 응답값으로만 건수를 다시 받는다', () => {
        const dataSource = (settings as any).data_sources.find((d: any) => d.id === 'privacyManualRuns');
        expect(dataSource.endpoint).toBe('/api/admin/privacy/manual-runs');
        expect(dataSource.params.targets.split(',')).toEqual(buttons.map(([, key]) => key));

        const handlers = callbacksOf(dataSource, 'onSuccess');
        expect(handlers.find((h: any) => h.handler === 'startInterval').if).toContain('has_active === true');
        expect(handlers.find((h: any) => h.handler === 'stopInterval').if).toContain('has_active !== true');
        const refetch = handlers.find((h: any) => h.handler === 'refetchDataSource');
        expect(refetch.params.dataSourceId).toBe('gdprAnnualNoticePreview');
        expect(refetch.if).toContain('finished_since === true');
        for (const handler of handlers) {
            expect(String(handler.if ?? ''), handler.handler).not.toContain('_global');
        }
    });

    /**
     * @scenario flow=layout
     *
     * @effects manual_send_button_opens_the_confirm_modal
     */
    it('확인 창은 코어 직권 실행 API 로 접수하고 이 확장의 문구(ko·en)만 쓴다', () => {
        const execute = manualRunNode(runModal, 'privacy_run_execute');
        const call = actionsOf(actionsOf(execute).find((a: any) => a.type === 'click')).find((a: any) => a.handler === 'apiCall');
        expect(call.params.method).toBe('POST');
        expect(call.target).toBe('/api/admin/privacy/manual-runs/{{_global.privacyRun?.key}}');
        expect(callbacksOf(call, 'onSuccess').filter((a: any) => a.handler === 'refetchDataSource').map((a: any) => a.params.dataSourceId))
            .toEqual(['privacyManualRuns', 'gdprAnnualNoticePreview']);
        expect(JSON.stringify(settings)).toContain('_modal_privacy_run.json');

        const source = JSON.stringify(runModal) + JSON.stringify(settings);
        expect(source).not.toContain('admin.settings.privacy.manual_run.');
        expect(source).not.toContain('skip_waiting_checkbox');
        const keys = new Set([...source.matchAll(/sirsoft-gdpr\.annual_notice\.manual_run\.([a-z_]+)/g)].map((m) => m[1]));
        if (keys.delete('modes')) {
            ['anonymize', 'delete', 'erase_ip'].forEach((mode) => keys.add(`modes.${mode}`));
        }
        expect(keys.size).toBeGreaterThan(10);
        for (const locale of ['ko', 'en']) {
            for (const key of keys) {
                expect(typeof manualRunLang(locale, `annual_notice.manual_run.${key}`), `${locale}:${key}`).toBe('string');
            }
        }
    });
});
