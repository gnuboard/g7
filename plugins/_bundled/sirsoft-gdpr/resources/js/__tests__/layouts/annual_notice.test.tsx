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
        const keys = [...JSON.stringify([settings, history]).matchAll(/sirsoft-gdpr.annual_notice.([a-z_]+)/g)].map(match => match[1]);
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
    it('「대상 보기」 버튼이 있고, 모두 권한·건수로 게이트되며 기능 토글 안에 갇히지 않는다', () => {
        // 모집단은 레이아웃에서 파생한다 — 버튼이 하나도 없으면 이 단언 자체가 공허해진다.
        expect(viewButtons.length).toBe(1);

        for (const [button, ancestors] of viewButtons) {
            expect(button.if, button.id).toContain('can_view_targets');
            expect(button.if, button.id).toMatch(/> 0/);
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
