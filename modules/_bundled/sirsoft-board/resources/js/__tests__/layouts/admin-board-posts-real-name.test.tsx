// e2e:allow 관리자 화면의 작성자(회원) 이름 아래 실명 보조 줄을 실물 레이아웃 노드로 그려, 응답에 실명 키가 있을 때만 보이는지 잰다 — 브라우저 축은 #490 화면 실측이 덮는다
/**
 * @file admin-board-posts-real-name.test.tsx
 * @description 게시판 관리자 화면 — 작성자(회원) 이름의 실명 보조 줄 (#490 P17)
 *
 * 관리자 응답의 이름 키에는 「관리자 화면의 표시 이름」이 실리고, 요청한 관리자에게 회원 조회 권한이 있고
 * 실명이 표시 이름과 다를 때만 실명이 별도 키(`real_name`, `author_real_name`)로 실린다.
 * 레이아웃은 그 키가 있을 때만 이름 곁에 작고 흐린 실명 줄을 그리고, 화면 낭독기용 이름(「실명: 홍길동」)을 준다.
 * 이름을 그리는 식 자체(기존 키)는 바꾸지 않는다.
 *
 * @scenario-manifest tests/scenarios/admin-board-real-name-line.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { waitFor } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import postsIndex from '../../../layouts/admin/admin_board_posts_index.json';
import scrapsIndex from '../../../layouts/admin/admin_board_scraps_index.json';
import reactionsIndex from '../../../layouts/admin/admin_board_reactions_index.json';
import reportsIndex from '../../../layouts/admin/admin_board_reports_index.json';
import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';
import commentCard from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import reportInfo from '../../../layouts/admin/partials/admin_board_reports_detail/_card_report_info.json';
import dashboard from '../../../extensions/admin_dashboard_community.json';
import koCommon from '../../../lang/partial/ko/common.json';
import enCommon from '../../../lang/partial/en/common.json';

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

/** 실명 줄 노드 */
const realNameNodes = (root: unknown): Node[] => findAll(root, (n) => n.props?.['data-testid'] === 'author-real-name');

/**
 * 표의 열 하나의 칸 노드를 꺼낸다.
 *
 * @param layout 레이아웃 JSON
 * @param field 열 field
 * @returns 칸 노드 목록
 */
function cellOf(layout: unknown, field: string): Node[] {
    const column = findAll(layout, (n) => n.field === field && Array.isArray(n.cellChildren))[0];
    expect(column, `열 ${field}`).toBeTruthy();

    return column.cellChildren;
}

let utils: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 노드를 반복 변수 하나(행, 댓글 등)를 가진 채로 그린다.
 *
 * @param nodes 그릴 노드
 * @param itemVar 반복 변수 이름 (없으면 데이터소스만 싣는다)
 * @param item 반복 변수 값
 * @param data 데이터소스 값
 * @returns 그린 상자
 */
async function draw(nodes: Node[], itemVar: string | null, item: Record<string, any> | null, data: Record<string, any> = {}): Promise<HTMLElement> {
    const components = itemVar
        ? [{ id: 'probe_row', type: 'basic', name: 'Div', iteration: { source: '{{probeItems}}', item_var: itemVar, index_var: 'i' }, children: nodes }]
        : [{ id: 'probe_row', type: 'basic', name: 'Div', children: nodes }];
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-board-real-name', components } as any,
        {
            componentRegistry: createMockComponentRegistryWithBasics() as any,
            locale: 'ko',
            translations: { 'sirsoft-board': { common: koCommon, admin: { posts: { unknown_author: '알 수 없음', guest: '비회원' } } } },
            initialData: { probeItems: item ? [item] : [], ...data },
        },
    );
    const { container } = await utils.render();

    return container;
}

const line = (container: HTMLElement): HTMLElement | null => container.querySelector('[data-testid="author-real-name"]');

