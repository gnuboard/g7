/**
 * @file admin_settings-extra-permission-sources.test.tsx
 * @description 환경설정 — 설정 조회 외 권한이 필요한 목록·링크가, 그 권한이 없는 관리자에게 「없음」으로 그려지지 않는다
 *
 * 결함(큐 탭 권한 결함과 같은 꼴): 환경설정 화면의 레이아웃 권한은 설정 조회(core.settings.read) 하나인데,
 *  - 언어팩 탭의 `language_packs`(core.language_packs.read)·`unplaced_bundled_notice`, 본인인증 탭의 `identityProviders`
 *    (core.admin.identity.providers.read)·`policies`·`adminIdentityPolicies`(core.admin.identity.policies.read)는 403 처리 없이
 *    fallback 만 있어, 권한이 없는 관리자에게 403 이 **성공(빈 목록)** 으로 처리됐다 — 「설치된 언어팩이 없습니다.」·
 *    「등록된 프로바이더가 없습니다.」·「등록된 정책이 없습니다.」·「운영자 정책이 없습니다. … 먼저 등록해 주세요.」로 그렸고,
 *    엔진 기본 처리(toast)가 화면마다 오류 알림을 띄웠다.
 *  - 드라이버 탭 「처리 기록 보기」(큐 탭으로 가는 링크)와 설정 탭 곳곳의 「스케줄 관리에서 변경」 링크가 큐 작업 조회·스케줄 조회
 *    권한과 무관하게 보여, 누르면 권한 안내·403 화면으로 갔다.
 *
 * 고침: 다섯 데이터소스의 403 은 조용히 넘기고(suppress), 계산값(current_user 권한, 받기 전 null)이 거짓이면 그 목록 자리에
 * 「빈 목록」 대신 권한 안내를 보인다. 언어팩 탭은 탭 자체가 언어팩 목록이라 탭 항목을 숨기고(직접 진입은 권한 안내),
 * 링크는 판정이 거짓이면 숨긴다(큐 = 베이스가 올린 `_global.adminQueueJobsReadable`, 스케줄 = 계산값 `canReadSchedules`).
 *
 * 렌더 방식: 배포 조각 JSON 을 그대로(하위 조각은 배포 JSON 으로 끼움) 실제 엔진 하네스(createLayoutTest)로 그린다.
 * 데이터소스는 엔진 조건 판정(DataSourceManager.filterByCondition)으로 고른 것을 하네스가 받고, 권한 없는 소스는 403 으로 실패시킨다
 * (하네스는 실제 엔진과 같이 fallback 이 있으면 그 값을 싣는다). 계산값은 `admin_settings.json` 의 정의를 그대로 쓴다.
 *
 * 시나리오 매니페스트: settings-extra-permission-sources (tests/scenarios — test_files 귀속)
 * @axes permission=missing, permission=granted
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { ErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';

const settingsLayout = require('../../layouts/admin_settings.json');
const koAdmin = require('../../lang/partial/ko/admin.json');
const enAdmin = require('../../lang/partial/en/admin.json');
const koCommon = require('../../lang/partial/ko/common.json');
const koExtensions = require('../../lang/partial/ko/extensions.json');

const SOURCES = ['language_packs', 'unplaced_bundled_notice', 'identityProviders', 'policies', 'adminIdentityPolicies'];

/** 데이터소스 → 필요한 권한 */
const NEEDS: Record<string, string> = {
    language_packs: 'core.language_packs.read',
    unplaced_bundled_notice: 'core.language_packs.read',
    identityProviders: 'core.admin.identity.providers.read',
    policies: 'core.admin.identity.policies.read',
    adminIdentityPolicies: 'core.admin.identity.policies.read',
};

