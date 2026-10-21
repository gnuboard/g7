// e2e:allow 레이아웃 정적 구조 + 엔진 렌더(Tab·Enter 로 첨부 카드 누르기) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자 게시글 상세 원글·답글 첨부 키보드 내려받기)이 덮는다.
/**
 * @file click-only-keyboard.test.tsx
 * @description 게시판 관리자 화면에서 클릭만 받던 영역을 단추로 바꿔 키보드로도 누를 수 있는지 (7.1.1)
 *
 * 관리자 게시글 상세의 원글·답글 첨부 카드가 클릭 동작(`<on type="click">`)만 달린 `<div>` 라 Tab 이 닿지 않고
 * Enter·Space 로 내려받을 수 없었다(7.1.1 출시 전 실측 「같은 모양」 표 — 게시판 첨부 행).
 *
 * 고침: 카드를 `<button type="button">` 으로 그린다(링크가 아니다 — 내려받기에 로그인 토큰을 실어야 해서 이 모듈의 핸들러가 받는다).
 *
 * 판정(모집단 = 게시판 레이아웃 JSON 전체): 클릭을 받는 비대화형 노드는 없어야 한다. 같은 동작의 라디오를 품은 고르기 카드
 * (설정의 「일괄 적용 대상」·「날짜 표시 형식」)는 라디오가 Tab·화살표 키로 같은 값을 고르므로 그대로 둔다.
 *
 * @scenario surface=admin_post_detail
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

import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';

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
 * 레이아웃에서 클릭을 받는데 키보드로 누를 수 없는 노드를 모은다(같은 동작의 라디오를 품은 카드는 제외).
 *
 * @param root 레이아웃 JSON
 * @returns 요약 목록
 */
function clickOnlyNodes(root: any): string[] {
    return collect(root, (n) => eventsOf(n).includes('click') && !KEYBOARD_NATIVE.has(n.name))
        .filter((n) => !(n.props?.role && (n.props?.tabIndex ?? n.props?.tabindex) !== undefined && eventsOf(n).some((e) => e.startsWith('key'))))
        .filter((n) => collect(n.children, (c) => c.name === 'Input' && c.props?.type === 'radio' && eventsOf(c).includes('change')).length === 0)
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

describe('게시판 관리자 클릭만 받던 영역 — 단추로 키보드 조작 (7.1.1)', () => {
    let registry: MockComponentRegistry;
    let download: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
        // 이 모듈의 내려받기 핸들러를 가짜로 등록한다(시험 유틸이 만드는 디스패처에 끼운다)
        download = vi.fn();
        const original = ActionDispatcher.prototype.setGlobalStateUpdater;
        vi.spyOn(ActionDispatcher.prototype, 'setGlobalStateUpdater').mockImplementation(function (this: ActionDispatcher, updater: any) {
            this.registerHandler('sirsoft-board.downloadAttachment', download as any);

            return original.call(this, updater);
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario surface=admin_post_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('게시판 레이아웃 전체에서 클릭만 받는 비대화형 노드가 없다(라디오를 품은 고르기 카드 제외)', () => {
        const files = jsonFiles(join(ROOT, 'resources/layouts'));
        expect(files.length).toBeGreaterThan(20);
        const missing = files.flatMap((path) =>
            clickOnlyNodes(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_post_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('답글 첨부 카드도 type=button 인 단추다', () => {
        const cards = collect(replyCard, (n) => String(n.id ?? '').startsWith('attachment_card_'));
        expect(cards).toHaveLength(1);
        expect(cards[0].name).toBe('Button');
        expect(cards[0].props?.type).toBe('button');
    });

    // @scenario surface=admin_post_detail
    // @effects admin_click_only_regions_are_keyboard_buttons
    it('원글 첨부 카드 — Tab 으로 닿고 Enter 로 관리자 내려받기 핸들러를 부른다', async () => {
        const list = collect(postCard, (n) => n.id === 'post_attachments_list')[0];
        expect(list, '첨부 목록 묶음을 찾지 못했다').toBeTruthy();
        const attachments = [
            { hash: 'h1', original_filename: '공지.pdf', size_formatted: '10 KB', is_image: false },
            { hash: 'h2', original_filename: '사진.png', size_formatted: '20 KB', is_image: false },
        ];
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/board-click-only', components: [list] } as any,
            {
                componentRegistry: registry as any,
                locale: 'ko',
                routeParams: { slug: 'notice' },
                initialData: { post: { data: { attachments, abilities: { can_download: true } } } },
                initialState: { _local: { showAttachments: true } },
            },
        );
        await utils.render();
        const user = userEvent.setup();
        const cards = screen.getAllByRole('button');
        expect(cards).toHaveLength(2);
        cards.forEach((c) => expect(c.getAttribute('type')).toBe('button'));
        expect(cards[0].textContent).toContain('공지.pdf');

        await user.tab();
        await user.tab();
        expect(document.activeElement).toBe(cards[1]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(download).toHaveBeenCalledTimes(1));
        expect(download.mock.calls[0][0].params.url).toContain('/attachments/download/h2');
        expect(download.mock.calls[0][0].params.filename).toBe('사진.png');
        utils.cleanup();
    });
});
