/**
 * @file admin-board-post-form-attachments-desc.test.tsx
 * @description 관리자 게시글 폼 첨부 안내가 게시판 설정값으로 렌더되는지 검증 (공개 #81 L-01)
 *
 * 결함: 관리자 게시글 폼의 첨부 영역에는 안내 문구가 아예 없었고, 대신 사장된 lang 키
 *   ("최대 5개 파일, 각 10MB 이하")가 남아 있었다. 그 문구는 어디에도 렌더되지 않는데,
 *   설령 렌더되었어도 게시판마다 다른 실제 제한과 무관한 고정 숫자였다.
 *
 *   운영자는 게시판 설정에서 첨부 개수·용량을 바꿀 수 있으므로, 안내는 반드시
 *   `form_meta.data.board.max_file_count/max_file_size` 를 파라미터로 받아야 한다.
 *   유저 폼(`_post_form.json` 의 `board.form.attachments_desc`)이 이미 그 형태다.
 *
 * @scenario surface=admin_post_form, source=board_settings
 * @effects admin_attachment_hint_renders_board_limits
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import attachmentsPartial from '../../../layouts/admin/partials/admin_board_post_form/_attachments.json';
import koPosts from '../../../lang/partial/ko/admin/posts.json';

/** 안내 노드 id (레이아웃 JSON) */
const DESC_ID = 'attachments_desc';

/**
 * partial 트리에서 id 로 노드를 찾습니다.
 *
 * @param node 순회 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 (없으면 null)
 */
function findById(node: any, id: string): any | null {
    if (!node || typeof node !== 'object') return null;
    if (node.id === id) return node;
    for (const child of node.children ?? []) {
        const found = findById(child, id);
        if (found) return found;
    }
    return null;
}

describe('관리자 첨부 안내 — 선언 (#81 L-01)', () => {
    it('안내 노드가 존재하고 게시판 설정값을 파라미터로 넘긴다', () => {
        const node = findById(attachmentsPartial, DESC_ID);

        expect(node, '첨부 안내 노드가 없다').not.toBeNull();
        expect(node.text).toContain('$t:sirsoft-board.admin.posts.form.attachments_desc');
        expect(node.text).toContain('maxFiles={{form_meta?.data?.board?.max_file_count');
        expect(node.text).toContain('maxSize={{form_meta?.data?.board?.max_file_size');
    });

    it('다크 모드 variant 를 함께 지정한다', () => {
        const node = findById(attachmentsPartial, DESC_ID);

        expect(node.props.className).toMatch(/text-gray-\d+/);
        expect(node.props.className).toMatch(/dark:text-gray-\d+/);
    });

    it('lang 키가 ko 에 정의되어 있고 파라미터를 포함한다', () => {
        const value = (koPosts as any).form?.attachments_desc;

        expect(value, 'attachments_desc 키가 없다').toBeTruthy();
        expect(value).toContain('{{maxFiles}}');
        expect(value).toContain('{{maxSize}}');
    });

    it('사장된 고정 숫자 안내 키가 남아 있지 않다', () => {
        expect((koPosts as any).form?.attachments_limit).toBeUndefined();
        expect((koPosts as any).form?.max_10mb).toBeUndefined();
    });
});

describe('관리자 첨부 안내 — 렌더 결과 (#81 L-01)', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    /**
     * 안내 노드만 떼어내 게시판 설정값과 함께 렌더합니다.
     *
     * FileUploader 는 composite 라 mock 레지스트리에 없으므로, 검증 대상인 안내 노드만
     * 실제 레이아웃 JSON 에서 가져와 렌더한다 (문구 자체는 실 JSON 선언 그대로다).
     *
     * @param board 게시판 설정 (max_file_count / max_file_size)
     * @returns 렌더 유틸
     */
    async function renderDesc(board: { max_file_count: number; max_file_size: number }) {
        const node = findById(attachmentsPartial, DESC_ID);

        const utils = createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'admin_attachments_desc_probe',
                data_sources: [],
                components: [{ ...node, props: { ...node.props, 'data-testid': DESC_ID } }],
            } as never,
            {
                componentRegistry: createMockComponentRegistryWithBasics() as never,
                locale: 'ko',
                templateId: 'test-template',
                translations: {
                    'sirsoft-board': { admin: { posts: { form: { attachments_desc: (koPosts as any).form.attachments_desc } } } },
                },
                initialData: { form_meta: { data: { board } } },
            }
        );

        await utils.render();

        return utils;
    }

    it('게시판이 3개·50MB 로 설정되어 있으면 그 값이 안내에 나온다', async () => {
        const utils = await renderDesc({ max_file_count: 3, max_file_size: 50 });

        // 존재를 먼저 확정한 뒤에야 문구를 단언한다 (부재 단언은 렌더 전에도 통과한다)
        const el = await screen.findByTestId(DESC_ID);
        expect(document.body.contains(el)).toBe(true);
        expect(el.textContent).toBe('최대 3개, 각 50MB 이하');

        utils.cleanup();
    });

    it('게시판 설정이 바뀌면 안내도 따라 바뀐다 (고정 문구가 아니다)', async () => {
        const utils = await renderDesc({ max_file_count: 10, max_file_size: 2 });

        const el = await screen.findByTestId(DESC_ID);
        expect(el.textContent).toBe('최대 10개, 각 2MB 이하');
        // 종전 사장 키의 고정 숫자가 다시 새어 나오지 않는다
        expect(el.textContent).not.toContain('5개');

        utils.cleanup();
    });
});
