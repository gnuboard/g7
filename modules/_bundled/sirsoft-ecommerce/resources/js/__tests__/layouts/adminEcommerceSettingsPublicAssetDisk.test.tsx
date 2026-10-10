/**
 * 이커머스 설정 기본정보 탭 — 공개 자산 디스크 오버라이드 필드 테스트 (공개#100)
 *
 * @description
 * 모듈 오버라이드 필드는 저장 키(basic_info.public_asset_disk)와 설정 응답의
 * 카탈로그(available_public_asset_disks)를 바인딩해야 한다. 세 지점(defaults.json ·
 * StoreEcommerceSettingsRequest · 레이아웃 name)이 같은 키를 가리키지 않으면
 * 저장값이 조용히 버려지거나(whitelist 탈락) 화면만 있고 저장이 없는 상태가 된다.
 *
 * 계약 단언에 더해, 실제 카드 서브트리를 createLayoutTest 로 렌더링해
 * "코어 설정 따름" 선두 옵션 + 카탈로그 $localized 매핑 계산까지 검증한다.
 *
 * @effects settings_catalog_includes_plugin_registered_disks
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import tabBasicInfo from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_basic_info.json';
import mainLayout from '../../../layouts/admin/admin_ecommerce_settings.json';

// ─── 테스트용 컴포넌트 ───

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
    ({ className, children, 'data-testid': testId }) => (
        <div className={className} data-testid={testId}>{children}</div>
    );

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <label className={className}>{children || text}</label>;

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <span className={className}>{children || text}</span>;

const TestSelect: React.FC<{
    name?: string; value?: string; className?: string; options?: any[]; disabled?: boolean;
    error?: string; onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ name, value, className, options, disabled, onChange }) => (
    <select data-name={name} value={value} className={className} disabled={disabled} onChange={onChange}>
        {options?.map((o) => <option key={String(o.value)} value={o.value}>{o.label}</option>)}
    </select>
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
}

// ─── 다국어 (기본정보 탭 공개 자산 카드가 쓰는 키) ───

const translations = {
    'sirsoft-ecommerce': {
        admin: {
            settings: {
                basic_info: {
                    public_asset_storage: {
                        title: '공개 자산 스토리지',
                        description: '이 모듈의 이미지 서빙 디스크를 오버라이드합니다.',
                        disk: '공개 자산 디스크',
                        follow_core: '코어 설정 따름',
                        help: '미설정 시 코어 전역 설정을 따릅니다.',
                        unavailable: '저장했던 디스크({disk})를 지금은 쓸 수 없어 이미지를 스트리밍으로 제공하고 있습니다.',
                    },
                },
            },
        },
    },
};

/**
 * 모듈 루트(모듈 manifest 기준)를 위로 훑어 찾는다.
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

// 실제 카드 서브트리를 그대로 렌더 대상으로 쓴다 — 사본 표현식이 아닌 배포 JSON 자체가 SSoT.
const publicAssetCard = findNode(
    tabBasicInfo,
    (n) => n.id === 'public_asset_storage_card',
);

const CATALOG = [
    { id: 'none', label: { ko: '사용 안 함 (스트리밍)', en: 'None (streaming)' } },
    { id: 'fake_cdn', label: { ko: '가짜 CDN', en: 'Fake CDN' } },
];

/**
 * 카드 렌더링용 테스트 유틸을 생성한다.
 *
 * @param basicInfo 폼의 basic_info 초기값
 * @returns createLayoutTest 유틸
 */
function makeUtils(basicInfo: Record<string, unknown> = { public_asset_disk: '' }) {
    return createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test_ecommerce_public_asset_card',
            components: [publicAssetCard],
        } as any,
        {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'Admin', role: 'super_admin' },
                authType: 'admin',
            },
            translations,
            locale: 'ko',
            initialState: {
                _local: {
                    form: {
                        available_public_asset_disks: CATALOG,
                        basic_info: basicInfo,
                    },
                    errors: null,
                },
            },
        },
    );
}

describe('이커머스 공개 자산 디스크 오버라이드 필드 — 계약', () => {
    /**
     * Select 가 저장 키와 카탈로그를 바인딩하고 코어 따름 선택지를 앞에 둔다.
     *
     * @scenario disk_setting=public, override=module_override
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     */
    it('Select 가 저장 키와 카탈로그를 바인딩하고 코어 따름 기본 옵션을 갖는다', () => {
        const select = findNode(
            publicAssetCard,
            (n) => n.name === 'Select' && n.props?.name === 'basic_info.public_asset_disk',
        );

        expect(select).not.toBeNull();
        expect(select.props.options).toContain('available_public_asset_disks');
        expect(select.props.options).toContain('follow_core');
    });

    /**
     * 기본값·저장 규칙이 같은 키를 선언해 카탈로그 밖 값을 저장 단계에서 거를 수 있다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     *
     * @effects invalid_disk_rejected_with_422
     */
    it('defaults.json 과 저장 규칙이 같은 키를 선언한다', () => {
        const root = moduleRoot();
        const defaults = JSON.parse(
            fs.readFileSync(path.join(root, 'config/settings/defaults.json'), 'utf-8'),
        );
        const requestSource = fs.readFileSync(
            path.join(root, 'src/Http/Requests/Admin/StoreEcommerceSettingsRequest.php'),
            'utf-8',
        );

        expect(defaults.defaults.basic_info).toHaveProperty('public_asset_disk');
        expect(requestSource).toContain("'basic_info.public_asset_disk'");
    });

    /**
     * 모듈이 공개 자산 디스크를 고를 때 같은 설정 키를 읽는다.
     *
     * @scenario disk_setting=public, override=module_override
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     */
    it('module.php 오버라이드가 같은 설정 키를 조회한다', () => {
        const moduleSource = fs.readFileSync(path.join(moduleRoot(), 'module.php'), 'utf-8');

        expect(moduleSource).toContain("'basic_info.public_asset_disk'");
        expect(moduleSource).toContain('PUBLIC_ASSET_CATEGORIES');
    });
});

