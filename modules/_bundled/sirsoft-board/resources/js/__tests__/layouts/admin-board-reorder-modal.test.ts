/**
 * 관리자 게시판 목록 — 「순서 변경」 단추·모달 계약 (#142)
 *
 * @description
 * 이 화면이 지키는 계약:
 *  - 단추는 범위 제한 없는 조회·수정 권한(`abilities.can_reorder`)이 있고 게시판이 2개 이상일 때만 보인다.
 *  - 단추를 누르면 **전체 게시판**(활성·비활성)을 표시 순서로 새로 받아 작업본(`reorderItems`)과 원본(`reorderOriginal`)에 싣는다
 *    — 모달은 별도 컨텍스트라 페이지 `_local` 을 볼 수 없어 전역에 싣는다(복사·삭제 모달과 같은 방식).
 *  - ▲▼ 는 두 자리를 맞바꾸고, 첫 행 ▲·끝 행 ▼ 는 잠긴다. 끌기가 끝나면 끌어 놓은 순서를 그대로 작업본으로 삼는다.
 *  - 작업본 순서가 원본과 같으면 저장이 잠긴다. 저장은 전체 id 목록을 보내고, 422 는 모달 안 오류 상자에, 그 밖은 토스트로 알린다.
 *  - 취소는 작업본을 버린다. 비활성 게시판은 흐린 글자 + 「비활성」 배지로 보인다.
 *  - 끌기 손잡이는 좁은 화면에서 숨기고 낭독기에도 숨긴다(키보드·좁은 화면은 ▲▼ 로 옮긴다).
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다 — 손으로 만든 fixture 를 검사하면 실제 화면이 바뀌어도 초록이 유지된다.
 * 바인딩 식은 문자열 비교로 끝내지 않고 실제 값으로 평가해 동작을 본다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/admin_board_index.json';
import koBoard from '../../../lang/partial/ko/admin/board.json';
import enBoard from '../../../lang/partial/en/admin/board.json';
import jaBoard from '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-board-ja/frontend/partial/admin/board.json';

type Node = Record<string, any>;

/** 트리에서 조건에 맞는 첫 노드를 찾는다. */
function find(node: unknown, pred: (n: Node) => boolean): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = find(child, pred);
            if (found) return found;
        }
        return null;
    }
    if (node && typeof node === 'object') {
        const obj = node as Node;
        if (pred(obj)) return obj;
        for (const value of Object.values(obj)) {
            const found = find(value, pred);
            if (found) return found;
        }
    }
    return null;
}

/** 트리에서 조건에 맞는 노드를 모두 모은다. */
function findAll(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, pred, out));
        return out;
    }
    if (node && typeof node === 'object') {
        const obj = node as Node;
        if (pred(obj)) out.push(obj);
        Object.values(obj).forEach((value) => findAll(value, pred, out));
    }
    return out;
}

/** `{{ … }}` 바인딩 하나를 주어진 문맥으로 평가한다. */
function evaluate(binding: string, scope: Record<string, unknown>): any {
    const match = /^\{\{([\s\S]*)\}\}$/.exec(binding.trim());
    if (!match) throw new Error(`바인딩이 아니다: ${binding}`);
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    return new Function(...names, `return (${match[1]});`)(...names.map((n) => scope[n]));
}

const byTestId = (prefix: string) => (n: Node) => typeof n.props?.['data-testid'] === 'string' && n.props['data-testid'].startsWith(prefix);

const reorderButton = find(layout, (n) => n.id === 'reorder_button')!;
const modal = find(layout, (n) => n.id === 'board_reorder_modal')!;
const list = find(modal, (n) => n.id === 'reorder_list')!;
const upButton = find(list, byTestId('board-reorder-up-'))!;
const downButton = find(list, byTestId('board-reorder-down-'))!;
const saveButton = find(modal, byTestId('board-reorder-save'))!;
const cancelButton = find(modal, byTestId('board-reorder-cancel'))!;

/** 클릭 액션 안의 setState 를 모두 모은다. */
const setStatesOf = (node: Node) => findAll(node.actions ?? [], (n) => n.handler === 'setState');

