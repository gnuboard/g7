/**
 * CKEditor5 설정 화면 — 미사용 이미지 정리 섹션 테스트 (공개 #115)
 *
 * @description
 * 자동 정리는 사용자 파일을 지우므로 기본 꺼짐이다. 화면이 그 계약을 지키는지
 * (저장 키 · 기본값 · 스키마 세 지점 일치)와, 라벨/힌트가 실제로 렌더되는지를 고정한다.
 *
 * @effects settings_cleanup_toggle_default_off, settings_cleanup_keys_declared
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import pluginSettings from '../../../layouts/admin/plugin_settings.json';

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

    const groups = [node.children ?? [], ...(node.slots ? Object.values(node.slots) : [])];
    for (const group of groups) {
        for (const child of group as any[]) {
            const found = findNode(child, predicate);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 이 화면을 렌더하는 admin 템플릿의 매니페스트가 선언한 컴포넌트 종류를 읽는다.
 *
 * 레이아웃 노드의 `type` 은 렌더러가 DOM 안전 필터링을 고르는 기준이라
 * 매니페스트 선언과 어긋나면 `editorAttrs` 가 제거된다(DynamicRenderer).
 *
 * @param name 컴포넌트 이름
 * @returns 매니페스트가 선언한 type
 */
function manifestType(name: string): string {
    // 플러그인 루트(plugins/_bundled/sirsoft-ckeditor5) 에서 저장소 루트로 두 단계 올라간다.
    const repoRoot = path.dirname(path.dirname(path.dirname(pluginRoot())));
    const manifest = JSON.parse(
        fs.readFileSync(
            path.join(repoRoot, 'templates/_bundled/sirsoft-admin_basic/components.json'),
            'utf-8',
        ),
    );

    for (const [kind, entries] of Object.entries<any>(manifest.components ?? {})) {
        if ((entries as any[]).some((entry) => entry.name === name)) {
            return kind;
        }
    }

    throw new Error(`매니페스트에 ${name} 선언이 없습니다.`);
}

const cleanupCard = findNode(pluginSettings, (n) => n.id === 'section_cleanup_card');