const TEXT = {
    lpEmpty: koAdmin.language_packs.messages.empty,
    providersEmpty: koAdmin.settings.identity.providers.empty,
    policiesEmpty: koAdmin.identity.policies.empty,
    policyEmptyInModal: koAdmin.settings.notification_definitions.identity_add.policy_empty,
    lpDenied: koAdmin.settings.permission_required?.language_packs,
    providersDenied: koAdmin.settings.permission_required?.identity_providers,
    policiesDenied: koAdmin.settings.permission_required?.identity_policies,
};

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
        const { meta, layout_name, comment, description, ...root } = require(`../../layouts/${node.partial}`);
        return inlinePartials(root);
    }
    const out: Record<string, any> = {};
    for (const [key, value] of Object.entries(node)) out[key] = inlinePartials(value);
    return out;
}

/** 조각 파일 하나의 뿌리 노드 */
function partialRoot(file: string): any {
    return inlinePartials({ partial: file });
}

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

/** 관리자 본인 응답(_admin_base 의 current_user) */
function currentUser(permissions: string[]): any {
    return { success: true, data: { uuid: 'u-1', permissions: permissions.map((identifier, i) => ({ id: i + 1, identifier })) } };
}

const SETTINGS_ONLY = currentUser(['core.settings.read', 'core.settings.update']);
const ALL = currentUser(['core.settings.read', 'core.settings.update', 'core.language_packs.read', 'core.admin.identity.providers.read', 'core.admin.identity.policies.read', 'core.schedules.read', 'core.queue_jobs.read']);

const RESPONSES: Record<string, any> = {
    language_packs: { data: { data: [{ id: 1, identifier: 'g7-core-ja', name: '日本語' }], meta: { total: 1, active: 1, installed: 1, inactive: 0, error: 0 }, abilities: {} } },
    unplaced_bundled_notice: { data: { items: { modules: [], plugins: [], templates: [], language_packs: [] } } },
    identityProviders: { data: [{ id: 'g7:core.mail', label: '메일', is_available: true, channels: ['mail'] }] },
    policies: { data: { data: [{ id: 1, key: 'core.login', purpose: 'login', scope: 'route', source_type: 'admin', enabled: true }], meta: { total: 1, current_page: 1, last_page: 1 } } },
    adminIdentityPolicies: { data: { data: [{ key: 'core.login' }] } },
};

interface Rendered {
    harness: ReturnType<typeof createLayoutTest>;
    container: HTMLElement;
    text: string;
    tabs: any[] | null;
}

/**
 * 환경설정 조각을 실제 엔진 하네스로 그립니다. 권한 없는 소스는 403 으로 실패합니다.
 *
 * @param tab 지금 탭
 * @param components 그릴 노드
 * @param user current_user 값
 * @param global 전역 상태(베이스 판정 등)
 * @returns 렌더 결과
 */
async function renderSettings(tab: string, components: any[], user: any, global: Record<string, any> = {}): Promise<Rendered> {
    const captured: { tabs: any[] | null } = { tabs: null };
    const registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'TabNavigation', (props: any) => {
        if (Array.isArray(props.tabs) && props.tabs.some((t: any) => t.id === 'language_packs')) captured.tabs = props.tabs;
        return null;
    });
    // 창은 열린 상태로 본문만 그린다(창 자체의 열림·닫힘은 이 시험의 관심이 아니다)
    registry.register('composite', 'Modal', ({ children }: any) => React.createElement('div', { 'data-testid': 'modal' }, children));
    const owned = new Set((user?.data?.permissions ?? []).map((p: any) => p.identifier));
    const sources = new DataSourceManager()
        .filterByCondition(settingsLayout.data_sources ?? [], { route: {}, query: { tab }, _global: { ...global, activeSettingsTab: tab } } as any)
        .filter((d: any) => SOURCES.includes(d.id));

    const harness = createLayoutTest(
        { version: '1.0.0', layout_name: 'admin_settings_permission_probe', computed: settingsLayout.computed, data_sources: sources, components } as any,
        {
            componentRegistry: registry as any,
            translations: { admin: koAdmin, common: koCommon, extensions: koExtensions },
            locale: 'ko',
            routePath: '/admin/settings',
            queryParams: { tab },
            // 안내 상자가 켜짐 조건 뒤에 있는 탭(GeoIP 자동 갱신·비밀번호 만료)은 그 설정을 켠 폼으로 그린다
            initialState: { _global: { ...global, activeSettingsTab: tab }, _local: { form: { advanced: { geoip_enabled: true, geoip_auto_update_enabled: true }, security: { password_expiry_enabled: true } } } },
            initialData: { current_user: user },
        },
    );
    for (const ds of sources) {
        if (owned.has(NEEDS[ds.id])) harness.mockApi(ds.id, { response: RESPONSES[ds.id] });
        else harness.mockApiError(ds.id, 403, '권한이 없습니다.');
    }
    const { container } = await harness.render();

    return { harness, container, text: container.textContent ?? '', tabs: captured.tabs };
}