const items = [
    { id: 4, slug: 'event', name: '이벤트', is_active: true },
    { id: 5, slug: 'qna', name: 'Q&A', is_active: true },
    { id: 8, slug: 'archive', name: '아카이브', is_active: false },
];
const withIdx = (rows: typeof items) => rows.map((r, i) => ({ ...r, _idx: i }));

describe('순서 변경 단추', () => {
    // @scenario case=modal_button_visibility
    // @effects reorder_modal_contract
    it('범위 제한 없는 수정 권한이 있고 게시판이 2개 이상일 때만 보인다', () => {
        const cond = reorderButton.if as string;
        const boards = (canReorder: boolean, total: number) => ({ data: { abilities: { can_reorder: canReorder }, pagination: { total } } });

        expect(evaluate(cond, { boards: boards(true, 2) })).toBe(true);
        expect(evaluate(cond, { boards: boards(true, 1) })).toBe(false);
        expect(evaluate(cond, { boards: boards(false, 13) })).toBe(false);
        expect(evaluate(cond, { boards: undefined })).toBeFalsy();
    });

    // @scenario case=modal_button_visibility
    // @effects reorder_modal_contract
    it('전체 게시판을 표시 순서로 받아 작업본·원본에 같은 값을 싣고 모달을 연다', () => {
        const apiCall = find(reorderButton.actions, (n) => n.handler === 'apiCall')!;
        expect(apiCall.target).toBe('/api/modules/sirsoft-board/admin/boards');
        expect(apiCall.params?.body ?? apiCall.params?.data ?? apiCall.body).toBeDefined();
        const json = JSON.stringify(apiCall);
        expect(json).toContain('"per_page":999');
        expect(json).toContain('"sort_by":"display_order"');
        expect(json).toContain('"sort_order":"asc"');

        const onSuccess = setStatesOf({ actions: apiCall.onSuccess ?? apiCall.params?.onSuccess ?? [] })[0]
            ?? findAll(apiCall, (n) => n.handler === 'setState' && 'reorderItems' in (n.params ?? {}))[0];
        const response = { data: { data: items.map((b) => ({ ...b, posts_count: 3, type: 'basic' })) } };
        const work = evaluate(onSuccess.params.reorderItems, { response });
        const original = evaluate(onSuccess.params.reorderOriginal, { response });
        expect(work).toEqual(items);
        expect(original).toEqual(items);
        expect(onSuccess.params.target).toBe('global');
        expect(JSON.stringify(apiCall)).toContain('"board_reorder_modal"');
    });
});

