// e2e:allow 레이아웃 정적 구조(클릭을 받는 요소의 요소 종류·선택/펼침 상태 속성) + 엔진 렌더(Tab·Enter·Space) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(필터 칩·접는 구획·기간 빠른 선택·목록 카드를 키보드로 조작)이 덮는다.
/**
 * @file adminClickOnlyKeyboard.test.tsx
 * @description 이커머스 레이아웃에서 클릭만 받던 영역(DIV·SPAN·IMG·TR 등)을 키보드·화면 낭독기로 쓸 수 있는지
 *
 * 저장한 검색 조건 칩·상태 필터 배지·기간 빠른 선택·상품 등록 구획 머리(접기)·분류 고르기·배너 목록·후기 사진 등이
 * 클릭만 받는 `<div>` 라 Tab 으로 닿지 않고 Enter·Space 로 누를 수 없었고, 화면 낭독기에는 단추로 읽히지 않았다
 * (7.1.1 출시 전 실측 「같은 모양」 표, 정적 스캔 110 노드). 같은 일을 하는 게시판 기간 칩은 이미 `<button>` 이다.
 *
 * 고침: 요소를 `<button type="button">` 으로 그린다(액션은 그대로). 고르는 칩·카드는 `aria-pressed`, 접는 머리는
 * `aria-expanded` 로 상태를 알린다. 표 행(`<tr>`)·안에 다른 단추가 있는 카드처럼 통째로 단추가 될 수 없는 자리는
 * 자기 동작 없는 대리 단추(상태 속성 + 이름)를 품어 그 단추의 클릭이 행의 동작으로 올라가게 한다.
 *
 * 판정(모집단 파생): 이커머스 레이아웃 JSON 전체에서 클릭을 받는 요소는 ① 단추·링크 같은 키보드 부품이거나
 * ② role·tabIndex·keydown 셋을 모두 갖추거나 ③ 자기 동작 없는 대리 단추(aria-pressed·aria-expanded·aria-checked)를
 * 품거나 ④ 같은 동작을 하는 키보드 부품(링크·단추)을 품는다. 새 화면에도 그대로 걸린다.
 *
 * @scenario surface=admin_layout
 * @effects click_only_region_keyboard_operable
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

import descriptionSection from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_description.json';
import excelDownloadIndex from '../../../layouts/admin/admin_ecommerce_excel_download_index.json';
import paymentFailureHistory from '../../../layouts/admin/admin_ecommerce_payment_failure_history.json';
import mileageFilters from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_filters.json';

type Node = Record<string, any>;

const ROOT = resolve(__dirname, '../../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품(또는 그 안의 칸이 키보드를 받는 부품) */
const KEYBOARD_NATIVE = new Set([
    'Button',
    'A',
    'Link',
    'Input',
    'Select',
    'Textarea',
    'Checkbox',
    'Toggle',
    'Label',
    'SearchableDropdown',
    'ActionMenu',
]);

const STATE_ATTRS = ['aria-pressed', 'aria-expanded', 'aria-checked'];

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children || x.actions));
const eventsOf = (n: any): string[] => actionsOf(n).map((a: any) => String(a?.type ?? a?.event ?? ''));
const clickActions = (n: any): any[] => actionsOf(n).filter((a: any) => String(a?.type ?? a?.event ?? '') === 'click');

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
    Object.entries(x).forEach(([k, v]) => {
        if (k !== 'actions') collect(v, pred, out);
    });

    return out;
}

/**
 * 액션을 비교용 글자로 — 전파 차단 표시는 같은 동작 판정에서 뺀다.
 *
 * @param actions 액션 목록
 * @returns 정규화한 JSON
 */
function actionKey(actions: any[]): string {
    return JSON.stringify(actions, (k, v) => (k === 'stopPropagation' || k === 'comment' ? undefined : v));
}

/**
 * 클릭을 받는 요소가 키보드로 같은 일을 할 수 있는지.
 *
 * @param n 클릭을 받는 노드
 * @returns 키보드 조작 가능하면 true
 */
