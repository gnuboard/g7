/**
 * 드라이버 탭 — 공개 자산 스토리지 카드 테스트 (공개#100)
 *
 * @description
 * 신규 "공개 자산 스토리지" 카드는 storage 카드(#99 작업 구역)의 **형제**로 존재해야
 * 하며, Select 는 저장 키(drivers.public_asset_disk)와 서버 카탈로그
 * (available_drivers.public_asset)를 정확히 바인딩해야 한다. 화면 옵션이 카탈로그를
 * 벗어나면 저장 단계(레지스트리 조회 closure rule)에서 422 가 된다.
 *
 * 계약 단언(JSON 구조 + 저장 규칙 대조)에 더해, 실제 카드 서브트리를
 * createLayoutTest 로 렌더링해 옵션 계산식($localized 매핑)과 s3 도움말 조건부
 * 렌더링까지 검증한다.
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

import tabDrivers from '../../layouts/partials/admin_settings/_tab_drivers.json';

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
// 미등록 시 트리 전체가 렌더되지 않아 "없어야 한다" 계열 단언이 거짓 통과한다.
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

// ─── 다국어 (드라이버 탭 공개 자산 카드가 쓰는 키) ───

const translations = {
    admin: {
        settings: {
            drivers: {
                public_asset: {
                    title: '공개 자산 스토리지',
                    desc: '완전 공개 자산의 직접 URL 서빙 디스크를 설정합니다.',
                    disk: '공개 자산 디스크',
                    s3_help: '스토리지 카드의 S3 URL 설정이 필요합니다.',
                },
            },
        },
    },
};

/**
 * 저장소 루트를 위로 훑어 찾는다.
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'config/core.php'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('저장소 루트를 찾지 못했습니다.');
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
const publicAssetCard = (tabDrivers as any).children.find(
    (c: any) => c.id === 'card_public_asset_settings',
);

const CATALOG = [
    { id: 'none', label: { ko: '사용 안 함 (스트리밍)', en: 'None (streaming)' } },
    { id: 'public', label: { ko: '로컬 공개 디스크', en: 'Local public disk' } },
    { id: 'fake_cdn', label: { ko: '가짜 CDN', en: 'Fake CDN' } },
];

/**
 * 카드 렌더링용 테스트 유틸을 생성한다.
 *
 * @param selectedDisk 현재 저장된 공개 자산 디스크 값
 * @returns createLayoutTest 유틸
 */
function makeUtils(selectedDisk: string) {
    return createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test_public_asset_storage_card',
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
                        available_drivers: { public_asset: CATALOG },
                        drivers: { public_asset_disk: selectedDisk },
                    },
                    errors: null,
                },
            },
        },
    );
}

describe('드라이버 탭 공개 자산 스토리지 카드 — 계약', () => {
    it('storage 카드의 형제로 카드가 존재한다 (#99 s3 블록 비접촉)', () => {
        const ids = (tabDrivers as any).children.map((c: any) => c.id);

        expect(ids).toContain('card_storage_settings');
        expect(ids).toContain('card_public_asset_settings');
        // storage 카드 내부가 아닌 최상위 형제 배열에 위치
        expect(ids.indexOf('card_public_asset_settings')).toBeGreaterThan(
            ids.indexOf('card_storage_settings'),
        );
    });

    it('Select 가 저장 키와 카탈로그를 정확히 바인딩한다', () => {
        const select = findNode(publicAssetCard, (n) => n.name === 'Select');

        expect(select).not.toBeNull();
        expect(select.props.name).toBe('drivers.public_asset_disk');
        expect(select.props.options).toContain('available_drivers?.public_asset');
        expect(select.props.error).toContain("['drivers.public_asset_disk']");
    });

    it('s3 선택 시에만 도움말이 노출된다', () => {
        const help = findNode(publicAssetCard, (n) => n.id === 'public_asset_s3_help');

        expect(help).not.toBeNull();
        expect(help.if).toContain("public_asset_disk === 's3'");
    });

    it('저장 규칙(SaveSettingsRequest)이 같은 키를 검증한다', () => {
        const requestSource = fs.readFileSync(
            path.join(repositoryRoot(), 'app/Http/Requests/Settings/SaveSettingsRequest.php'),
            'utf-8',
        );

        expect(requestSource).toContain("'drivers.public_asset_disk'");
        expect(requestSource).toContain("isDriverAvailable('public_asset'");
    });
});

describe('드라이버 탭 공개 자산 스토리지 카드 — 렌더링', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        registry = setupTestRegistry();
    });

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
        (registry as any).registry = {};
    });

    it('카드 제목/라벨이 다국어 해석되어 렌더된다', async () => {
        testUtils = makeUtils('public');
        const { container } = await testUtils.render();

        expect(container.querySelector('h3.card-title')?.textContent ?? '').toContain(
            '공개 자산 스토리지',
        );
        expect(container.querySelector('label.form-label')?.textContent ?? '').toContain(
            '공개 자산 디스크',
        );
        expect(() => testUtils!.assertNoValidationErrors()).not.toThrow();
    });

    it('Select 옵션이 서버 카탈로그에서 $localized 매핑으로 계산된다', async () => {
        testUtils = makeUtils('public');
        const { container } = await testUtils.render();

        const select = container.querySelector('select[data-name="drivers.public_asset_disk"]');
        expect(select).not.toBeNull();

        const options = Array.from(select!.querySelectorAll('option'));
        expect(options.map((o) => o.getAttribute('value'))).toEqual([
            'none',
            'public',
            'fake_cdn',
        ]);
        // $localized(d.label) — 활성 로케일(ko) 라벨로 해석되어야 한다
        expect(options.map((o) => o.textContent)).toEqual([
            '사용 안 함 (스트리밍)',
            '로컬 공개 디스크',
            '가짜 CDN',
        ]);
    });

    it('s3 미선택 시 도움말이 렌더되지 않는다', async () => {
        testUtils = makeUtils('public');
        const { container } = await testUtils.render();

        // 긍정 앵커 — 카드가 실제로 렌더됐음을 먼저 확인해야 아래 부정 단언이 의미를 갖는다
        expect(container.querySelector('select[data-name="drivers.public_asset_disk"]')).not.toBeNull();
        expect(container.textContent ?? '').not.toContain('S3 URL 설정');
    });

    it('s3 선택 시 도움말이 렌더된다', async () => {
        testUtils = makeUtils('s3');
        const { container } = await testUtils.render();

        expect(container.textContent ?? '').toContain('스토리지 카드의 S3 URL 설정이 필요합니다.');
    });
});
