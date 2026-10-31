// e2e:allow 레이아웃 정적 구조 + 엔진 렌더(Tab·Enter 로 미리보기 머리 펼치기) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(ckeditor5 플러그인 설정 「미리보기」 키보드 펼침)이 덮는다.
/**
 * @file click-only-keyboard.test.tsx
 * @description ckeditor5 플러그인 설정의 「미리보기」 머리를 단추로 바꿔 키보드로도 펼 수 있는지 (7.1.1)
 *
 * 미리보기 구역 머리가 클릭 동작(`<on type="click">`)만 달린 `<div>` 라 Tab 이 닿지 않고 Enter·Space 로 펼칠 수 없었으며,
 * 펼침 여부도 보조기술에 알리지 않았다(7.1.1 출시 전 실측 「같은 모양」 표 — 클릭만 받는 단건 영역).
 *
 * 고침: 머리를 제목(`h3`) 안의 `<button type="button" aria-expanded>` 로 — 다른 구역 머리처럼 제목 탐색에 남고, 머리 줄 전체가 단추다.
 *
 * 판정(모집단 = 이 플러그인 레이아웃 JSON 전체): 클릭을 받는 비대화형 노드는 없어야 한다.
 *
 * @scenario surface=plugin_settings
 * @effects plugin_click_only_regions_are_keyboard_buttons
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import userEvent from '@testing-library/user-event';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import pluginSettings from '../../../layouts/admin/plugin_settings.json';

type Node = Record<string, any>;

const ROOT = resolve(__dirname, '../../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품 */
const KEYBOARD_NATIVE = new Set(['Button', 'A', 'Link', 'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'Label', 'Form', 'Modal', 'IconButton', 'ActionMenu', 'SearchableDropdown']);

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const eventOf = (a: any): string => String(a?.event ?? a?.type ?? '').replace(/^on/i, '').toLowerCase();
const eventsOf = (n: any): string[] => actionsOf(n).map(eventOf);

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @param out 결과
 * @returns 찾은 노드 목록
 */
function collect(x: any, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
    if (Array.isArray(x)) {
        x.forEach((y) => collect(y, pred, out));

        return out;
    }
    if (!x || typeof x !== 'object') return out;
    if (isNode(x) && pred(x)) out.push(x);
    Object.values(x).forEach((v) => collect(v, pred, out));

    return out;
}

/**
 * 디렉터리 아래 JSON 경로를 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

/** 테스트 렌더는 `target=local` 을 `_global._local` 로 폴백해 담는다 — 두 곳을 합쳐 읽는다 */
const localOf = (state: any): any => ({ ...(state._local ?? {}), ...(state._global?._local ?? {}) });

describe('ckeditor5 플러그인 설정 클릭만 받던 영역 — 단추로 키보드 조작 (7.1.1)', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario surface=plugin_settings
    // @effects plugin_click_only_regions_are_keyboard_buttons
    it('플러그인 레이아웃 전체에서 클릭만 받는 비대화형 노드가 없다', () => {
        const files = ['resources/layouts', 'resources/extensions'].flatMap((d) => {
            try {
                return jsonFiles(join(ROOT, d));
            } catch {
                return [];
            }
        });
        expect(files.length).toBeGreaterThan(0);
        const missing = files.flatMap((path) =>
            collect(JSON.parse(readFileSync(path, 'utf-8')), (n) => eventsOf(n).includes('click') && !KEYBOARD_NATIVE.has(n.name))
                .filter((n) => !(n.props?.role && (n.props?.tabIndex ?? n.props?.tabindex) !== undefined && eventsOf(n).some((e) => e.startsWith('key'))))
                .map((n) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${n.name}${n.id ? `#${n.id}` : ''}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=plugin_settings
    // @effects plugin_click_only_regions_are_keyboard_buttons
    it('「미리보기」 머리 — 제목 안의 aria-expanded 단추라 Tab·Enter 로 편다', async () => {
        const header = collect(pluginSettings, (n) => n.id === 'section_preview_header')[0];
        expect(header, '미리보기 머리를 찾지 못했다').toBeTruthy();
        expect(header.name).toBe('Button');
        const heading = collect(pluginSettings, (n) => n.name === 'H3' && collect(n.children, (c) => c.id === 'section_preview_header').length > 0);
        expect(heading, '미리보기 머리가 제목(h3) 안에 있지 않다').toHaveLength(1);

        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/ckeditor5-click-only', components: [heading[0]] } as any,
            { componentRegistry: registry as any, locale: 'ko' },
        );
        await utils.render();
        const user = userEvent.setup();
        const button = screen.getByRole('button');
        expect(button.getAttribute('type')).toBe('button');
        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(screen.getByRole('heading', { level: 3 })).toContainElement(button);

        await user.tab();
        expect(document.activeElement).toBe(button);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils.getState()).showPreview).toBe(true));
        utils.cleanup();
    });
});
