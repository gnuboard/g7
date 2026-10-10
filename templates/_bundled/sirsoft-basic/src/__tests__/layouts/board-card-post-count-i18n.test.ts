/**
 * @file board-card-post-count-i18n.test.ts
 * @description 전체 게시판 카드의 글 수가 언어와 무관하게 「N개」로 나오던 하드코딩 회귀 (7.1.0 실측 D-N3)
 *
 * 결함: `partials/board/boards/_board_card` 의 글 수 배지가 `{{board.posts_count ?? 0}}개` 로 적혀 있어
 *   영어·일본어 화면에서도 「16개」로 보였다. 같은 뜻의 다국어 키 `board.post_count`(ko 「{{count}}개」,
 *   en 「{{count}} posts」)가 이미 있는데 쓰지 않았다.
 *
 * 함께 잠그는 것: 이커머스(shop) 밖 사용자 레이아웃의 `text` 에 한글이 직접 적힌 자리가 없어야 한다
 *   (문구는 `$t:` 키로만). shop 레이아웃의 통화 단위 대체값은 이커머스 영역에서 따로 다룬다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const TEMPLATE_ROOT = path.resolve(__dirname, '../../..');
const LAYOUTS_ROOT = path.join(TEMPLATE_ROOT, 'layouts');

type LayoutNode = {
    comment?: string;
    text?: string;
    children?: LayoutNode[];
    [key: string]: unknown;
};

/**
 * 레이아웃 트리를 깊이 우선으로 순회하며 노드를 모읍니다.
 *
 * @param node 시작 노드 (객체·배열 모두 허용)
 * @param out 결과 누적 배열
 * @returns 모든 객체 노드
 */
function collectNodes(node: unknown, out: LayoutNode[] = []): LayoutNode[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectNodes(child, out));
    } else if (node && typeof node === 'object') {
        out.push(node as LayoutNode);
        Object.values(node as Record<string, unknown>).forEach((value) => collectNodes(value, out));
    }

    return out;
}

/**
 * 디렉터리 아래 모든 .json 레이아웃 경로를 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @returns 레이아웃 파일 경로 목록
 */
function listLayoutJson(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return listLayoutJson(full);
        }

        return entry.name.endsWith('.json') ? [full] : [];
    });
}

describe('전체 게시판 카드 글 수 다국어 (D-N3)', () => {
    /**
     * @scenario surface=all_boards_card
     * @effects board_card_post_count_uses_translation_key
     */
    it('글 수 배지가 board.post_count 키를 count 인자로 쓴다', () => {
        const layout = JSON.parse(
            fs.readFileSync(path.join(LAYOUTS_ROOT, 'partials/board/boards/_board_card.json'), 'utf8'),
        );
        const badge = collectNodes(layout).find((node) => node.comment === '게시글 수 배지');

        expect(badge, '게시글 수 배지 노드').toBeDefined();
        expect(badge!.text).toBe('$t:board.post_count|count={{board.posts_count ?? 0}}');
    });

    /**
     * @scenario surface=all_boards_card
     * @effects board_card_post_count_uses_translation_key
     */
    it('ko·en 언어 파일에 count 자리표시자를 가진 board.post_count 가 있다', () => {
        for (const locale of ['ko', 'en']) {
            const board = JSON.parse(
                fs.readFileSync(path.join(TEMPLATE_ROOT, `lang/partial/${locale}/board.json`), 'utf8'),
            );
            expect(board.post_count, `${locale} board.post_count`).toContain('{{count}}');
        }
    });

    /**
     * @scenario surface=non_shop_layouts
     * @effects non_shop_layouts_have_no_hardcoded_korean_text
     */
    it('shop 밖 사용자 레이아웃 text 에 한글이 직접 적힌 자리가 없다', () => {
        const offenders = listLayoutJson(LAYOUTS_ROOT)
            .filter((file) => !file.split(path.sep).includes('shop'))
            .flatMap((file) =>
                collectNodes(JSON.parse(fs.readFileSync(file, 'utf8')))
                    .filter((node) => typeof node.text === 'string' && /[가-힣]/.test(node.text) && !node.text.startsWith('$t:'))
                    .map((node) => `${path.relative(LAYOUTS_ROOT, file)}: ${node.text}`),
            );

        expect(offenders).toEqual([]);
    });
});