function keyboardOperable(n: Node): boolean {
    if (KEYBOARD_NATIVE.has(n.name)) return true;
    const p = n.props ?? {};
    const tab = p.tabIndex ?? p.tabindex;
    if (p.role && tab !== undefined && eventsOf(n).some((e) => /^key(down|up|press)$/i.test(e))) return true;
    // ③ 자기 동작 없는 대리 단추 — 클릭이 이 요소로 올라온다
    const proxy = collect(n.children, (c) => c.name === 'Button' && !eventsOf(c).includes('click') && STATE_ATTRS.some((a) => c.props?.[a] !== undefined));
    if (proxy.length > 0) return true;
    // ④ 같은 동작을 하는 키보드 부품을 품는다(이름 링크 등)
    const own = actionKey(clickActions(n));
    const same = collect(n.children, (c) => KEYBOARD_NATIVE.has(c.name) && clickActions(c).length > 0).some((c) => {
        const theirs = actionKey(clickActions(c));

        return theirs === own;
    });

    return same;
}

/**
 * 레이아웃에서 클릭을 받는데 키보드로 쓸 수 없는 요소를 모은다.
 *
 * @param root 레이아웃 JSON
 * @returns 요약 목록
 */
function clickOnlyRegions(root: any): string[] {
    return collect(root, (n) => eventsOf(n).includes('click') && !keyboardOperable(n)).map(
        (n) => `${n.name}${n.id ? `#${n.id}` : ''} .${String(n.props?.className ?? '').slice(0, 60)}`,
    );
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

/**
 * 노드 하나를 엔진으로 렌더한다.
 *
 * @param node 렌더할 노드
 * @param registry 부품 레지스트리
 * @param local 초기 _local
 * @returns 테스트 유틸
 */
async function renderNode(node: Node, registry: MockComponentRegistry, local: Record<string, any>) {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test/admin-click-only-keyboard',
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' }, children: [node] }],
        } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _local: local } },
    );
    await utils.render();

    return utils;
}

/**
 * 테스트 렌더의 지역 상태 — 컴포넌트 상태 저장소가 없으면 target=local 이 `_global._local` 로 폴백해 담긴다.
 *
 * @param utils 테스트 유틸
 * @returns 지역 상태
 */
function localOf(utils: { getState: () => any }): any {
    const s = utils.getState();

    return { ...(s._local ?? {}), ...(s._global?._local ?? {}) };
}

/**
 * Tab 을 눌러 대상에 초점이 갈 때까지 이동한다(키보드만으로 닿는지).
 *
 * @param user userEvent
 * @param target 대상 요소
 */
async function tabTo(user: ReturnType<typeof userEvent.setup>, target: HTMLElement): Promise<void> {
    for (let i = 0; i < 40 && document.activeElement !== target; i++) {
        await user.tab();
    }
    expect(document.activeElement).toBe(target);
}