describe('게시글 관리 목록 — 작성자 칸의 실명 보조 줄 (#490 P17)', () => {
    const cell = cellOf(postsIndex, 'author');

    // @scenario case=posts_index_with_real_name
    // @effects real_name_line_drawn_when_key_present
    it('응답에 real_name 이 있으면 표시 이름 아래에 실명 줄이 보이고 「실명: 홍길동」으로 읽힌다', async () => {
        const container = await draw(cell, 'row', { author: { name: '길동이', real_name: '홍길동', uuid: 'u-1', is_guest: false, email: 'hong@example.com' } });

        const found = await waitFor(() => {
            const el = line(container);
            expect(el).toBeTruthy();

            return el!;
        });
        expect(found.textContent).toBe('홍길동');
        expect(found.getAttribute('aria-label')).toBe('실명: 홍길동');
        expect(found.className).toContain('board-text-tertiary');
        // 이름 자리는 표시 이름 그대로이고, 실명 줄은 이름 줄 다음에 온다
        const nameLink = container.querySelector('a[href="/admin/users/u-1"]')!;
        expect(nameLink.textContent?.trim()).toBe('길동이');
        expect(nameLink.compareDocumentPosition(found) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    // @scenario case=posts_index_without_real_name
    // @effects real_name_line_absent_when_key_missing
    it('real_name 키가 없으면(권한 없음, 실명과 같음, 비회원) 실명 줄을 그리지 않는다', async () => {
        const container = await draw(cell, 'row', { author: { name: '길동이', uuid: 'u-1', is_guest: false, email: 'hong@example.com' } });

        // 이름이 그려진 것을 먼저 확정한 뒤 부재를 단언한다
        await waitFor(() => expect(container.querySelector('a[href="/admin/users/u-1"]')?.textContent?.trim()).toBe('길동이'));
        expect(line(container)).toBeNull();
        utils!.cleanup();
        document.body.innerHTML = '';

        const guest = await draw(cell, 'row', { author: { name: '지나가던 사람', uuid: null, is_guest: true, real_name: null } });
        await waitFor(() => expect(guest.textContent).toContain('지나가던 사람'));
        expect(line(guest)).toBeNull();
    });

    // @scenario case=posts_index_with_real_name
    // @effects name_expression_unchanged
    it('이름을 그리는 식은 그대로 row.author.name 이다', () => {
        const json = JSON.stringify(cell);
        expect(json).toContain('"text":"{{row.author.name}}"');
        expect(json).toContain("{{row.author.name ?? '$t:sirsoft-board.admin.posts.unknown_author'}}");
        expect(realNameNodes(cell)).toHaveLength(1);
        // 표 칸 안이라 번역 인자가 행 값을 받도록 $t() 호출식을 쓴다($t:키|인자 는 칸 문맥 없이 먼저 번역된다)
        expect(realNameNodes(cell)[0].props['aria-label']).toBe("{{$t('sirsoft-board.common.real_name_label', { name: row.author.real_name })}}");
    });
});

/** 화면별 실명 줄 — [이름, 노드를 찾을 뿌리, 반복 변수, 실명이 있는 값, 실명이 없는 값, 데이터소스 만들기, 기대 글자] */
const SPOTS: Array<{
    name: string;
    root: () => unknown;
    count: number;
    itemVar: string | null;
    pick?: (nodes: Node[]) => Node;
    withName: Record<string, any>;
    withoutName: Record<string, any>;
    asData?: boolean;
    text: string;
}> = [
    { name: '스크랩 관리의 회원 칸', root: () => cellOf(scrapsIndex, 'member'), count: 1, itemVar: 'row', withName: { member: { name: '길동이', real_name: '홍길동' } }, withoutName: { member: { name: '길동이' } }, text: '홍길동' },
    { name: '스크랩 관리의 작성자 칸', root: () => cellOf(scrapsIndex, 'author_name'), count: 1, itemVar: 'row', withName: { author_name: '길동이', author_real_name: '홍길동' }, withoutName: { author_name: '길동이' }, text: '홍길동' },
    { name: '반응 관리의 회원 칸', root: () => cellOf(reactionsIndex, 'member'), count: 1, itemVar: 'row', withName: { member: { name: '길동이', real_name: '홍길동' } }, withoutName: { member: { name: '길동이' } }, text: '홍길동' },
    { name: '신고 목록의 작성자 칸', root: () => cellOf(reportsIndex, 'snapshot_author_name'), count: 1, itemVar: 'row', withName: { author: { name: '길동이', real_name: '홍길동' } }, withoutName: { author: { name: '길동이' } }, text: '홍길동' },
    { name: '신고 목록의 신고자 칸', root: () => cellOf(reportsIndex, 'reporter_id'), count: 1, itemVar: 'row', withName: { reporter: { name: '길동이', real_name: '홍길동' } }, withoutName: { reporter: { name: '길동이' } }, text: '홍길동' },
    { name: '글 상세의 작성자', root: () => postCard, count: 1, itemVar: null, asData: true, withName: { post: { data: { author: { name: '길동이', real_name: '홍길동' } } } }, withoutName: { post: { data: { author: { name: '길동이' } } } }, text: '실명: 홍길동' },
    { name: '글 상세의 답글 작성자', root: () => replyCard, count: 1, itemVar: 'item', withName: { id: 3, author: { name: '길동이', real_name: '홍길동' } }, withoutName: { id: 3, author: { name: '길동이' } }, text: '실명: 홍길동' },
    { name: '글 상세의 댓글 작성자', root: () => commentCard, count: 1, itemVar: 'comment', withName: { id: 5, author: { name: '길동이', real_name: '홍길동' } }, withoutName: { id: 5, author: { name: '길동이' } }, text: '실명: 홍길동' },
    {
        name: '신고 상세의 작성자(글)', root: () => reportInfo, count: 2, pick: (nodes) => nodes.find((n) => String(n.if).includes('report_detail'))!, itemVar: null, asData: true,
        withName: { report_detail: { data: { target_type: 'post', post: { author: { name: '길동이', real_name: '홍길동' } } } } },
        withoutName: { report_detail: { data: { target_type: 'post', post: { author: { name: '길동이' } }, comment: { author: { name: '다른 이', real_name: '다른 실명' } } } } },
        text: '홍길동',
    },
    {
        name: '신고 상세의 작성자(댓글)', root: () => reportInfo, count: 2, pick: (nodes) => nodes.find((n) => String(n.if).includes('report_detail'))!, itemVar: null, asData: true,
        withName: { report_detail: { data: { target_type: 'comment', comment: { author: { name: '길동이', real_name: '홍길동' } } } } },
        withoutName: { report_detail: { data: { target_type: 'comment', comment: { author: { name: '길동이' } } } } },
        text: '홍길동',
    },
    { name: '신고 상세의 신고자', root: () => reportInfo, count: 2, pick: (nodes) => nodes.find((n) => String(n.if).includes('reporter.reporter'))!, itemVar: 'reporter', withName: { reporter: { name: '길동이', real_name: '홍길동' } }, withoutName: { reporter: { name: '길동이' } }, text: '홍길동' },
    { name: '대시보드 최신글', root: () => dashboard, count: 2, pick: (nodes) => nodes.find((n) => String(n.if).includes('post?.real_name'))!, itemVar: 'post', withName: { author_name: '길동이', real_name: '홍길동' }, withoutName: { author_name: '길동이' }, text: '실명: 홍길동' },
    { name: '대시보드 미처리 신고', root: () => dashboard, count: 2, pick: (nodes) => nodes.find((n) => String(n.if).includes('report?.real_name'))!, itemVar: 'report', withName: { author_name: '길동이', real_name: '홍길동' }, withoutName: { author_name: '길동이' }, text: '실명: 홍길동' },
];

describe('관리자 화면 전반 — 실명 보조 줄 (#490 P17)', () => {
    for (const spot of SPOTS) {
        // @scenario case=other_screens_real_name
        // @effects real_name_line_drawn_when_key_present, real_name_line_absent_when_key_missing
        it(`${spot.name}: 실명 키가 있을 때만 실명 줄이 보이고 「실명: 홍길동」으로 읽힌다`, async () => {
            const nodes = realNameNodes(spot.root());
            expect(nodes).toHaveLength(spot.count);
            const node = spot.pick ? spot.pick(nodes) : nodes[0];
            expect(node, '실명 줄 노드').toBeTruthy();
            expect(typeof node.if, '실명 키가 있을 때만 그리는 조건').toBe('string');
            // 부재를 가릴 대조용 글자를 함께 그린다
            const probe = { id: 'probe_marker', type: 'basic', name: 'Span', text: '그려짐' };

            const present = spot.asData
                ? await draw([probe, node], null, null, spot.withName)
                : await draw([probe, node], spot.itemVar, spot.withName);
            const found = await waitFor(() => {
                const el = line(present);
                expect(el).toBeTruthy();

                return el!;
            });
            expect(found.textContent).toBe(spot.text);
            expect(found.getAttribute('aria-label')).toBe('실명: 홍길동');
            utils!.cleanup();
            document.body.innerHTML = '';

            const absent = spot.asData
                ? await draw([probe, node], null, null, spot.withoutName)
                : await draw([probe, node], spot.itemVar, spot.withoutName);
            await waitFor(() => expect(absent.textContent).toContain('그려짐'));
            expect(line(absent)).toBeNull();
        });
    }

    // @scenario case=other_screens_real_name
    // @effects name_expression_unchanged
    it('이름을 그리는 기존 식은 화면마다 그대로다', () => {
        expect(JSON.stringify(cellOf(scrapsIndex, 'member'))).toContain('"text":"{{row.member?.name}}"');
        expect(JSON.stringify(cellOf(scrapsIndex, 'author_name'))).toContain("\"text\":\"{{row.author_name ?? ''}}\"");
        expect(JSON.stringify(cellOf(reactionsIndex, 'member'))).toContain('"text":"{{row.member?.name}}"');
        expect(JSON.stringify(cellOf(reportsIndex, 'snapshot_author_name'))).toContain("{{row.author?.name ?? '$t:sirsoft-board.admin.posts.unknown_author'}}");
        expect(JSON.stringify(cellOf(reportsIndex, 'reporter_id'))).toContain('"text":"{{row.reporter?.name}}"');
        expect(JSON.stringify(postCard)).toContain('"triggerLabel":"{{post?.data?.author?.name}}"');
        expect(JSON.stringify(replyCard)).toContain('"triggerLabel":"{{item.author?.name}}"');
        expect(JSON.stringify(commentCard)).toContain('"triggerLabel":"{{comment?.author?.name}}"');
        expect(JSON.stringify(reportInfo)).toContain("\"triggerLabel\":\"{{reporter.reporter?.name ?? '-'}}\"");
        expect(JSON.stringify(dashboard)).toContain("(post?.author_name ?? '')");
        expect(JSON.stringify(dashboard)).toContain("(report?.author_name ?? $t('sirsoft-board.admin.dashboard.community.anonymous'))");
    });

    // @scenario case=real_name_i18n
    // @effects real_name_label_exists_in_ko_and_en
    it('실명 문구 키가 ko 와 en 에 있고 이름 자리표시를 갖는다', () => {
        expect((koCommon as any).real_name_label).toBe('실명: {{name}}');
        expect((enCommon as any).real_name_label).toContain('{{name}}');
        // 표 칸 안의 실명 줄은 모두 $t() 호출식으로 이름을 넘긴다
        for (const layout of [postsIndex, scrapsIndex, reactionsIndex, reportsIndex]) {
            for (const node of realNameNodes(layout)) {
                expect(node.props['aria-label']).toMatch(/^\{\{\$t\('sirsoft-board\.common\.real_name_label', \{ name: row\.[a-z_?.]+ \}\)\}\}$/);
            }
        }
    });
});
