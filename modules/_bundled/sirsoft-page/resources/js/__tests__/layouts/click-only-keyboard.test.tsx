// e2e:allow 레이아웃 정적 구조 + 엔진 렌더(Tab·Enter·Space 로 접기 머리·첨부 카드 누르기) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자 페이지 상세 본문 미리보기·첨부 접기와 첨부 키보드 내려받기)이 덮는다.
/**
 * @file click-only-keyboard.test.tsx
 * @description 페이지 관리자 상세에서 클릭만 받던 영역을 단추로 바꿔 키보드로도 누를 수 있는지 (7.1.1)
 *
 * 관리자 페이지 상세의 「본문 미리보기」·「첨부파일」 접기 머리와 첨부 카드가 클릭 동작(`<on type="click">`)만 달린 `<div>` 라
 * Tab 이 닿지 않고 Enter·Space 로 접거나 내려받을 수 없었으며, 접힘 여부도 보조기술에 알리지 않았다(7.1.1 출시 전 실측 「같은 모양」 표).
 *
 * 고침: 접기 머리는 `aria-expanded` 를 단 `<button type="button">`, 첨부 카드는 `<button type="button">`(내려받기는 이 모듈의 핸들러).
 *
 * 판정(모집단 = 페이지 모듈 레이아웃 JSON 전체): 클릭을 받는 비대화형 노드는 없어야 한다.
 *
 * @scenario surface=admin_page_detail
 * @effects admin_click_only_regions_are_keyboard_buttons
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';

import adminPageDetail from '../../../layouts/admin/admin_page_detail.json';

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
 * 레이아웃에서 클릭을 받는데 키보드로 누를 수 없는 노드를 모은다.
 *
 * @param root 레이아웃 JSON
 * @returns 요약 목록
 */
function clickOnlyNodes(root: any): string[] {
    return collect(root, (n) => eventsOf(n).includes('click') && !KEYBOARD_NATIVE.has(n.name))
        .filter((n) => !(n.props?.role && (n.props?.tabIndex ?? n.props?.tabindex) !== undefined && eventsOf(n).some((e) => e.startsWith('key'))))
        .map((n) => `${n.name}${n.id ? `#${n.id}` : ''} .${String(n.props?.className ?? '').slice(0, 50)}`);
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

describe('페이지 관리자 상세 클릭만 받던 영역 — 단추로 키보드 조작 (7.1.1)', () => {
    let registry: MockComponentRegistry;
    let download: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
        // 이 모듈의 내려받기 핸들러를 가짜로 등록한다(시험 유틸이 만드는 디스패처에 끼운다)
        download = vi.fn();
        const original = ActionDispatcher.prototype.setGlobalStateUpdater;
        vi.spyOn(ActionDispatcher.prototype, 'setGlobalStateUpdater').mockImplementation(function (this: ActionDispatcher, updater: any) {
            this.registerHandler('sirsoft-page.downloadAttachment', download as any);

            return original.call(this, updater);
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario surface=admin_page_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('페이지 모듈 레이아웃 전체에서 클릭만 받는 비대화형 노드가 없다', () => {
        const files = jsonFiles(join(ROOT, 'resources/layouts'));
        expect(files.length).toBeGreaterThan(2);
        const missing = files.flatMap((path) =>
            clickOnlyNodes(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_page_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('본문 미리보기·첨부 접기 머리 — aria-expanded 단추라 Enter·Space 로 접는다', async () => {
        const headers = ['content_header', 'attachments_header'].map((id) => collect(adminPageDetail, (n) => n.id === id)[0]);
        headers.forEach((h, i) => expect(h, `${['content_header', 'attachments_header'][i]} 를 찾지 못했다`).toBeTruthy());
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/page-click-only', components: headers } as any,
            { componentRegistry: registry as any, locale: 'ko', initialData: { page: { data: { attachments: [{}] } } } },
        );
        await utils.render();
        const user = userEvent.setup();
        const buttons = screen.getAllByRole('button');
        expect(buttons).toHaveLength(2);
        buttons.forEach((b) => {
            expect(b.getAttribute('type')).toBe('button');
            // 둘 다 기본 펼침
            expect(b.getAttribute('aria-expanded')).toBe('true');
        });

        await user.tab();
        expect(document.activeElement).toBe(buttons[0]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils.getState()).contentCollapsed).toBe(true));
        await user.tab();
        await user.keyboard(' ');
        await waitFor(() => expect(localOf(utils.getState()).showAttachments).toBe(false));
        utils.cleanup();
    });

    // @scenario surface=admin_page_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('첨부 카드 — Tab 으로 닿고 Enter 로 이 모듈의 내려받기 핸들러를 부른다', async () => {
        const list = collect(adminPageDetail, (n) => String(n.id ?? '').startsWith('attachments_list_'))[0];
        expect(list, '첨부 목록 반복 노드를 찾지 못했다').toBeTruthy();
        const attachments = [{ original_filename: '약관.pdf', size_formatted: '5 KB', is_image: false, download_url: '/api/admin/x/h9' }];
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/page-click-only', components: [list] } as any,
            { componentRegistry: registry as any, locale: 'ko', initialData: { page: { data: { attachments } } } },
        );
        await utils.render();
        const user = userEvent.setup();
        const card = screen.getByRole('button');
        expect(card.getAttribute('type')).toBe('button');
        expect(card.textContent).toContain('약관.pdf');
        await user.tab();
        expect(document.activeElement).toBe(card);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(download).toHaveBeenCalledTimes(1));
        expect(download.mock.calls[0][0].params).toMatchObject({ url: '/api/admin/x/h9', filename: '약관.pdf' });
        utils.cleanup();
    });
});
