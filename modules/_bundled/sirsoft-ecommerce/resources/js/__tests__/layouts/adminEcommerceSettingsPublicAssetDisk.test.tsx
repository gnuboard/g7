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
 * @returns createLayoutTest 유틸
 */
function makeUtils() {
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
                        basic_info: { public_asset_disk: '' },
                    },
                    errors: null,
                },
            },
        },
    );
}

describe('이커머스 공개 자산 디스크 오버라이드 필드 — 계약', () => {
    it('Select 가 저장 키와 카탈로그를 바인딩하고 코어 따름 기본 옵션을 갖는다', () => {
        const select = findNode(
            publicAssetCard,
            (n) => n.name === 'Select' && n.props?.name === 'basic_info.public_asset_disk',
        );

        expect(select).not.toBeNull();
        expect(select.props.options).toContain('available_public_asset_disks');
        expect(select.props.options).toContain('follow_core');
    });

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

    it('module.php 오버라이드가 같은 설정 키를 조회한다', () => {
        const moduleSource = fs.readFileSync(path.join(moduleRoot(), 'module.php'), 'utf-8');

        expect(moduleSource).toContain("'basic_info.public_asset_disk'");
        expect(moduleSource).toContain('PUBLIC_ASSET_CATEGORIES');
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

    it('카드 제목/도움말이 다국어 해석되어 렌더된다', async () => {
        testUtils = makeUtils();
        const { container } = await testUtils.render();

        expect(container.querySelector('h3.card-title')?.textContent ?? '').toContain(
            '공개 자산 스토리지',
        );
        expect(container.textContent ?? '').toContain('미설정 시 코어 전역 설정을 따릅니다.');
        expect(() => testUtils!.assertNoValidationErrors()).not.toThrow();
    });

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
});