describe('이커머스 공개 자산 디스크 — 저장 후 안내', () => {
    /**
     * 설정 재조회는 폼에 없는 키를 지우지 않는다 — 저장 성공 처리가 안내 키를 저장 응답값으로 덮지 않으면
     * 새 디스크를 골라 저장해 저장값이 바뀐 뒤에도 「쓸 수 없는 디스크」 안내가 남았다(#703 브라우저 실측).
     * 탭별 저장이라 다른 탭을 저장할 때는 응답이 옛 이름을 그대로 실어 안내가 유지돼야 하므로 null 고정이 아니라 응답값이다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     * @effects stale_disk_normalized_with_notice
     */
    it('저장 성공 시 쓸 수 없는 디스크 안내 키를 저장 응답값으로 덮는다', () => {
        const saveButton = findNode((mainLayout as any).slots.content[0], (n) => n.id === 'save_button');
        const sequence = (saveButton?.actions ?? []).find((a: any) => a.handler === 'sequence');
        const apiCall = (sequence?.actions ?? []).find((a: any) => a.handler === 'apiCall');
        const localReset = (apiCall?.onSuccess ?? []).find(
            (a: any) => a.handler === 'setState' && a.params?.target === 'local',
        );

        expect(localReset?.params?.['form.basic_info.public_asset_disk_unavailable']).toBe(
            '{{response.data?.basic_info?.public_asset_disk_unavailable ?? null}}',
        );
    });
});

describe('이커머스 공개 자산 디스크 오버라이드 필드 — 렌더링', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        registry = setupTestRegistry();
        // 표현식 내 $t() 는 컨텍스트에 $templateId 가 없으면 __templateApp.getConfig()
        // 로 회수한다(engine-v1.38.2 폴백). layoutTestUtils 는 이를 세팅하지 않으므로
        // 테스트에서 동일 폴백 경로를 제공해야 옵션 계산식의 $t() 가 해석된다.
        (window as any).__templateApp = {
            getConfig: () => ({ templateId: 'test-template', locale: 'ko' }),
        };
    });

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
        (registry as any).registry = {};
        delete (window as any).__templateApp;
    });

    /**
     * 카드 제목·도움말이 번역되어 렌더된다.
     *
     * @scenario disk_setting=none, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     */
    it('카드 제목/도움말이 다국어 해석되어 렌더된다', async () => {
        testUtils = makeUtils();
        const { container } = await testUtils.render();

        expect(container.querySelector('h3.card-title')?.textContent ?? '').toContain(
            '공개 자산 스토리지',
        );
        expect(container.textContent ?? '').toContain('미설정 시 코어 전역 설정을 따릅니다.');
        expect(() => testUtils!.assertNoValidationErrors()).not.toThrow();
    });

    /**
     * 선택지가 코어 따름 + 카탈로그 디스크(플러그인 등록 포함)로 계산된다.
     *
     * @scenario disk_setting=fake_cdn, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     */
    it('Select 옵션이 코어 따름 선두 + 카탈로그 $localized 매핑으로 계산된다', async () => {
        testUtils = makeUtils();
        const { container } = await testUtils.render();

        const select = container.querySelector('select[data-name="basic_info.public_asset_disk"]');
        expect(select).not.toBeNull();

        const options = Array.from(select!.querySelectorAll('option'));
        expect(options.map((o) => o.getAttribute('value'))).toEqual(['', 'none', 'fake_cdn']);
        expect(options.map((o) => o.textContent)).toEqual([
            '코어 설정 따름',
            '사용 안 함 (스트리밍)',
            '가짜 CDN',
        ]);
    });

    /**
     * 저장했던 디스크를 쓸 수 없게 되면 서버가 값을 스트리밍(`none`)으로 바꿔 내려주고 옛 이름을 알린다 —
     * 화면은 그 이름을 안내로 보여 줘야 값이 왜 바뀌었는지 안다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     *
     * @effects stale_disk_normalized_with_notice
     */
    it('쓸 수 없게 된 디스크면 옛 디스크 이름과 스트리밍 안내가 나온다', async () => {
        testUtils = makeUtils({ public_asset_disk: 'none', public_asset_disk_unavailable: 'gone_cdn' });
        const { container } = await testUtils.render();

        expect(container.textContent ?? '').toContain(
            '저장했던 디스크(gone_cdn)를 지금은 쓸 수 없어 이미지를 스트리밍으로 제공하고 있습니다.',
        );
    });

    /**
     * 카탈로그에 있는 디스크나 「코어 설정 따름」이면 안내가 나오지 않는다.
     *
     * @scenario disk_setting=public, override=follow_core
     *
     * @effects stale_disk_normalized_with_notice
     */
    it('사용할 수 있는 디스크면 안내가 나오지 않는다', async () => {
        testUtils = makeUtils({ public_asset_disk: 'fake_cdn' });
        const { container } = await testUtils.render();

        expect(container.textContent ?? '').not.toContain('지금은 쓸 수 없어');
    });
});