describe('미사용 이미지 정리 섹션 — 계약', () => {
    it('설정 섹션이 존재한다', () => {
        expect(cleanupCard).not.toBeNull();
    });

    it('설정 화면의 모든 Toggle 노드 type 이 매니페스트 선언과 같다', () => {
        const collected: any[] = [];
        const collect = (node: any): void => {
            if (!node || typeof node !== 'object') return;
            if (node.name === 'Toggle') collected.push(node);

            const groups = [node.children ?? [], ...(node.slots ? Object.values(node.slots) : [])];
            for (const group of groups) {
                for (const child of group as any[]) collect(child);
            }
        };
        collect(pluginSettings);

        // 특정 노드만 짚으면 다음에 추가되는 토글이 같은 실수를 반복해도 잡히지 않는다.
        expect(collected.length).toBeGreaterThan(0);
        for (const toggle of collected) {
            expect(toggle.type).toBe(manifestType('Toggle'));
        }
    });

    it('토글과 보존기간 입력이 저장 키를 그대로 바인딩한다', () => {
        const toggle = findNode(cleanupCard, (n) => n.name === 'Toggle');
        const input = findNode(cleanupCard, (n) => n.name === 'Input');

        expect(toggle.props.name).toBe('unusedImageCleanup');
        expect(input.props.name).toBe('unusedImageRetentionDays');
        expect(input.props.min).toBe(1);
    });

    it('기본값이 꺼짐이고 보존기간 기본이 30일이다 (defaults.json)', () => {
        const defaults = JSON.parse(
            fs.readFileSync(path.join(pluginRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );

        expect(defaults.defaults.unusedImageCleanup).toBe(false);
        expect(defaults.defaults.unusedImageRetentionDays).toBe(30);
    });

    it('두 키가 프론트엔드에 노출되지 않는다 (운영자 전용 설정)', () => {
        const defaults = JSON.parse(
            fs.readFileSync(path.join(pluginRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );

        expect(defaults.frontend_schema.unusedImageCleanup.expose).toBe(false);
        expect(defaults.frontend_schema.unusedImageRetentionDays.expose).toBe(false);
    });

    it('설정 스키마가 같은 키를 선언한다 (저장 검증 whitelist)', () => {
        const pluginSource = fs.readFileSync(path.join(pluginRoot(), 'plugin.php'), 'utf-8');

        expect(pluginSource).toContain("'unusedImageCleanup' => [");
        expect(pluginSource).toContain("'unusedImageRetentionDays' => [");
        expect(pluginSource).toContain("'default' => false");
    });

    it('스케줄 게이트가 같은 설정 키를 가리킨다', () => {
        const pluginSource = fs.readFileSync(path.join(pluginRoot(), 'plugin.php'), 'utf-8');

        expect(pluginSource).toContain("'enabled_config' => 'sirsoft-ckeditor5.unusedImageCleanup'");
        expect(pluginSource).toContain('sirsoft-ckeditor5:prune-unused-images --scheduled');
    });

    it('업로드 관리 화면으로 가는 링크가 있다', () => {
        const button = findNode(cleanupCard, (n) => n.id === 'open_uploads_button');

        expect(button.actions[0].params.path).toBe('/admin/plugins/sirsoft-ckeditor5/uploads');
    });
});

// ─── 렌더링 ───

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestLabel: React.FC<any> = ({ className, children, text }) => (
    <label className={className}>{children || text}</label>
);
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestH3: React.FC<any> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestToggle: React.FC<any> = ({ name }) => <input type="checkbox" data-name={name} readOnly />;
const TestInput: React.FC<any> = ({ name, type }) => <input type={type} data-name={name} readOnly />;
const TestButton: React.FC<any> = ({ className, children }) => <button type="button" className={className}>{children}</button>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

describe('미사용 이미지 정리 섹션 — 렌더링', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
            Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
            P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
            H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
            Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
            Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
            Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
            Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
            Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        };
    });

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
        (registry as any).registry = {};
    });

    it('라벨과 힌트가 다국어 해석되어 렌더된다', async () => {
        testUtils = createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'test_ckeditor5_cleanup_section',
                components: [cleanupCard],
            } as any,
            {
                auth: {
                    isAuthenticated: true,
                    user: { id: 1, name: 'Admin', role: 'super_admin' },
                    authType: 'admin',
                },
                translations: {
                    'sirsoft-ckeditor5': {
                        settings: {
                            section_cleanup: '미사용 이미지 정리',
                            cleanup: {
                                toggle_label: '미사용 이미지 자동 정리',
                                toggle_hint: '기본 꺼짐 — 운영자가 직접 켜야 동작합니다.',
                                retention_label: '보존기간 (일)',
                                retention_hint: '보존기간이 지난 미사용 이미지만 정리 대상입니다.',
                                manual_hint: 'php artisan sirsoft-ckeditor5:prune-unused-images --dry-run',
                                open_uploads: '업로드 이미지 관리 열기',
                            },
                        },
                    },
                },
                locale: 'ko',
                initialState: { _local: { form: { unusedImageCleanup: false, unusedImageRetentionDays: 30 } } },
            },
        );

        const { container } = await testUtils.render();
        const text = container.textContent ?? '';

        expect(text).toContain('미사용 이미지 정리');
        expect(text).toContain('기본 꺼짐');
        expect(text).toContain('보존기간 (일)');
        expect(text).toContain('업로드 이미지 관리 열기');

        expect(container.querySelector('input[data-name="unusedImageCleanup"]')).not.toBeNull();
        expect(container.querySelector('input[data-name="unusedImageRetentionDays"]')).not.toBeNull();
        expect(() => testUtils!.assertNoValidationErrors()).not.toThrow();
    });
});