describe('이커머스 클릭만 받던 영역 키보드 조작', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
        registry.register('composite', 'Dropdown', ({ label }: { label?: string }) => React.createElement('span', null, label));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('이커머스 레이아웃 전체에서 클릭을 받는 요소는 키보드로 같은 일을 할 수 있다', () => {
        const files = jsonFiles(join(ROOT, 'resources/layouts'));
        expect(files.length).toBeGreaterThan(200);
        const missing = files.flatMap((path) =>
            clickOnlyRegions(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('고르는 칩(상태 배지·기간 빠른 선택·저장한 검색 조건)은 단추이고 선택 상태를 aria-pressed 로 알린다', () => {
        const files = jsonFiles(join(ROOT, 'resources/layouts'));
        const chips = files.flatMap((path) =>
            collect(JSON.parse(readFileSync(path, 'utf-8')), (n) => eventsOf(n).includes('click') && /\b(btn-date|btn-preset|btn-badge|badge-status)\b/.test(String(n.props?.className ?? '')) && /\?/.test(String(n.props?.className ?? ''))).map((n) => ({ path, n })),
        );
        expect(chips.length).toBeGreaterThan(40);
        const bad = chips.filter(({ n }) => n.name !== 'Button' || n.props?.type !== 'button' || n.props?.['aria-pressed'] === undefined).map(({ path, n }) => `${relative(ROOT, path)}: ${n.name} .${String(n.props?.className).slice(0, 50)}`);
        expect(bad, bad.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('상품 폼 구획 머리(접기)는 단추이고 펼침 상태를 aria-expanded 로 알린다', () => {
        const dir = join(ROOT, 'resources/layouts/admin/partials/admin_ecommerce_product_form');
        const heads = jsonFiles(dir).flatMap((path) =>
            collect(JSON.parse(readFileSync(path, 'utf-8')), (n) => eventsOf(n).includes('click') && (/\bcard-header\b/.test(String(n.props?.className ?? '')) || /_detail_header$/.test(String(n.id ?? '')))).map((n) => ({ path, n })),
        );
        expect(heads).toHaveLength(15);
        const bad = heads.filter(({ n }) => n.name !== 'Button' || n.props?.type !== 'button' || n.props?.['aria-expanded'] === undefined).map(({ path, n }) => `${relative(ROOT, path)}: ${n.name}`);
        expect(bad, bad.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('상품 등록 「상세 설명」 구획 머리 — Tab 으로 닿고 Enter 로 접고 Space 로 편다', async () => {
        const head = collect(descriptionSection, (n) => /\bcard-header\b/.test(String(n.props?.className ?? '')))[0];
        const utils = await renderNode(head, registry, { ui: { descriptionExpanded: true } });
        const user = userEvent.setup();
        const button = screen.getByRole('button');
        expect(button.getAttribute('aria-expanded')).toBe('true');

        await tabTo(user, button);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils).ui?.descriptionExpanded).toBe(false));
        await user.keyboard(' ');
        await waitFor(() => expect(localOf(utils).ui?.descriptionExpanded).toBe(true));
        utils.cleanup();
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('엑셀 내려받기 상태 필터 배지 — Tab 으로 「완료」 에 닿아 Enter 로 고르고 aria-pressed 가 따라온다', async () => {
        const row = collect(excelDownloadIndex, (n) => n.id === 'status_filter_row')[0];
        const utils = await renderNode(row, registry, { statusFilter: '' });
        const user = userEvent.setup();
        const buttons = screen.getAllByRole('button');
        expect(buttons).toHaveLength(5);
        expect(buttons.map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false', 'false', 'false']);

        await tabTo(user, buttons[1]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils).statusFilter).toBe('completed'));
        utils.cleanup();
    });

    // @scenario surface=admin_layout
    // @effects click_only_region_keyboard_operable
    it('마일리지 내역 기간 빠른 선택 「전체」 — Tab 으로 닿아 Space 로 고른다', async () => {
        const all = collect(mileageFilters, (n) => String(n.props?.className ?? '').includes("dateQuick === 'all'"))[0];
        const utils = await renderNode(all, registry, { filter: { dateQuick: 'week', startDate: '2026-10-01', endDate: '2026-10-07' } });
        const user = userEvent.setup();
        const button = screen.getByRole('button');
        expect(button.getAttribute('aria-pressed')).toBe('false');

        await tabTo(user, button);
        await user.keyboard(' ');
        await waitFor(() => expect(localOf(utils).filter?.dateQuick).toBe('all'));
        expect(localOf(utils).filter?.startDate).toBe('');
        utils.cleanup();
    });

    // @scenario surface=admin_row
    // @effects click_only_region_keyboard_operable
    it('결제 실패 이력 표 행 — 행 끝 펼치기 단추로 Tab·Enter 로 행을 펼친다(단추는 자기 동작 없이 행 클릭으로 올린다)', async () => {
        const tbody = collect(paymentFailureHistory, (n) => n.name === 'Tbody')[0];
        const utils = await renderNode({ type: 'basic', name: 'Table', children: [tbody] }, registry, { expandedRows: [] });
        const user = userEvent.setup();
        const toggles = screen.getAllByRole('button').filter((b) => b.hasAttribute('aria-expanded'));
        expect(toggles).toHaveLength(10);
        expect(toggles.every((b) => b.getAttribute('aria-expanded') === 'false')).toBe(true);
        expect(toggles.every((b) => (b.getAttribute('aria-label') ?? '') !== '')).toBe(true);

        await tabTo(user, toggles[1]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(localOf(utils).expandedRows).toEqual([2]));
        utils.cleanup();
    });
});
