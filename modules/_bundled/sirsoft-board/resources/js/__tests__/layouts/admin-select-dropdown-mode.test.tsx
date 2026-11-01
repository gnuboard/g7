// e2e:allow 실제 관리자 템플릿 Select 를 엔진 렌더러로 그려 목록형(단추 + 목록 상자) 여부와 고른 값이 상태까지 닿는지 잰다 — 브라우저 축은 반응 관리 Playwright(board-reactions-index.spec.ts) 와 출시 전 화면 재실측이 덮는다
/**
 * @file admin-select-dropdown-mode.test.tsx
 * @description 게시판 관리자 선택칸이 브라우저 기본 선택상자가 아니라 관리자 템플릿의 목록형 드롭다운으로 그려진다
 *
 * 관리자 템플릿 Select 는 `props.options` 가 있으면 단추(`aria-haspopup=listbox`) + 목록 상자로, 없으면 브라우저 기본
 * `<select>` 로 그린다. 반복(`data-each`)으로 만든 `<option>` 자식을 적으면 options 가 비어 기본 선택상자가 됐다
 * (글 상세 신고 사유, 글 작성 분류, 반응 관리 게시판·유형 필터, 스크랩 관리 게시판 필터).
 *
 * 실물 Select 를 엔진 렌더러(createLayoutTest)로 그리고, 단추를 눌러 항목을 고르면 그 칸의 change 동작(`$event.target.value`)
 * 이나 폼 자동 바인딩이 고른 값을 상태에 담는지, 단추 글자가 고른 값으로 바뀌는지를 본다.
 *
 * @scenario-manifest tests/scenarios/admin-select-dropdown-mode.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { Select } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/basic/Select';

import postDetail from '../../../layouts/admin/admin_board_post_detail.json';
import postFormFields from '../../../layouts/admin/partials/admin_board_post_form/_form_fields.json';
import reactionsFilter from '../../../layouts/admin/partials/admin_board_reactions_index/_partial_filter.json';
import scrapsFilter from '../../../layouts/admin/partials/admin_board_scraps_index/_partial_filter.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 결과 누적
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, match, acc));

        return acc;
    }
    if (!node || typeof node !== 'object') return acc;
    if (match(node as Node)) acc.push(node as Node);
    Object.values(node as Node).forEach((value) => findAll(value, match, acc));

    return acc;
}

const selects = (root: unknown): Node[] => findAll(root, (n) => n.name === 'Select');
const reportReasonSelect = selects(postDetail).find((n) => JSON.stringify(n.actions ?? []).includes('report_reason_type'));
const categorySelect = selects(postFormFields).find((n) => n.props?.name === 'category');
const reactionBoardSelect = selects(reactionsFilter).find((n) => n.id === 'board_filter_select');
const reactionTypeSelect = selects(reactionsFilter).find((n) => n.id === 'reaction_type_filter_select');
const scrapBoardSelect = selects(scrapsFilter).find((n) => n.id === 'board_filter_select');

let registry: MockComponentRegistry;
let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 노드를 실물 Select 로 그린다.
 *
 * @param components 그릴 노드
 * @param options 초기 상태·데이터
 * @returns 그린 상자
 */
async function draw(components: Node[], options: Record<string, any> = {}): Promise<HTMLElement> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-board-select-dropdown', components } as any,
        { componentRegistry: registry as any, locale: 'ko', ...options },
    );
    const { container } = await utils.render();

    return container;
}

/**
 * 목록형 단추 하나를 돌려준다(브라우저 기본 선택상자가 없어야 한다).
 *
 * @param container 그린 상자
 * @returns 목록형 단추
 */
function onlyTrigger(container: HTMLElement): HTMLButtonElement {
    expect(container.querySelector('select'), '브라우저 기본 선택상자').toBeNull();
    const found = container.querySelectorAll<HTMLButtonElement>('button[aria-haspopup="listbox"]');
    expect(found).toHaveLength(1);

    return found[0];
}

/**
 * 단추를 눌러 글자가 맞는 항목을 고른다.
 *
 * @param trigger 목록형 단추
 * @param label 고를 항목 글자(정규식)
 */
async function choose(trigger: HTMLElement, label: RegExp): Promise<void> {
    fireEvent.click(trigger);
    const option = await waitFor(() => {
        const found = screen.getAllByRole('option').find((o) => label.test(o.textContent ?? ''));
        expect(found, `항목 ${label}`).toBeTruthy();

        return found!;
    });
    fireEvent.click(option);
}

/**
 * 노드가 목록형(options) 표기인지 — `<option>` 자식(브라우저 기본 선택상자)이 아니다.
 *
 * @param node Select 노드
 */
function expectOptionsNotation(node: Node | undefined): void {
    expect(node, 'Select 노드').toBeTruthy();
    expect(node!.props?.options, 'props.options').toBeDefined();
    expect(findAll(node!.children ?? [], (n) => n.name === 'Option')).toHaveLength(0);
}