/** id 가 붙은 요소가 그려졌는지 */
function has(container: HTMLElement, id: string): boolean {
    return container.querySelector(`[id="${id}"]`) !== null;
}

describe('환경설정 — 설정 조회 외 권한이 필요한 목록', () => {
    let current: Rendered | undefined;

    afterEach(() => {
        cleanup();
        current?.harness.cleanup();
        current = undefined;
    });

    // @scenario permission=missing
    // @effects permission_notice_keys_exist
    it('새 문구 키가 ko·en 둘 다 있다', () => {
        for (const dict of [koAdmin, enAdmin]) {
            for (const key of ['language_packs', 'identity_providers', 'identity_policies']) {
                expect(dict.settings.permission_required?.[key], key).toBeTruthy();
            }
        }
    });

    // @scenario permission=missing
    // @effects forbidden_source_is_quiet
    it('다섯 데이터소스의 403 은 조용히 넘긴다(전역 오류 알림 없음)', () => {
        const resolver = new ErrorHandlingResolver();
        for (const id of SOURCES) {
            const ds = (settingsLayout.data_sources ?? []).find((d: any) => d.id === id);
            expect(resolver.resolve(403, { errorHandling: ds?.errorHandling, onError: ds?.onError }).handler?.handler, id).toBe('suppress');
        }
    });

    // @scenario permission=missing
    // @effects forbidden_list_shows_permission_notice
    it('언어팩 조회 권한이 없으면 언어팩 탭 항목이 숨고, 직접 들어오면 「설치된 언어팩이 없습니다.」 대신 권한 안내', async () => {
        const tabBar = byId(settingsLayout.slots, 'tab_navigation');
        current = await renderSettings('language_packs', [tabBar, partialRoot('partials/admin_settings/_tab_language_packs.json')], SETTINGS_ONLY);
        expect(current.tabs, '탭 막대 props').toBeTruthy();
        const lpTab = (current.tabs ?? []).find((t: any) => t.id === 'language_packs');
        // 결함: 403 을 빈 목록으로 그린다
        expect(current.text).not.toContain(TEXT.lpEmpty);
        expect(lpTab && Object.prototype.hasOwnProperty.call(lpTab, 'if') && !lpTab.if, '언어팩 탭 항목이 숨는다').toBe(true);
        expect(TEXT.lpDenied).toBeTruthy();
        expect(current.text).toContain(TEXT.lpDenied);
    });

    // @scenario permission=missing
    // @effects forbidden_list_shows_permission_notice
    it('본인인증 제공자·정책 조회 권한이 없으면 「등록된 프로바이더/정책이 없습니다.」 대신 권한 안내', async () => {
        current = await renderSettings('identity', [partialRoot('partials/admin_settings/_tab_identity.json')], SETTINGS_ONLY);
        // 결함: 403 을 빈 목록으로 그린다
        expect(current.text).not.toContain(TEXT.providersEmpty);
        expect(current.text).not.toContain(TEXT.policiesEmpty);
        expect(TEXT.providersDenied).toBeTruthy();
        expect(TEXT.policiesDenied).toBeTruthy();
        expect(current.text).toContain(TEXT.providersDenied);
        expect(current.text).toContain(TEXT.policiesDenied);
    });

    // @scenario permission=missing
    // @effects forbidden_list_shows_permission_notice
    it('알림 설정 「정책별 문구 추가」 창: 정책 조회 권한이 없으면 「운영자 정책이 없습니다 … 먼저 등록해 주세요」 대신 권한 안내', async () => {
        current = await renderSettings('notification_definitions', [partialRoot('partials/admin_settings/_modal_notification_identity_definition_add.json')], SETTINGS_ONLY, {
            identity_notification_add_modal: { form: { policy_key: '' } },
        });
        expect(current.container.querySelector('[data-testid="modal"]'), '창 본문').not.toBeNull();
        expect(current.text).not.toContain(TEXT.policyEmptyInModal);
        expect(current.text).toContain(TEXT.policiesDenied ?? '권한 안내 키 없음');
    });

    // @scenario permission=granted
    // @effects granted_lists_unchanged
    it('권한이 있으면 종전대로 — 언어팩 탭 항목이 보이고 목록·제공자·정책이 그려지며 권한 안내는 없다', async () => {
        const tabBar = byId(settingsLayout.slots, 'tab_navigation');
        current = await renderSettings('language_packs', [tabBar, partialRoot('partials/admin_settings/_tab_language_packs.json')], ALL);
        const lpTab = (current.tabs ?? []).find((t: any) => t.id === 'language_packs');
        expect(lpTab?.if === undefined || !!lpTab?.if).toBe(true);
        expect(current.text).not.toContain(TEXT.lpEmpty);
        if (TEXT.lpDenied) expect(current.text).not.toContain(TEXT.lpDenied);
        cleanup();
        current.harness.cleanup();

        current = await renderSettings('identity', [partialRoot('partials/admin_settings/_tab_identity.json')], ALL);
        expect(current.text).toContain('메일');
        expect(current.text).not.toContain(TEXT.providersEmpty);
        if (TEXT.providersDenied) expect(current.text).not.toContain(TEXT.providersDenied);
        if (TEXT.policiesDenied) expect(current.text).not.toContain(TEXT.policiesDenied);
    });
});