describe('순서 변경 모달 — 옮기기', () => {
    // @scenario case=modal_move_swaps_and_edges
    // @effects reorder_modal_contract
    it('▲ 는 위 행과, ▼ 는 아래 행과 자리를 맞바꾼다', () => {
        const up = setStatesOf(upButton)[0].params.reorderItems as string;
        const down = setStatesOf(downButton)[0].params.reorderItems as string;
        const _global = { reorderItems: items };

        expect(evaluate(up, { _global, row: withIdx(items)[1] }).map((r: any) => r.slug)).toEqual(['qna', 'event', 'archive']);
        expect(evaluate(down, { _global, row: withIdx(items)[1] }).map((r: any) => r.slug)).toEqual(['event', 'archive', 'qna']);
    });

    // @scenario case=modal_move_swaps_and_edges
    // @effects reorder_modal_contract
    it('첫 행 ▲·끝 행 ▼ 와 저장 중에는 단추가 잠긴다', () => {
        const _global = { reorderItems: items, reorderSaving: false };
        const rows = withIdx(items);

        expect(evaluate(upButton.props.disabled, { _global, row: rows[0] })).toBe(true);
        expect(evaluate(upButton.props.disabled, { _global, row: rows[1] })).toBe(false);
        expect(evaluate(downButton.props.disabled, { _global, row: rows[2] })).toBe(true);
        expect(evaluate(downButton.props.disabled, { _global, row: rows[1] })).toBe(false);
        expect(evaluate(upButton.props.disabled, { _global: { ...(_global as object), reorderSaving: true }, row: rows[1] })).toBe(true);
    });

    // @scenario case=modal_sort_end_applies
    // @effects reorder_modal_contract
    it('끌기가 끝나면 끌어 놓은 순서를 작업본으로 삼고 화면용 자리 번호는 싣지 않는다', () => {
        expect(list.sortable.handle).toBe('[data-drag-handle]');
        const onSortEnd = findAll(list.actions ?? [], (n) => n.handler === 'setState')[0];
        const sorted = withIdx(items).reverse();

        const next = evaluate(onSortEnd.params.reorderItems, { $sortedItems: sorted });

        expect(next.map((r: any) => r.slug)).toEqual(['archive', 'qna', 'event']);
        expect(next.every((r: any) => !('_idx' in r))).toBe(true);
    });

    // @scenario case=modal_inactive_badge
    // @effects reorder_modal_contract
    it('비활성 게시판은 「비활성」 배지와 흐린 글자로 구분하고, 끌기 손잡이는 좁은 화면·낭독기에서 숨긴다', () => {
        const badge = find(list, byTestId('board-reorder-inactive-'))!;
        expect(evaluate(badge.if, { row: { is_active: false } })).toBe(true);
        expect(evaluate(badge.if, { row: { is_active: true } })).toBe(false);
        expect(badge.text ?? JSON.stringify(badge.children)).toContain('sirsoft-board.admin.board.index.reorder.inactive');

        const row = find(list, byTestId('board-reorder-row-'))!;
        expect(row.props.className).toContain("row.is_active ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500'");

        const handle = find(list, byTestId('board-reorder-drag-'))!;
        expect(handle.props.className).toContain('hidden md:flex');
        expect(handle.props['aria-hidden']).toBe('true');
        // 이름은 `$t()` 식의 인자로 넘긴다 — `$t:키|name={{row.name}}` 꼴은 이름의 `&`(예: Q&A)에서 인자가 잘린다(실측 「Q 위로 옮기기」)
        const t = (key: string, params: Record<string, string>) => `${key}:${params.name}`;
        expect(evaluate(upButton.props['aria-label'], { $t: t, row: { name: 'Q&A' } })).toBe('sirsoft-board.admin.board.index.reorder.move_up:Q&A');
        expect(evaluate(downButton.props['aria-label'], { $t: t, row: { name: 'Q&A' } })).toBe('sirsoft-board.admin.board.index.reorder.move_down:Q&A');
        expect(evaluate(handle.props.title, { $t: t, row: { name: 'Q&A' } })).toBe('sirsoft-board.admin.board.index.reorder.drag_handle:Q&A');
        // 좁은 화면에서도 ▲▼ 의 누르는 영역은 40px 이상이다
        expect(upButton.props.className).toContain('w-10 h-10');

        // 행마다 슬러그 배지 — 목록 카드의 슬러그 배지와 같은 컴포넌트
        const slug = find(list, byTestId('board-reorder-slug-'))!;
        expect(slug.name).toBe('StatusBadge');
        expect(slug.props.label).toBe('{{row.slug}}');
    });
});

describe('순서 변경 모달 — 목록 틀', () => {
    // @scenario case=modal_inactive_badge
    // @effects reorder_modal_contract
    it('테두리·스크롤 영역은 정렬 노드가 아니라 바깥 래퍼에 둔다 — 정렬 노드의 class 는 렌더에서 빠진다(실측)', () => {
        const frame = find(modal, (n) => n.id === 'reorder_list_frame')!;
        expect(frame.props.className).toContain('overflow-y-auto');
        expect(frame.props.className).toContain('border');
        expect(frame.props['data-testid']).toBe('board-reorder-list');
        expect(find(frame, (n) => n.id === 'reorder_list')?.sortable).toBeDefined();
        // 높이 상한은 관리자 템플릿 safelist 에 있는 임의값만 빌드된다 — max-h-[60vh] 는 빌드되지 않아 무효였다(실측)
        expect(frame.props.className).toContain('max-h-[70vh]');
    });
});

