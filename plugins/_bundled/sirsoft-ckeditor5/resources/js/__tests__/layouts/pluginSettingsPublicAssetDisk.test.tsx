/**
 * CKEditor5 설정 화면 — 공개 자산 디스크 필드 테스트 (공개#100)
 *
 * @description
 * 플러그인 오버라이드 필드는 저장 키(public_asset_disk)와 **플러그인 설정 응답에
 * 서버 부착되는** 카탈로그(available_public_asset_disks)를 바인딩해야 한다.
 * 별도 코어 설정 API(/api/admin/settings, core.settings.read)를 교차 조회하면
 * 화면 권한(core.plugins.read)과 표면이 갈려 커스텀 역할에서 카탈로그가 조용히
 * 빈다 — 단일 표면 계약을 고정한다. 설정 스키마(getSettingsSchema)에 같은 키가
 * 없으면 저장 검증이 규칙을 만들지 않아 값이 조용히 버려진다 — 세 지점을 대조한다.
 *
 * 계약 단언에 더해, 실제 필드 서브트리를 createLayoutTest 로 렌더링해
 * 옵션 계산식($t 선두 + $localized 매핑)까지 검증한다.
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

import pluginSettings from '../../../layouts/admin/plugin_settings.json';

// ─── 테스트용 컴포넌트 ───

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
    ({ className, children, 'data-testid': testId }) => (
        <div className={className} data-testid={testId}>{children}</div>
    );

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <label className={className}>{children || text}</label>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestSelect: React.FC<{
    name?: string; value?: string; className?: string; options?: any[];
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ name, value, className, options, onChange }) => (
    <select data-name={name} value={value} className={className} onChange={onChange}>
        {options?.map((o) => <option key={String(o.value)} value={o.value}>{o.label}</option>)}
    </select>
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
}

// ─── 다국어 (공개 자산 디스크 필드가 쓰는 키) ───

const translations = {
    'sirsoft-ckeditor5': {
        settings: {
            fields: {
                public_asset_disk: {
                    label: '공개 자산 디스크',
                    hint: '에디터 이미지 서빙 디스크를 오버라이드합니다.',
                    follow_core: '코어 설정 따름',
                },
            },
        },
    },
};

/**
 * 플러그인 루트(plugin.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 플러그인 루트 절대경로
 */
function pluginRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'plugin.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('plugin.json 을 가진 플러그인 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다 (slots 포함).
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    const childGroups = [node.children ?? [], ...(node.slots ? Object.values(node.slots) : [])];
    for (const group of childGroups) {
        for (const child of group as any[]) {
            const found = findNode(child, predicate);
            if (found) return found;
        }
    }

    return null;
}

// 실제 필드 서브트리를 그대로 렌더 대상으로 쓴다 — 사본 표현식이 아닌 배포 JSON 자체가 SSoT.
const publicAssetField = findNode(
    pluginSettings,
    (n) => n.id === 'field_public_asset_disk',
);

const CATALOG = [
    { id: 'none', label: { ko: '사용 안 함 (스트리밍)', en: 'None (streaming)' } },
    { id: 'fake_cdn', label: { ko: '가짜 CDN', en: 'Fake CDN' } },
];

describe('CKEditor5 공개 자산 디스크 필드 — 계약', () => {
    it('코어 설정 API 교차 조회 데이터소스가 없다 (권한 표면 단일화)', () => {
        const dataSources = (pluginSettings as any).data_sources;

        // 존재 앵커 먼저 — data_sources 가 비어 있으면 아래 부재 단언이 공허하게 통과한다
        expect(dataSources.find((d: any) => d.id === 'settings')).toBeDefined();

        // 카탈로그는 플러그인 설정 응답에 서버 부착 — 화면 권한(core.plugins.read)과
        // 다른 권한(core.settings.read)을 요구하는 교차 조회가 남아 있으면 안 된다
        expect(dataSources.find((d: any) => d.endpoint === '/api/admin/settings')).toBeUndefined();
    });

    it('Select 가 저장 키와 설정 응답 부착 카탈로그를 바인딩한다', () => {
        const select = findNode(
            publicAssetField,
            (n) => n.name === 'Select' && n.props?.name === 'public_asset_disk',
        );

        expect(select).not.toBeNull();
        expect(select.props.options).toContain('_local.form?.available_public_asset_disks');
        expect(select.props.options).toContain('follow_core');
    });

    it('설정 스키마(getSettingsSchema)가 같은 키를 선언한다 (저장 whitelist)', () => {
        const pluginSource = fs.readFileSync(path.join(pluginRoot(), 'plugin.php'), 'utf-8');
        const defaults = JSON.parse(
            fs.readFileSync(path.join(pluginRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );

        expect(pluginSource).toContain("'public_asset_disk' => [");
        expect(defaults.defaults).toHaveProperty('public_asset_disk');
    });
});

describe('CKEditor5 공개 자산 디스크 필드 — 렌더링', () => {
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
     * 필드 렌더링용 테스트 유틸을 생성한다.
     *
     * @returns createLayoutTest 유틸
     */
    function makeUtils() {
        return createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'test_ckeditor5_public_asset_field',
                components: [publicAssetField],
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
                        // 플러그인 설정 GET 응답(initLocal: form)에 서버 부착되는 형태
                        form: {
                            public_asset_disk: '',
                            available_public_asset_disks: CATALOG,
                        },
                    },
                },
            },
        );
    }

    it('라벨/힌트가 다국어 해석되어 렌더된다', async () => {
        testUtils = makeUtils();
        const { container } = await testUtils.render();

        expect(container.querySelector('label.form-label')?.textContent ?? '').toContain(
            '공개 자산 디스크',
        );
        expect(container.querySelector('p.form-hint')?.textContent ?? '').toContain(
            '에디터 이미지 서빙 디스크를 오버라이드합니다.',
        );
        expect(() => testUtils!.assertNoValidationErrors()).not.toThrow();
    });

    it('설정 응답 부착 카탈로그가 옵션으로 계산된다 (코어 따름 선두 + $localized 매핑)', async () => {
        testUtils = makeUtils();
        const { container } = await testUtils.render();

        const select = container.querySelector('select[data-name="public_asset_disk"]');
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
