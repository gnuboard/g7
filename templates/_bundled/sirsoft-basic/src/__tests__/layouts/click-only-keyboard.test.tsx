// e2e:allow 레이아웃 정적 구조 + 엔진 렌더(Tab·Enter·Space 로 단추 누르기) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(게시글 첨부·장바구니/주문서 금액 펼침·후기 사진·클레임 번호·문의 펼침 키보드 조작)이 덮는다.
/**
 * @file click-only-keyboard.test.tsx
 * @description basic 화면에서 클릭만 받던 영역을 단추로 바꿔 키보드로도 누를 수 있는지 (7.1.1)
 *
 * 클릭 동작(`<on type="click">`)만 달린 `<div>`·`<span>`·`<img>` 는 Tab 이 닿지 않고 Enter·Space 로 눌리지 않는다.
 * 게시글 첨부(펼침 머리·내려받기 카드), 장바구니·주문서 금액 줄 펼침, 상품 후기 사진, 비회원 주문 상세의 클레임 번호가
 * 그런 자리였다(7.1.1 출시 전 실측 「같은 모양」 표).
 *
 * 고침: 그 자리를 `<button type="button">` 으로 그린다. 펼침은 `aria-expanded`, 아이콘·사진만 있는 단추는 다국어 `aria-label`.
 * 클레임 번호는 비회원 조회 토큰이 탭에 묶여 새 탭이 조회 폼으로 되돌아가므로 링크가 아니라 단추다(결정 — 비회원 주문 조회 화면은
 * 링크 전환 제외).
 *
 * 판정(모집단 = basic 레이아웃 JSON 전체): 클릭을 받는 비대화형 노드는 없어야 한다. 아래 넷은 같은 일을 하는 키보드 수단이 따로
 * 있어 그대로 둔다 — ① 덮개(`fixed inset-0`, 자식 없음)인데 같은 상태를 닫는 단추가 같은 파일에 있다(모바일 메뉴 덮개 — Esc·닫기 단추,
 * 통화 목록 덮개 — 여닫기 단추) ② 안에 같은 동작의 라디오가 있다 ③ 안에 같은 상태를 펴는 `aria-expanded` 단추가 있다(문의 카드)
 * ④ 안에 자기 동작 없는 `aria-pressed` 대리 단추가 있다(배송지 관리 카드).
 *
 * @scenario surface=user_page
 * @effects click_only_regions_are_keyboard_buttons
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

import postAttachments from '../../../layouts/partials/board/show/_post_attachments.json';
import cartSummary from '../../../layouts/partials/shop/_cart_summary.json';
import checkoutSummary from '../../../layouts/partials/shop/_checkout_summary.json';
import tabReviews from '../../../layouts/partials/shop/detail/_tab_reviews.json';
import claimList from '../../../layouts/partials/mypage/claims/_list.json';
import inquiryList from '../../../layouts/partials/mypage/inquiries/_list.json';

type Node = Record<string, any>;

const ROOT = resolve(__dirname, '../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품 */
const KEYBOARD_NATIVE = new Set(['Button', 'A', 'Link', 'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'Label', 'Form', 'Modal']);

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const eventOf = (a: any): string => String(a?.event ?? a?.type ?? '').replace(/^on/i, '').toLowerCase();
const eventsOf = (n: any): string[] => actionsOf(n).map(eventOf);
const clickActionsOf = (n: any): any[] => actionsOf(n).filter((a: any) => eventOf(a) === 'click');

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
 * 액션(과 그 안쪽 sequence·conditions)이 setState 로 쓰는 상태 키를 모은다.
 *
 * @param actions 액션 목록
 * @returns 상태 키 집합
 */
function stateKeysOf(actions: any[]): Set<string> {
    const keys = new Set<string>();
    const walk = (a: any): void => {
        if (Array.isArray(a)) return a.forEach(walk);
        if (!a || typeof a !== 'object') return;
        if (a.handler === 'setState' && a.params) Object.keys(a.params).filter((k) => k !== 'target').forEach((k) => keys.add(k));
        Object.values(a).forEach(walk);
    };
    walk(actions);

    return keys;
}

/**
 * 클릭만 받는 노드가 같은 일을 하는 키보드 수단을 따로 갖는지(위 머리 주석 ①~④).
 *
 * @param node 클릭을 받는 노드
 * @param fileButtons 같은 파일의 단추가 클릭으로 쓰는 상태 키
 * @returns 키보드 수단이 있으면 그 갈래 이름, 없으면 null
 */
function keyboardAlternative(node: Node, fileButtons: Set<string>): string | null {
    const cls = String(node.props?.className ?? '');
    const keys = [...stateKeysOf(clickActionsOf(node))];
    if (/\bfixed\b/.test(cls) && /\binset-0\b/.test(cls) && !(node.children ?? []).length && keys.length > 0 && keys.every((k) => fileButtons.has(k))) {
        return 'backdrop';
    }
    if (collect(node.children, (n) => n.name === 'Input' && n.props?.type === 'radio' && eventsOf(n).includes('change')).length > 0) {
        return 'radio';
    }
    const disclosure = collect(node.children, (n) => n.name === 'Button' && n.props?.['aria-expanded'] !== undefined);
    if (keys.length > 0 && disclosure.some((b) => keys.every((k) => stateKeysOf(clickActionsOf(b)).has(k)))) {
        return 'disclosure';
    }
    if (collect(node.children, (n) => n.name === 'Button' && !eventsOf(n).includes('click') && n.props?.['aria-pressed'] !== undefined).length > 0) {
        return 'pressed-proxy';
    }

    return null;
}

/**
 * 레이아웃에서 클릭을 받는데 키보드로 누를 수 없는 노드를 모은다.
 *
 * @param root 레이아웃 JSON
 * @returns 요약 목록
 */
function clickOnlyNodes(root: any): string[] {
    const fileButtons = new Set<string>();
    collect(root, (n) => KEYBOARD_NATIVE.has(n.name)).forEach((b) => stateKeysOf(clickActionsOf(b)).forEach((k) => fileButtons.add(k)));

    return collect(root, (n) => eventsOf(n).includes('click') && !KEYBOARD_NATIVE.has(n.name))
        .filter((n) => !(n.props?.role && (n.props?.tabIndex ?? n.props?.tabindex) !== undefined && eventsOf(n).some((e) => e.startsWith('key'))))
        .filter((n) => keyboardAlternative(n, fileButtons) === null)
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

/**
 * 노드 하나를 엔진으로 렌더한다.
 *
 * @param node 렌더할 노드(부분 레이아웃 루트 또는 그 일부)
 * @param registry 부품 레지스트리
 * @param options 초기 데이터·상태
 * @returns 테스트 유틸
 */
async function renderNode(node: Node, registry: MockComponentRegistry, options: { initialData?: any; initialState?: any; translations?: any } = {}) {
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/click-only-keyboard', components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'root' }, children: [node] }] } as any,
        { componentRegistry: registry as any, locale: 'ko', ...options },
    );
    await utils.render();

    return utils;
}

/** 반복 원천을 시험 데이터로 바꿔 끼운 감싸개 — 조각의 일부만 그릴 때 반복 변수(review·claim)를 세운다 */
const repeatOver = (itemVar: string, source: string, child: Node): Node => ({
    type: 'basic',
    name: 'Div',
    iteration: { source, item_var: itemVar },
    children: [child],
});

describe('basic 클릭만 받던 영역 — 단추로 키보드 조작 (7.1.1)', () => {
    let registry: MockComponentRegistry;
    let handlerSpies: Record<string, ReturnType<typeof vi.fn>>;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
        // 이 화면들이 부르는 템플릿·모듈 핸들러를 가짜로 등록한다(시험 유틸이 만드는 디스패처에 끼운다)
        handlerSpies = { downloadAttachment: vi.fn(), redirectToLoginWithReturn: vi.fn() };
        const original = ActionDispatcher.prototype.setGlobalStateUpdater;
        vi.spyOn(ActionDispatcher.prototype, 'setGlobalStateUpdater').mockImplementation(function (this: ActionDispatcher, updater: any) {
            Object.entries(handlerSpies).forEach(([name, spy]) => this.registerHandler(name, spy as any));

            return original.call(this, updater);
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('basic 레이아웃 전체에서 클릭만 받는 비대화형 노드가 없다(키보드 수단이 따로 있는 덮개·카드 제외)', () => {
        const files = jsonFiles(join(ROOT, 'layouts'));
        expect(files.length).toBeGreaterThan(50);
        const missing = files.flatMap((path) =>
            clickOnlyNodes(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=user_page
    // @effects buttons_declare_type
    it('basic 레이아웃 전체에서 폼(Form) 안의 단추는 모두 type 을 적는다 — 빠지면 누를 때 폼이 제출된다', () => {
        const files = jsonFiles(join(ROOT, 'layouts'));
        const untyped: string[] = [];
        const walk = (x: any, inForm: boolean, file: string): void => {
            if (Array.isArray(x)) return x.forEach((y) => walk(y, inForm, file));
            if (!x || typeof x !== 'object') return;
            const form = inForm || (isNode(x) && x.name === 'Form');
            if (isNode(x) && x.name === 'Button' && form && !x.props?.type) untyped.push(`${file}: Button${x.id ? `#${x.id}` : ''}`);
            Object.values(x).forEach((v) => walk(v, form, file));
        };
        files.forEach((path) => walk(JSON.parse(readFileSync(path, 'utf-8')), false, relative(ROOT, path).replace(/\\/g, '/')));
        expect(untyped, untyped.join('\n')).toEqual([]);
    });

    // @scenario surface=user_page
    // @effects buttons_declare_type
    it('이번에 단추로 바꾼 자리(첨부·금액 줄·후기 사진·클레임 번호·문의 펼침)의 클릭 단추는 type="button" 을 적는다', () => {
        const converted = { postAttachments, cartSummary, checkoutSummary, tabReviews, claimList, inquiryList };
        const untyped = Object.entries(converted).flatMap(([name, tree]) =>
            collect(tree, (n) => n.name === 'Button' && eventsOf(n).includes('click') && n.props?.type !== 'button').map((n) => `${name}: ${n.id ?? String(n.props?.className ?? '').slice(0, 40)}`),
        );
        expect(untyped, untyped.join('\n')).toEqual([]);
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('게시글 첨부 — 펼침 머리는 aria-expanded 단추, 카드는 단추라 Tab·Enter 로 내려받고 권한 없으면 안내한다', async () => {
        const attachment = { id: 1, hash: 'h1', original_filename: '안내문.pdf', size_formatted: '12 KB', is_image: false, download_url: '/api/x/h1' };
        const post = { data: { has_attachment: true, status: 'published', content: '본문', attachment_count: 1, attachments: [attachment], abilities: { can_download: true } } };
        const user = userEvent.setup();

        const utils = await renderNode(postAttachments as Node, registry, { initialData: { post }, initialState: { _global: { currentUser: { uuid: 'u-1' } } } });
        const toggle = screen.getAllByRole('button').find((b) => b.hasAttribute('aria-expanded'))!;
        expect(toggle, '첨부 펼침 머리가 단추가 아니다').toBeTruthy();
        expect(toggle.getAttribute('type')).toBe('button');
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        const card = screen.getAllByRole('button').find((b) => b.textContent?.includes('안내문.pdf'))!;
        expect(card, '첨부 카드가 단추가 아니다').toBeTruthy();
        expect(card.getAttribute('type')).toBe('button');

        await user.tab();
        expect(document.activeElement).toBe(toggle);
        await user.tab();
        expect(document.activeElement).toBe(card);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(handlerSpies.downloadAttachment).toHaveBeenCalledTimes(1));
        expect(handlerSpies.downloadAttachment.mock.calls[0][0].params).toMatchObject({ url: '/api/x/h1', filename: '안내문.pdf' });

        toggle.focus();
        await user.keyboard(' ');
        await waitFor(() => expect(localOf(utils.getState()).showAttachments).toBe(false));
        utils.cleanup();

        // 권한 없는 회원 — 같은 카드를 눌러 권한 없음 안내
        const denied = await renderNode(postAttachments as Node, registry, {
            initialData: { post: { data: { ...post.data, abilities: { can_download: false } } } },
            initialState: { _global: { currentUser: { uuid: 'u-1' } } },
        });
        const deniedCard = screen.getAllByRole('button').find((b) => b.textContent?.includes('안내문.pdf'))!;
        expect(deniedCard, '권한 없음 카드가 단추가 아니다').toBeTruthy();
        deniedCard.focus();
        await user.keyboard('{Enter}');
        // 엔진 내장 toast 는 전역 toasts 배열에 쌓인다. 번역이 없는 시험 렌더에서는 키 그대로(접두 유무 무관) 담긴다
        await waitFor(() =>
            expect((denied.getState()._global.toasts ?? []).some((t: any) => String(t.message).includes('board.attachments.download_no_permission'))).toBe(true),
        );
        denied.cleanup();
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('장바구니·주문서 금액 줄 — 펼침 단추(aria-expanded)를 Enter 로 눌러 과세·할인 내역을 편다', async () => {
        const user = userEvent.setup();
        for (const [partial, data, local] of [
            // 장바구니 금액 줄은 고른 상품이 있을 때만 그려진다
            [cartSummary, { cartItems: { data: { items: [{ id: 1 }], calculation: { summary: { subtotal_formatted: '1,000원' } } } } }, { selectedItems: [1] }],
            [checkoutSummary, { checkoutData: { data: { calculation: { summary: { subtotal_formatted: '1,000원', total_discount: 100, total_discount_formatted: '100원' } } } } }, {}],
        ] as const) {
            const utils = await renderNode(partial as Node, registry, { initialData: data, initialState: { _local: local } });
            const toggles = screen.getAllByRole('button').filter((b) => b.hasAttribute('aria-expanded'));
            expect(toggles.length, '금액 줄 펼침 단추가 없다').toBeGreaterThan(0);
            toggles.forEach((t) => {
                expect(t.getAttribute('type')).toBe('button');
                expect(t.getAttribute('aria-expanded')).toBe('false');
            });
            toggles[0].focus();
            await user.keyboard('{Enter}');
            await waitFor(() => expect(localOf(utils.getState()).showTaxDetails).toBe(true));
            if (toggles[1]) {
                toggles[1].focus();
                await user.keyboard(' ');
                await waitFor(() => expect(localOf(utils.getState()).showDiscountDetails).toBe(true));
            }
            utils.cleanup();
        }
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('상품 후기 사진 — 사진마다 이름 있는 단추라 Tab·Enter 로 큰 사진 창을 그 사진부터 연다', async () => {
        const row = collect(tabReviews, (n) => String(n.if ?? '').includes('review.image_count > 0'))[0];
        expect(row, '후기 사진 줄을 찾지 못했다').toBeTruthy();
        const images = Array.from({ length: 6 }, (_, i) => ({ id: i + 1, download_url: `/img/${i + 1}.jpg` }));
        const user = userEvent.setup();

        const utils = await renderNode(repeatOver('review', '{{_local.testReviews}}', row), registry, {
            initialState: { _local: { testReviews: [{ id: 9, image_count: 6, images, content: '좋아요', rating: 5, user: { name: '구매자' } }] } },
            // 단추 이름이 몇 번째 사진인지 담는지 보려고 basic 원문(ko) 두 키를 싣는다
            translations: { shop: { product: { review_image_open: '리뷰 이미지 {{n}} 크게 보기', review_image_open_more: '리뷰 이미지 {{n}} 크게 보기 (그 밖 {{count}}장 더 있음)' } } },
        });
        const buttons = screen.getAllByRole('button');
        // 4번째 자리는 「+N 더 보기」 — 사진 1~3 + 더 보기 1
        expect(buttons).toHaveLength(4);
        buttons.forEach((b) => {
            expect(b.getAttribute('type')).toBe('button');
            expect(b.getAttribute('aria-label') ?? '', '사진 단추에 이름이 없다').not.toBe('');
        });
        expect(buttons.map((b) => b.getAttribute('aria-label'))).toEqual([
            '리뷰 이미지 1 크게 보기',
            '리뷰 이미지 2 크게 보기',
            '리뷰 이미지 3 크게 보기',
            '리뷰 이미지 4 크게 보기 (그 밖 3장 더 있음)',
        ]);

        await user.tab();
        await user.tab();
        expect(document.activeElement).toBe(buttons[1]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(utils.getState()._global.reviewImageIndex).toBe(1));
        // 큰 사진 창이 읽을 후기 묶음도 같이 담긴다(창 열기는 엔진 내장 처리라 여기서는 상태로 본다)
        expect(utils.getState()._global.reviewForModal?.images).toHaveLength(6);
        utils.cleanup();
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('비회원 주문 상세 클레임 번호 — 같은 창 이동 단추라 Enter 로 상세로 간다', async () => {
        const numberNode = collect(claimList, (n) => String(n.id ?? '').startsWith('claim_list_number_button'))[0];
        expect(numberNode, '클레임 번호 노드를 찾지 못했다').toBeTruthy();
        expect(numberNode.name).toBe('Button');
        expect(numberNode.props?.type).toBe('button');
        const user = userEvent.setup();

        const utils = await renderNode(repeatOver('claim', '{{_local.testClaims}}', numberNode), registry, {
            initialState: { _local: { claimDetailLinkMode: 'same_window', claimDetailPathBase: '/shop/guest-claims', testClaims: [{ claim_number: 'CLM-1' }] } },
        });
        await user.tab();
        expect(document.activeElement?.textContent).toContain('CLM-1');
        await user.keyboard('{Enter}');
        await waitFor(() => expect(utils.mockNavigate).toHaveBeenCalled());
        expect(String(utils.mockNavigate.mock.calls[0][0])).toContain('/shop/guest-claims/CLM-1');
        utils.cleanup();
    });

    // @scenario surface=user_page
    // @effects click_only_regions_are_keyboard_buttons
    it('문의 카드 — 카드 클릭은 남기고, 안의 펼침 단추(aria-expanded)를 Enter 로 눌러 문의를 편다', async () => {
        const user = userEvent.setup();
        const utils = await renderNode(inquiryList as Node, registry, {
            initialData: { myInquiries: { data: { items: [{ id: 5, title: '배송 문의', content: '언제 오나요', is_answered: false, created_at: '2026-10-01' }], meta: {} } } },
        });
        const chevron = screen.getAllByRole('button').find((b) => b.hasAttribute('aria-expanded'))!;
        expect(chevron).toBeTruthy();
        expect(chevron.getAttribute('aria-expanded')).toBe('false');
        chevron.focus();
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils.getState()).openedInquiryId).toBe(5));
        utils.cleanup();
    });
});
