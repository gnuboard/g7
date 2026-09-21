/**
 * 연간 통지 폼의 저장 경로와 시험 발송 수명주기, 이력 페이지 계약.
 * @scenario flow=layout
 * @effects settings_validated, self_test_only, history_paginated
 */
import React from 'react';
import { describe, expect, it } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import settings from '../../../layouts/admin/plugin_settings.json';
import history from '../../../layouts/admin/gdpr_notice_log.json';
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