describe('순서 변경 모달 — 저장·취소', () => {
    // @scenario case=modal_save_disabled_when_unchanged
    // @effects reorder_modal_contract
    it('순서가 원본과 같거나 저장 중이면 저장이 잠긴다', () => {
        const cond = saveButton.props.disabled as string;
        const swapped = [items[1], items[0], items[2]];

        expect(evaluate(cond, { _global: { reorderItems: items, reorderOriginal: items, reorderSaving: false } })).toBe(true);
        expect(evaluate(cond, { _global: { reorderItems: swapped, reorderOriginal: items, reorderSaving: false } })).toBe(false);
        expect(evaluate(cond, { _global: { reorderItems: swapped, reorderOriginal: items, reorderSaving: true } })).toBe(true);
    });

    // @scenario case=modal_422_error_box
    // @effects reorder_modal_contract
    it('저장은 전체 id 목록을 PUT 으로 보내고, 422 는 모달 안 오류 상자에·그 밖은 토스트로 알린다', () => {
        const apiCall = find(saveButton.actions, (n) => n.handler === 'apiCall')!;
        expect(apiCall.target).toBe('/api/modules/sirsoft-board/admin/boards/order');
        expect(String(apiCall.params?.method ?? apiCall.method).toUpperCase()).toBe('PUT');
        const bodyBinding = find(apiCall, (n) => typeof n.board_ids === 'string')!.board_ids;
        expect(evaluate(bodyBinding, { _global: { reorderItems: [items[2], items[0], items[1]] } })).toEqual([8, 4, 5]);

        const json = JSON.stringify(apiCall);
        expect(json).toContain('error.status === 422');
        const errorSet = findAll(apiCall, (n) => n.handler === 'setState' && typeof n.params?.reorderError === 'string')[0];
        expect(evaluate(errorSet.params.reorderError, { error: { errors: { board_ids: ['목록이 바뀌었습니다'] }, message: '입력값 오류' } })).toBe('목록이 바뀌었습니다');
        expect(evaluate(errorSet.params.reorderError, { error: { message: '입력값 오류' } })).toBe('입력값 오류');
        expect(findAll(apiCall, (n) => n.handler === 'toast' && n.params?.type === 'error').length).toBeGreaterThan(0);
        expect(json).toContain('"dataSourceId":"boards"');

        const errorBox = find(modal, byTestId('board-reorder-error'))!;
        expect(evaluate(errorBox.if, { _global: { reorderError: '목록이 바뀌었습니다' } })).toBe(true);
        expect(evaluate(errorBox.if, { _global: { reorderError: null } })).toBe(false);
        expect(errorBox.props.role).toBe('alert');
    });

    // @scenario case=modal_cancel_discards
    // @effects reorder_modal_contract
    it('취소는 모달을 닫고 작업본을 버린다(저장하지 않는다)', () => {
        const json = JSON.stringify(cancelButton.actions);
        expect(json).toContain('"closeModal"');
        expect(json).not.toContain('apiCall');
        const discard = setStatesOf(cancelButton).find((n) => 'reorderItems' in (n.params ?? {}))!;
        expect(discard.params.reorderItems).toBeNull();
        expect(discard.params.reorderOriginal).toBeNull();
    });

    // @scenario case=modal_cancel_discards
    // @effects reorder_modal_contract
    it('✕·Esc·바깥 클릭으로 닫아도 취소와 같이 모달을 닫고 작업본을 버린다', () => {
        const onClose = (modal.actions ?? []).find((a: Node) => a.event === 'onClose')!;
        expect(onClose).toBeDefined();
        const json = JSON.stringify(onClose);
        expect(json).toContain('"closeModal"');
        expect(json).not.toContain('apiCall');
        const discard = findAll(onClose, (n) => n.handler === 'setState').find((n) => 'reorderItems' in (n.params ?? {}))!;
        expect(discard.params.reorderItems).toBeNull();
        expect(discard.params.reorderOriginal).toBeNull();
        expect(discard.params.reorderError).toBeNull();
    });

    // @scenario case=modal_cancel_discards
    // @effects reorder_modal_contract
    it('모달 문구는 ko·en·ja 세 언어에 모두 있다', () => {
        for (const [label, lang] of [['ko', koBoard], ['en', enBoard], ['ja', jaBoard]] as const) {
            const reorder = (lang as any).index.reorder;
            for (const key of ['button', 'title', 'description', 'drag_handle', 'move_up', 'move_down', 'inactive', 'save', 'saving', 'cancel']) {
                expect(typeof reorder?.[key], `${label}.${key}`).toBe('string');
            }
            expect(reorder.move_up, label).toContain('{{name}}');
        }
    });
});