/** [탭, 조각, 링크 id, 필요한 판정] */
const LINKS: Array<[string, string, string, 'queue' | 'schedule']> = [
    ['drivers', 'partials/admin_settings/_tab_drivers.json', 'queue_records_link', 'queue'],
    ['drivers', 'partials/admin_settings/_tab_drivers.json', 'queue_records_schedule_notice_link', 'schedule'],
    ['advanced', 'partials/admin_settings/_tab_advanced.json', 'geoip_schedule_notice_link', 'schedule'],
    ['privacy', 'partials/admin_settings/_tab_privacy.json', 'withdrawn_schedule_notice_link', 'schedule'],
    ['security', 'partials/admin_settings/_tab_security_activity_log.json', 'activity_log_schedule_notice_link', 'schedule'],
    ['security', 'partials/admin_settings/_tab_security_password_policy.json', 'password_expiry_schedule_notice_link', 'schedule'],
    ['seo', 'partials/admin_settings/_tab_seo.json', 'sitemap_schedule_link', 'schedule'],
    ['upload', 'partials/admin_settings/_tab_upload.json', 'orphan_cleanup_schedule_notice_link', 'schedule'],
];

describe('환경설정 — 큐·스케줄 화면으로 가는 링크', () => {
    let current: Rendered | undefined;

    afterEach(() => {
        cleanup();
        current?.harness.cleanup();
        current = undefined;
    });

    // @scenario permission=missing
    // @effects forbidden_link_hidden
    it.each(LINKS)('%s 탭 %s: 권한이 없으면 %s 를 숨기고, 있으면 보인다', async (tab, file, linkId, kind) => {
        const denied = kind === 'queue'
            ? await renderSettings(tab, [partialRoot(file)], currentUser(['core.settings.read', 'core.schedules.read']), { adminQueueJobsReadable: false })
            : await renderSettings(tab, [partialRoot(file)], currentUser(['core.settings.read', 'core.queue_jobs.read']), { adminQueueJobsReadable: true });
        current = denied;
        expect(has(denied.container, linkId), `${linkId} 숨김`).toBe(false);
        cleanup();
        denied.harness.cleanup();

        current = await renderSettings(tab, [partialRoot(file)], ALL, { adminQueueJobsReadable: true });
        expect(has(current.container, linkId), `${linkId} 보임`).toBe(true);
    });
});