describe('게시판 관리자 선택칸 — 목록형 드롭다운', () => {
    // @scenario screen=post_report_reason
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('글 상세 신고 창의 사유 종류는 목록형이고 고른 사유가 담겨 단추에 남는다', async () => {
        expectOptionsNotation(reportReasonSelect);
        const container = await draw([reportReasonSelect!], {
            initialState: { _local: {} },
            initialData: { post: { data: { board: { report_types: [{ value: 'spam', label: '스팸' }, { value: 'abuse', label: '욕설' }] } } } },
        });
        const trigger = onlyTrigger(container);
        expect(trigger.textContent).toContain('report_modal.reason_type_placeholder');

        await choose(trigger, /^욕설$/);
        await waitFor(() => expect(utils!.getState()._local.report_reason_type).toBe('abuse'));
        expect(trigger.textContent).toContain('욕설');
    });

    // @scenario screen=post_form_category
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('글 작성 폼의 분류는 목록형이고 고르면 폼 자동 바인딩으로 form.category 에 담기며, 답글이면 잠긴다', async () => {
        expectOptionsNotation(categorySelect);
        const formBox = (select: Node): Node => ({ id: 'post_form_box', type: 'basic', name: 'Div', dataKey: 'form', children: [select] });
        const data = { form_meta: { data: { board: { categories: ['공지', '질문'] } } } };

        const container = await draw([formBox(categorySelect!)], { initialState: { _local: { form: { category: '' } } }, initialData: data });
        const trigger = onlyTrigger(container);
        expect(trigger.textContent).toContain('posts.form.select_category');
        expect(trigger.disabled).toBe(false);

        await choose(trigger, /^질문$/);
        await waitFor(() => expect(trigger.textContent).toContain('질문'));
        // 자동 바인딩은 운영에서 G7Core.state.setLocal({ 'form.category': 값 }) 으로 쓴다 — 시험 도구의 setLocal 은 점 경로를
        // 풀지 않고 그 키 그대로 얕게 담으므로 두 모양을 함께 읽는다
        const local = utils!.getState()._local;
        expect(local['form.category'] ?? local.form?.category).toBe('질문');
        utils!.cleanup();

        // 답글은 원글의 분류를 따른다 — 칸이 잠긴다(종전 브라우저 기본 선택상자의 disabled 와 같다)
        const replyContainer = await draw([formBox(categorySelect!)], { initialState: { _local: { form: { category: '공지', parent_id: 9 } } }, initialData: data });
        const locked = onlyTrigger(replyContainer);
        expect(locked.disabled).toBe(true);
        expect(locked.textContent).toContain('공지');
    });

    // @scenario screen=reaction_filters
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('반응 관리의 게시판·유형 필터는 목록형이고 고른 값이 전역 필터에 문자열 번호로 담긴다(비활성 유형도 항목에 남는다)', async () => {
        expectOptionsNotation(reactionBoardSelect);
        expectOptionsNotation(reactionTypeSelect);
        const data = {
            reactions: {
                data: {
                    boards: [{ id: 7, name: '자유게시판' }],
                    reaction_types: [{ id: 1, name: '추천' }, { id: 2, name: '비추천', is_active: false }],
                },
            },
        };

        const boardContainer = await draw([reactionBoardSelect!], { initialState: { _global: {} }, initialData: data });
        const board = onlyTrigger(boardContainer);
        expect(board.textContent).toContain('reactions.filters.board_all');
        await choose(board, /^자유게시판$/);
        await waitFor(() => expect(utils!.getState()._global.reactionBoardFilter).toBe('7'));
        utils!.cleanup();

        const typeContainer = await draw([reactionTypeSelect!], { initialState: { _global: {} }, initialData: data });
        const type = onlyTrigger(typeContainer);
        fireEvent.click(type);
        await waitFor(() => expect(screen.getAllByRole('option').some((o) => (o.textContent ?? '').includes('type_inactive_suffix'))).toBe(true));
        fireEvent.click(type);
        await choose(type, /^비추천/);
        await waitFor(() => expect(utils!.getState()._global.reactionTypeFilter).toBe('2'));
    });

    // @scenario screen=scrap_filters
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('스크랩 관리의 게시판 필터는 목록형이고 고른 값이 전역 필터에 문자열 번호로 담긴다', async () => {
        expectOptionsNotation(scrapBoardSelect);
        const container = await draw([scrapBoardSelect!], {
            initialState: { _global: {} },
            initialData: { scraps: { data: { boards: [{ id: 3, name: '갤러리' }] } } },
        });
        const trigger = onlyTrigger(container);
        expect(trigger.textContent).toContain('scraps.filters.board_all');

        await choose(trigger, /^갤러리$/);
        await waitFor(() => expect(utils!.getState()._global.scrapBoardFilter).toBe('3'));
        expect(trigger.textContent).toContain('갤러리');
    });
});
