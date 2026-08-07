/**
 * @file board-scrap.test.tsx
 * @description 게시글 스크랩(북마크) 기능 레이아웃 구조 검증 (이슈 #524)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 대상:
 * - partials/board/types/basic/show.json — 상세 스크랩 버튼(토글·비로그인·수치)
 * - mypage/scrap.json — 스크랩 탭 데이터소스/구조
 * - partials/mypage/scrap/_list.json — 카드 반복·빈 상태·삭제된 원글·✕ 모달 연결
 * - partials/mypage/scrap/_modal_scrap_delete.json — 해제 확인 모달(DELETE)
 * - partials/mypage/_tab_navigation.json — 스크랩 탭 추가
 *
 * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @scenario action=toggle_remove,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=soft_deleted,post_scope=same_board
 * @scenario action=mypage_search,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @effects detail_button_reflects_is_scraped,detail_shows_scrap_count,guest_toggle_rejected_401,mypage_list_includes_deleted_post,mypage_delete_requires_ownership,mypage_list_deleted_post_exposes_board_and_author,mypage_list_notice_banner_shown,mypage_list_search_filters_by_title
 */

import { describe, it, expect } from 'vitest';

import basicShow from '../../layouts/partials/board/types/basic/show.json';
import scrapPage from '../../layouts/mypage/scrap.json';
import scrapList from '../../layouts/partials/mypage/scrap/_list.json';
import scrapDeleteModal from '../../layouts/partials/mypage/scrap/_modal_scrap_delete.json';
import tabNavigation from '../../layouts/partials/mypage/_tab_navigation.json';
import boardNavigation from '../../layouts/partials/board/show/_navigation.json';

type Node = Record<string, unknown> & { children?: unknown; actions?: unknown };

function collectNodes(node: unknown, predicate: (n: Node) => boolean): Node[] {
    const result: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (predicate(obj)) {
                result.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return result;
}

function findByComment(root: unknown, commentText: string): Node[] {
    return collectNodes(
        root,
        (n) => typeof n.comment === 'string' && (n.comment as string).includes(commentText),
    );
}

/** 노드 트리를 문자열로 직렬화해 특정 토큰 포함 여부를 검사한다. */
function serialized(node: unknown): string {
    return JSON.stringify(node);
}

describe('이슈 #524 — 게시글 상세 스크랩 버튼 (show.json)', () => {
    const scrapButtons = findByComment(basicShow, '스크랩 버튼');

    it('스크랩 버튼이 존재하고 use_scrap 활성 게시판에서만 노출된다', () => {
        expect(scrapButtons.length).toBeGreaterThan(0);
        const btn = scrapButtons[0];
        expect(String(btn.if)).toContain('board?.use_scrap');
    });

    it('버튼 표시가 is_scraped 상태를 반영한다 (아이콘 스타일 + 텍스트 전환)', () => {
        const btn = scrapButtons[0];
        const str = serialized(btn);
        // 아이콘 스타일이 is_scraped 에 따라 solid/regular 전환
        expect(str).toContain('is_scraped');
        expect(str).toContain('solid');
        expect(str).toContain('regular');
        // 텍스트가 scrapped/scrap 전환
        expect(str).toContain('board.post.scrapped');
        expect(str).toContain('board.post.scrap');
    });

    it('스크랩 수(scrap_count)를 버튼에 노출한다', () => {
        const btn = scrapButtons[0];
        expect(serialized(btn)).toContain('scrap_count');
    });

    it('비회원은 로그인 유도(redirectToLoginWithReturn), 회원은 toggle 엔드포인트 호출', () => {
        const btn = scrapButtons[0];
        const str = serialized(btn);
        // 비로그인 분기: 로그인 복귀 핸들러
        expect(str).toContain('redirectToLoginWithReturn');
        // 회원 분기: 토글 엔드포인트 + auth_mode required (401 게이트)
        expect(str).toContain('/scrap/toggle');
        expect(str).toContain('auth_mode');
    });

    it('수치는 서버 응답값으로 확정한다 (refetch 없이 오차 0 — 반응 지연 방지)', () => {
        const btn = scrapButtons[0];
        const str = serialized(btn);
        // 전체 재조회는 느리므로 쓰지 않는다.
        expect(str).not.toContain('refetchDataSource');
        // 응답의 scrap_count 로 _local.scrapCount 를 갱신하고, 화면은 그 값을 우선 참조한다.
        expect(str).toContain('response.data.scrap_count');
        expect(str).toContain('scrapCount');
        expect(str).toContain('_local.scrapCount ?? post?.data?.scrap_count');
    });

    it('로딩 중에는 아이콘만 스피너로 전환한다 (name=spinner + className animate-spin, 검증된 방식)', () => {
        const btn = scrapButtons[0];
        const str = serialized(btn);
        // 로딩 상태에 따라 아이콘 name 이 spinner/bookmark 로 분기된다.
        expect(str).toContain('scrapLoading');
        expect(str).toContain("? 'spinner' : 'bookmark'");
        // 회전은 같은 파일 비밀번호 확인 스피너와 동일한 animate-spin(Tailwind) 방식.
        expect(str).toContain('animate-spin');
        // name 에 FA 클래스 문자열 직접 주입 금지 (Icon 이 fa- 접두사를 재부착해 깨진다).
        expect(str).not.toContain('fa-spinner fa-spin');
    });

    it('로딩 중 iconStyle 은 solid 로 고정한다 (spinner 는 Free 에서 solid 전용 — regular 시 글리프 없어 깨짐)', () => {
        // iconStyle 삼항은 scrapLoading 을 먼저 분기해 로딩 중엔 무조건 solid 여야 한다.
        const btn = scrapButtons[0];
        const iconStyleExpr = collectNodes(
            btn,
            (n) =>
                (n as any).name === 'Icon' &&
                typeof (n as any).props === 'object' &&
                typeof ((n as any).props as any).iconStyle === 'string',
        ).map((n) => ((n as any).props as any).iconStyle as string)[0];
        expect(iconStyleExpr).toBeTruthy();
        // scrapLoading 이 iconStyle 분기의 첫 조건이어야 로딩 중 solid 가 보장된다.
        expect(iconStyleExpr).toContain('scrapLoading');
        expect(iconStyleExpr).toContain("? 'solid' :");
    });
});

describe('이슈 #524 — 마이페이지 스크랩 탭 (scrap.json)', () => {
    it('scrap 데이터소스가 me/scraps 엔드포인트를 auth_required 로 조회한다', () => {
        const ds = (scrapPage as any).data_sources?.find((d: any) => d.id === 'scrap');
        expect(ds).toBeTruthy();
        expect(ds.endpoint).toContain('/api/modules/sirsoft-board/me/scraps');
        expect(ds.auth_required).toBe(true);
    });

    it('scrap 데이터소스 엔드포인트는 _local.scrapSearch 를 search 쿼리 파라미터로 전달한다', () => {
        const ds = (scrapPage as any).data_sources?.find((d: any) => d.id === 'scrap');
        expect(ds.endpoint).toContain('search={{_local.scrapSearch');
    });

    it('init_actions 가 scrapSearch 를 빈 문자열로 초기화한다 (레이아웃 재진입 시 이전 검색어 잔존 방지)', () => {
        const initActions = (scrapPage as any).init_actions;
        expect(Array.isArray(initActions)).toBe(true);
        const setStateAction = initActions.find((a: any) => a.handler === 'setState');
        expect(setStateAction.params.scrapSearch).toBe('');
        expect(setStateAction.params.target).toBe('local');
    });

    it('탭 컨텐츠에 목록 partial 과 해제 확인 모달 partial 을 포함한다', () => {
        const str = serialized(scrapPage);
        expect(str).toContain('partials/mypage/scrap/_list.json');
        expect(str).toContain('partials/mypage/scrap/_modal_scrap_delete.json');
    });

    it('회귀(#524): 모달 partial 은 최상위 modals 배열에 있어야 렌더된다 (content slot 배치 금지)', () => {
        // content slot 에 두면 모달이 렌더되지 않는다. modals 최상위 배열이 정위치.
        const modals = (scrapPage as any).modals;
        expect(Array.isArray(modals)).toBe(true);
        expect(serialized(modals)).toContain('partials/mypage/scrap/_modal_scrap_delete.json');
        // content slot 안에는 모달 partial 이 없어야 한다.
        const contentSlot = (scrapPage as any).slots?.content;
        expect(serialized(contentSlot)).not.toContain('_modal_scrap_delete.json');
    });
});

describe('이슈 #524 — 스크랩 목록 표/카드 (_list.json)', () => {
    it('PC 표와 모바일 카드 모두 scrap.data.data 배열을 반복 렌더링한다', () => {
        const iterated = collectNodes(scrapList, (n) => Boolean(n.iteration));
        // Tbody(PC) + 모바일 카드 컨테이너, 최소 2곳
        expect(iterated.length).toBeGreaterThanOrEqual(2);
        iterated.forEach((n) => {
            expect(serialized(n.iteration)).toContain('scrap.data.data');
        });
    });

    it('회귀: scrapped_at 표시는 프론트 파이프가 아니라 백엔드(FormatsBoardDate)가 포맷한 값을 그대로 쓴다', () => {
        // relativeTime 파이프는 로케일을 항상 'ko' 로 고정해 en/ja 사이트에서도 한국어가 노출되는 결함이 있었다.
        // 게시판 게시글 목록·상세와 동일하게 PostScrapResource 가 서버에서 포맷·다국어를 처리하도록 되돌렸다 —
        // 프론트에서 relativeTime 파이프를 다시 붙이지 말 것.
        const str = serialized(scrapList);
        expect(str).not.toContain('relativeTime');
    });

    it('회귀: 상시 안내 배너는 제거되어 검색 영역이 헤더 바로 아래에 온다 (PO 지시로 배너 제거)', () => {
        const str = serialized(scrapList);
        expect(str).not.toContain('mypage.scrap.notice"');
        expect(str).not.toContain('mypage.scrap.notice_sub');
        expect(str).not.toContain('circle-info');
    });

    it('게시글 제목 검색 입력란이 존재하며 _local.scrapSearch 에 바인딩된다', () => {
        const searchInput = collectNodes(scrapList, (n) => (n as any).name === 'Input' && (n as any).props?.placeholder === '$t:mypage.scrap.search_placeholder')[0];
        expect(searchInput).toBeDefined();
        expect((searchInput as any).props.value).toBe("{{_local.scrapSearch ?? ''}}");
        const actions = (searchInput as any).actions as Array<Record<string, unknown>>;
        expect(actions[0].handler).toBe('setState');
        expect(serialized(actions[0])).toContain('scrapSearch');
    });

    function findSearchAreaButton(labelKey: string): Node {
        const searchArea = findByComment(scrapList, '검색 영역')[0];
        const btn = collectNodes(
            searchArea,
            (n) => (n as any).name === 'Button' && serialized((n as any).children).includes(labelKey),
        )[0];
        expect(btn).toBeDefined();
        return btn;
    }

    it('검색 버튼은 페이지를 1로 되돌리고 scrap 데이터소스를 재조회한다 (my_posts 탭과 동일 패턴)', () => {
        const searchBtn = findSearchAreaButton('$t:common.search');
        const str = serialized((searchBtn as any).actions);
        expect(str).toContain('scrapPage');
        expect(str).toContain('refetchDataSource');
        expect(str).toContain('"dataSourceId":"scrap"');
    });

    it('초기화 버튼은 검색어를 비우고 페이지를 1로 되돌린 뒤 재조회한다', () => {
        const resetBtn = findSearchAreaButton('$t:common.reset');
        const str = serialized((resetBtn as any).actions);
        expect(str).toContain('"scrapSearch":""');
        expect(str).toContain('scrapPage');
        expect(str).toContain('refetchDataSource');
    });

    it('회귀: 검색/초기화 버튼은 각자 독립된 로딩 상태(scrapSearching/scrapResetting)를 켜고, refetch 완료 후에야 끈다 (sequence 순서: on → refetch → off)', () => {
        const cases = [
            { labelKey: '$t:common.search', stateKey: 'scrapSearching' },
            { labelKey: '$t:common.reset', stateKey: 'scrapResetting' },
        ];
        for (const { labelKey, stateKey } of cases) {
            const btn = findSearchAreaButton(labelKey);
            const actions = (btn as any).actions[0].actions as Array<Record<string, unknown>>;
            expect(actions.length).toBe(3);
            expect(serialized(actions[0])).toContain(`"${stateKey}":true`);
            expect(actions[1].handler).toBe('refetchDataSource');
            expect(serialized(actions[2])).toContain(`"${stateKey}":false`);
        }
    });

    it('회귀: 검색 버튼을 눌러도 초기화 버튼 스피너는 돌지 않는다 (독립 상태 — 반대도 동일)', () => {
        const searchBtn = findSearchAreaButton('$t:common.search');
        const resetBtn = findSearchAreaButton('$t:common.reset');
        // 검색 버튼의 스피너/처리중 텍스트는 scrapSearching 만 본다 (scrapResetting 무관)
        const searchSpinner = ((searchBtn as any).children as any[]).find((c) => c.name === 'Icon' && c.props?.name === 'spinner');
        expect(searchSpinner.if).toBe('{{_local.scrapSearching}}');
        expect(searchSpinner.if).not.toContain('scrapResetting');
        // 초기화 버튼의 스피너/처리중 텍스트는 scrapResetting 만 본다 (scrapSearching 무관)
        const resetSpinner = ((resetBtn as any).children as any[]).find((c) => c.name === 'Icon' && c.props?.name === 'spinner');
        expect(resetSpinner.if).toBe('{{_local.scrapResetting}}');
        expect(resetSpinner.if).not.toContain('scrapSearching');
    });

    it('회귀: 검색/초기화 버튼은 로딩 중 disabled 이며(서로의 진행 상태도 함께 잠금), 스피너+"처리 중" 텍스트로 전환된다', () => {
        const cases = [
            { labelKey: '$t:common.search', stateKey: 'scrapSearching' },
            { labelKey: '$t:common.reset', stateKey: 'scrapResetting' },
        ];
        for (const { labelKey, stateKey } of cases) {
            const btn = findSearchAreaButton(labelKey);
            expect((btn as any).props.disabled).toContain('scrapSearching');
            expect((btn as any).props.disabled).toContain('scrapResetting');
            const spinner = ((btn as any).children as any[]).find((c) => c.name === 'Icon' && c.props?.name === 'spinner');
            expect(spinner).toBeDefined();
            expect(spinner.if).toBe(`{{_local.${stateKey}}}`);
            const idleLabel = ((btn as any).children as any[]).find((c) => c.text === labelKey);
            expect(idleLabel.if).toBe(`{{!_local.${stateKey}}}`);
            const processingLabel = ((btn as any).children as any[]).find((c) => c.text === '$t:common.processing');
            expect(processingLabel.if).toBe(`{{_local.${stateKey}}}`);
        }
    });

    it('회귀: 페이지네이션은 마이페이지 문의내역 탭과 동일하게 if 조건 없이 항상 노출된다 (1페이지뿐이어도 표시)', () => {
        const pagination = collectNodes(scrapList, (n) => (n as any).name === 'Pagination');
        expect(pagination.length).toBe(1);
        expect(pagination[0].if).toBeUndefined();
    });

    it('빈 상태 안내가 항상 노출 탭 규약에 따라 존재한다', () => {
        const empty = findByComment(scrapList, '스크랩 목록 없음');
        expect(empty.length).toBeGreaterThan(0);
        expect(serialized(empty[0])).toContain('mypage.scrap.empty');
    });

    it('회귀: 빈 상태는 마이페이지 마일리지 탭과 동일하게 카드 박스(테두리+배경)로 감싸진다 (배경에 텍스트만 떠 있지 않도록)', () => {
        const empty = findByComment(scrapList, '스크랩 목록 없음')[0];
        const className = String((empty as any).props?.className ?? '');
        expect(className).toContain('bg-white');
        expect(className).toContain('dark:bg-gray-800');
        expect(className).toContain('border');
        expect(className).toMatch(/\brounded/);
    });

    describe('PC 표 (Table/Tbody/Tr)', () => {
        it('표는 모바일에서 숨겨지고(hidden) 그 반대(모바일 카드)는 PC에서 숨겨진다 (반응형 상호배타)', () => {
            const tableWrap = findByComment(scrapList, '스크랩 목록 표 (PC)');
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            expect(tableWrap.length).toBe(1);
            expect(cardWrap.length).toBe(1);
            expect((tableWrap[0].responsive as any)?.portable?.props?.className).toContain('hidden');
            expect((cardWrap[0].props as any)?.className).toContain('hidden');
            expect((cardWrap[0].responsive as any)?.portable?.props?.className).not.toContain('hidden');
        });

        it('살아있는 원글 행은 게시판·제목·작성자·댓글수·스크랩일 컬럼을 노출한다', () => {
            const aliveRows = findByComment(scrapList, '살아있는 원글 행');
            expect(aliveRows.length).toBeGreaterThan(0);
            const row = aliveRows[0];
            expect(String(row.if)).toContain('!item.is_deleted');
            const str = serialized(row);
            expect(str).toContain('item.board_name');
            expect(str).toContain('item.title');
            expect(str).toContain('item.author_name');
            expect(str).toContain('item.comments_count');
            expect(str).toContain('item.scrapped_at');
        });

        it('회귀: 살아있는 행 자체(Tr)에는 navigate 액션이 없다 (해제 버튼과의 이벤트 버블링 충돌 방지 — 게시판명·제목에 개별 클릭으로 대체)', () => {
            const aliveRows = findByComment(scrapList, '살아있는 원글 행');
            const row = aliveRows[0];
            expect(row.actions).toBeUndefined();
        });

        it('회귀: 게시판명은 클릭 시 게시판 목록(/board/{slug})으로, 제목은 클릭 시 원글 상세(/board/{slug}/{id})로 각각 독립 이동한다', () => {
            const aliveRow = findByComment(scrapList, '살아있는 원글 행')[0];
            const tds = collectNodes(aliveRow, (n) => (n as any).name === 'Td');
            const boardTd = tds[0];
            const titleTd = tds[1];

            const boardSpan = collectNodes(boardTd, (n) => (n as any).name === 'Span' && Array.isArray(n.actions))[0];
            expect(boardSpan).toBeDefined();
            const boardAction = (boardSpan as any).actions[0];
            expect(boardAction.handler).toBe('navigate');
            expect(boardAction.params.path).toContain('item.board_slug');
            expect(boardAction.params.path).not.toContain('item.post_id');

            const titleSpan = collectNodes(titleTd, (n) => (n as any).name === 'Span' && Array.isArray(n.actions))[0];
            expect(titleSpan).toBeDefined();
            const titleAction = (titleSpan as any).actions[0];
            expect(titleAction.handler).toBe('navigate');
            expect(titleAction.params.path).toContain('item.board_slug');
            expect(titleAction.params.path).toContain('item.post_id');
        });

        it('삭제된 원글 행은 톤다운되고 "삭제된 게시글" + "삭제됨" 을 표시하되, 게시판명·작성자는 유지한다 (is_deleted) — 클릭 이동 없음', () => {
            const deletedRows = findByComment(scrapList, '삭제된 원글 행');
            expect(deletedRows.length).toBeGreaterThan(0);
            const row = deletedRows[0];
            expect(String(row.if)).toContain('item.is_deleted');
            const str = serialized(row);
            expect(str).toContain('mypage.scrap.deleted_post');
            expect(str).toContain('mypage.scrap.deleted_flag');
            // 회귀 방지: "어떤 글이 삭제됐는지" 맥락 유지를 위해 게시판명·작성자는 계속 노출한다.
            expect(str).toContain('item.board_name');
            expect(str).toContain('item.author_name');
            // 삭제글 행은 클릭해도 원글로 이동하지 않는다 (navigate 액션 없음).
            expect(row.actions).toBeUndefined();
        });

        it('회귀: 표 셀 여백은 마이페이지 마일리지 탭과 동일한 px-4 py-3 이다 (PO 지시 — 지나치게 빡빡했던 px-2 py-2 에서 마일리지 탭 기준으로 복원)', () => {
            const str = serialized(scrapList);
            expect(str).not.toContain('px-4 py-4');
            expect(str).not.toContain('px-2 py-2');
            const headerRow = collectNodes(scrapList, (n) => (n as any).name === 'Tr' && !n.if && !n.actions).find((tr) =>
                serialized(tr).includes('mypage.scrap.table.board'),
            );
            const ths = collectNodes(headerRow, (n) => (n as any).name === 'Th');
            ths.forEach((th) => expect((th as any).props.className).toContain('px-4 py-3'));
            for (const rowComment of ['살아있는 원글 행', '삭제된 원글 행']) {
                const row = findByComment(scrapList, rowComment)[0];
                const tds = collectNodes(row, (n) => (n as any).name === 'Td');
                tds.forEach((td) => expect((td as any).props.className).toContain('px-4 py-3'));
            }
        });

        it('표 헤더는 컬럼 라벨 다국어 키를 사용한다', () => {
            const str = serialized(scrapList);
            expect(str).toContain('mypage.scrap.table.board');
            expect(str).toContain('mypage.scrap.table.title');
            expect(str).toContain('mypage.scrap.table.author');
            expect(str).toContain('mypage.scrap.table.comments');
            expect(str).toContain('mypage.scrap.table.scrapped_at');
        });

        it('회귀: 게시판·제목 컬럼은 왼쪽 정렬이고 나머지(작성자/댓글/스크랩일/관리) 헤더는 가운데 정렬이다 (PO 지시로 게시판 컬럼을 가운데→왼쪽 정렬로 변경)', () => {
            const headerRow = collectNodes(scrapList, (n) => (n as any).name === 'Tr' && !n.if && !n.actions).find((tr) =>
                serialized(tr).includes('mypage.scrap.table.board'),
            );
            const ths = collectNodes(headerRow, (n) => (n as any).name === 'Th');
            expect(ths.length).toBe(6);
            const [board, title, author, comments, scrappedAt, manage] = ths as any[];
            for (const th of [board, title]) {
                expect(th.props.className).toContain('text-left');
                expect(th.props.className).not.toContain('text-center');
            }
            for (const th of [author, comments, scrappedAt, manage]) {
                expect(th.props.className).toContain('text-center');
                expect(th.props.className).not.toContain('text-left');
                expect(th.props.className).not.toContain('text-right');
            }
        });

        it('회귀: 살아있는 행/삭제된 행 모두 게시판·제목 셀은 왼쪽 정렬이고, 나머지 셀은 가운데 정렬이다', () => {
            for (const rowComment of ['살아있는 원글 행', '삭제된 원글 행']) {
                const row = findByComment(scrapList, rowComment)[0];
                const tds = collectNodes(row, (n) => (n as any).name === 'Td');
                expect(tds.length).toBe(6);
                const [board, title, author, comments, scrappedAt, manage] = tds as any[];
                expect(String(board.props?.className ?? '')).toContain('text-left');
                expect(String(board.props?.className ?? '')).not.toContain('text-center');
                expect(String(title.props?.className ?? '')).not.toContain('text-center');
                for (const td of [author, comments, scrappedAt, manage]) {
                    expect(td.props.className).toContain('text-center');
                    expect(td.props.className).not.toContain('text-right');
                }
            }
        });

        it('회귀: 해제 버튼 컬럼도 빈 텍스트가 아니라 "관리" 라벨을 갖는다 (컬럼명 없어 보이는 회귀 방지)', () => {
            const headerRow = collectNodes(scrapList, (n) => (n as any).name === 'Tr' && !n.if && !n.actions).find(
                (tr) => serialized(tr).includes('mypage.scrap.table.board'),
            );
            expect(headerRow).toBeDefined();
            const ths = collectNodes(headerRow, (n) => (n as any).name === 'Th');
            expect(ths.length).toBe(6);
            const lastTh = ths[ths.length - 1];
            expect((lastTh as any).text).toBe('$t:mypage.scrap.table.manage');
            expect((lastTh as any).text).not.toBe('');
        });

        it('회귀: PC 표 해제 버튼은 테두리·배경 등 버튼임을 식별 가능한 스타일을 가진다 (텍스트 링크처럼 보이지 않도록)', () => {
            const tableWrap = findByComment(scrapList, '스크랩 목록 표 (PC)');
            const removeBtns = findByComment(tableWrap[0], '스크랩 해제 버튼');
            removeBtns.forEach((btn) => {
                const className = String((btn as any).props?.className ?? '');
                expect(className).toContain('border');
                expect(className).toMatch(/\brounded/);
                expect(className).toMatch(/\bpx-/);
            });
        });

        it('PC 표의 해제 버튼(살아있는 행 + 삭제된 행 양쪽)은 즉시 삭제가 아니라 확인 모달을 연다', () => {
            const tableWrap = findByComment(scrapList, '스크랩 목록 표 (PC)');
            const removeBtns = findByComment(tableWrap[0], '스크랩 해제 버튼');
            expect(removeBtns.length).toBe(2);
            removeBtns.forEach((btn) => {
                const str = serialized(btn);
                expect(str).toContain('openModal');
                expect(str).toContain('scrap_delete_modal');
                expect(str).not.toContain('"method":"DELETE"');
                const actions = (btn.actions as Array<Record<string, unknown>>) ?? [];
                expect(actions.length).toBeGreaterThan(0);
                actions.forEach((action) => {
                    expect(Boolean(action.type || action.event)).toBe(true);
                });
                const sequenceAction = actions.find((a) => a.handler === 'sequence');
                expect(sequenceAction).toBeDefined();
            });
        });

        it('회귀: 살아있는 행의 해제 버튼은 stopPropagation 없이도 안전하다 (행 자체에 navigate 가 없으므로 이벤트 버블링 충돌 자체가 발생하지 않음 — stopPropagation 핸들러는 엔진 미구현이라 실질 no-op)', () => {
            const aliveRow = findByComment(scrapList, '살아있는 원글 행')[0];
            const removeBtn = findByComment(aliveRow, '스크랩 해제 버튼')[0];
            const actions = (removeBtn.actions as Array<Record<string, unknown>>) ?? [];
            expect(actions.some((a) => a.handler === 'stopPropagation')).toBe(false);
            expect(actions[0].handler).toBe('sequence');
        });

        it('회귀: PC 표의 해제 버튼은 아이콘이 아니라 텍스트 버튼("해제")이다 (별도 컬럼)', () => {
            const tableWrap = findByComment(scrapList, '스크랩 목록 표 (PC)');
            const removeBtns = findByComment(tableWrap[0], '스크랩 해제 버튼');
            removeBtns.forEach((btn) => {
                expect((btn as any).text).toBe('$t:mypage.scrap.table.remove');
                expect(serialized(btn)).not.toContain('"name":"Icon"');
            });
        });
    });

    describe('모바일 카드 (반응형 폴백)', () => {
        it('살아있는 원글 카드는 게시판 뱃지+제목·작성자·댓글수·스크랩일을 노출한다', () => {
            const aliveCards = findByComment(scrapList, '살아있는 원글 카드');
            expect(aliveCards.length).toBeGreaterThan(0);
            const str = serialized(aliveCards[0]);
            expect(str).toContain('item.title');
            expect(str).toContain('item.board_name');
            expect(str).toContain('item.author_name');
            expect(str).toContain('mypage.scrap.comments');
            expect(str).toContain('item.scrapped_at');
        });

        it('회귀: 게시판 뱃지와 제목이 같은 줄(Div)에 배치된다 (별도 줄로 분리 시 세로 여백 회귀)', () => {
            const aliveCards = findByComment(scrapList, '살아있는 원글 카드');
            const titleRow = collectNodes(
                aliveCards[0],
                (n) => (n as any).name === 'Div' && serialized(n).includes('item.board_name') && serialized(n).includes('item.title'),
            );
            expect(titleRow.length).toBeGreaterThan(0);
        });

        it('삭제된 원글 카드는 톤다운되고 "삭제된 게시글" 을 표시하되, 게시판명·작성자·"삭제됨" 표시는 유지한다 (is_deleted)', () => {
            const deletedCards = findByComment(scrapList, '삭제된 원글 카드');
            expect(deletedCards.length).toBeGreaterThan(0);
            const card = deletedCards[0];
            expect(String(card.if)).toContain('item.is_deleted');
            const str = serialized(card);
            expect(str).toContain('mypage.scrap.deleted_post');
            expect(str).toContain('item.board_name');
            expect(str).toContain('item.author_name');
            expect(str).toContain('mypage.scrap.deleted_flag');
            expect(card.actions).toBeUndefined();
        });

        it('모바일 카드의 ✕ 버튼은 즉시 삭제가 아니라 확인 모달을 연다 (openModal scrap_delete_modal)', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeBtns = findByComment(cardWrap[0], '스크랩 해제 버튼');
            expect(removeBtns.length).toBeGreaterThan(0);
            const str = serialized(removeBtns[0]);
            expect(str).toContain('openModal');
            expect(str).toContain('scrap_delete_modal');
            expect(str).not.toContain('"method":"DELETE"');
        });

        it('회귀(#524): 모바일 해제 버튼의 최상위 트리거 액션은 type 을 가져야 한다 (setState+openModal 을 sequence 로 묶음)', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeWrap = findByComment(cardWrap[0], '스크랩 해제 버튼');
            // comment 는 버튼을 감싸는 Div(정렬용)에 있으므로, actions 를 가진 실제 Button 노드까지 내려간다.
            const btn = collectNodes(removeWrap[0], (n) => (n as any).name === 'Button' && Array.isArray(n.actions))[0] as { actions?: Array<Record<string, unknown>> };
            expect(Array.isArray(btn.actions)).toBe(true);
            for (const action of btn.actions!) {
                expect(Boolean(action.type || action.event)).toBe(true);
            }
            expect(serialized(btn.actions![0])).toContain('sequence');
        });

        it('회귀: 모바일 해제 버튼은 텍스트 버튼("해제")이며 카드 하단에 우측 정렬로 배치된다 (아이콘 절대위치 버튼 아님)', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeWrap = findByComment(cardWrap[0], '스크랩 해제 버튼')[0];
            const str = serialized(removeWrap);
            expect(str).toContain('mypage.scrap.table.remove');
            expect(str).not.toContain('absolute');
            expect(str).not.toContain('"name":"Icon"');
        });

        it('회귀: 모바일 해제 버튼도 PC 표와 동일하게 테두리·배경 등 버튼 식별 스타일을 가진다', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeWrap = findByComment(cardWrap[0], '스크랩 해제 버튼')[0];
            const btn = collectNodes(removeWrap, (n) => (n as any).name === 'Button' && Array.isArray(n.actions))[0];
            const className = String((btn as any).props?.className ?? '');
            expect(className).toContain('border');
            expect(className).toMatch(/\brounded/);
            expect(className).toMatch(/\bpx-/);
        });
    });
});

describe('이슈 #524 — 해제 확인 모달 (_modal_scrap_delete.json)', () => {
    it('모달 id 가 목록의 openModal target 과 일치한다 (scrap_delete_modal)', () => {
        expect((scrapDeleteModal as any).id).toBe('scrap_delete_modal');
    });

    it('해제 버튼이 me/scraps/{id} DELETE 후 목록을 갱신한다', () => {
        const str = serialized(scrapDeleteModal);
        expect(str).toContain('/api/modules/sirsoft-board/me/scraps/');
        expect(str).toContain('DELETE');
        expect(str).toContain('_global.scrapDeleteModal');
        expect(str).toContain('refetchDataSource');
    });

    it('원글 삭제 여부에 따라 안내 문구가 분기된다', () => {
        const str = serialized(scrapDeleteModal);
        expect(str).toContain('mypage.scrap.delete_confirm');
        expect(str).toContain('mypage.scrap.delete_confirm_deleted');
        expect(str).toContain('mypage.scrap.delete_notice');
    });

    it('회귀: 원글 보존 안내 문구는 별도 강조 박스 없이 본문 텍스트 바로 아래 작은 회색 글씨로 붙는다 (1안 — PO 지시로 박스형에서 인라인 텍스트로 변경)', () => {
        const bodyWrap = collectNodes(
            scrapDeleteModal,
            (n) => (n as any).name === 'Div' && (n as any).props?.className === 'flex-1 pt-0.5',
        )[0];
        expect(bodyWrap).toBeDefined();
        const children = (bodyWrap as any).children as any[];
        const noticeNode = children.find((c) => c.text === '$t:mypage.scrap.delete_notice');
        expect(noticeNode).toBeDefined();
        expect(noticeNode.name).toBe('P');
        expect(noticeNode.props.className).toContain('text-xs');
        expect(noticeNode.props.className).not.toContain('rounded-lg');
        expect(noticeNode.props.className).not.toContain('bg-gray-100');
        // info-circle 아이콘이 딸린 별도 박스는 더 이상 존재하지 않는다.
        expect(serialized(scrapDeleteModal)).not.toContain('info-circle');
    });
});

describe('이슈 #524 — 마이페이지 탭 네비게이션 (_tab_navigation.json)', () => {
    it('"스크랩" 탭이 "게시판 활동"(board) 바로 다음에 추가된다', () => {
        const tabs = (tabNavigation as any).props?.tabs as Array<{ id: string }>;
        const boardIdx = tabs.findIndex((t) => t.id === 'board');
        const scrapIdx = tabs.findIndex((t) => t.id === 'scrap');
        expect(boardIdx).toBeGreaterThanOrEqual(0);
        expect(scrapIdx).toBe(boardIdx + 1);
    });
});

describe('회귀(#524): 이전글/다음글 이동 시 스크랩 로컬 상태가 형제 상세로 승계되지 않는다 (_navigation.json)', () => {
    // 재현: A 게시글을 스크랩(버튼 클릭 → _local.isScraped=true 세팅) 후 이전글/다음글로
    // 스크랩하지 않은 B 게시글로 이동하면, show.json 의 스크랩 버튼이
    // `{{_local.isScraped ?? post?.data?.is_scraped}}` 로 값을 읽는다. 네비게이션이
    // _local.isScraped 를 비우지 않으면 B 게시글에서도 A 의 true 값이 그대로 남아
    // "스크랩 안 한 글인데 스크랩된 것처럼" 보인다 (새로고침하면 서버값으로 정상화).
    function findNavButton(labelKey: string): Node {
        const btn = collectNodes(
            boardNavigation,
            (n) => (n as any).name === 'Button' && serialized((n as any).children).includes(labelKey),
        )[0];
        expect(btn).toBeDefined();
        return btn;
    }

    it.each([
        ['이전글', '$t:board.prev'],
        ['다음글', '$t:board.next'],
    ])('%s 버튼 클릭 시 setState 로 스크랩 로컬 상태(isScraped/scrapCount/scrapLoading)를 null 로 비운 뒤 navigate 한다', (_label, labelKey) => {
        const btn = findNavButton(labelKey);
        const actions = (btn as any).actions as Array<Record<string, unknown>>;
        expect(actions[0].handler).toBe('sequence');

        const innerActions = actions[0].actions as Array<Record<string, unknown>>;
        expect(innerActions.length).toBe(2);

        // 1) setState 가 navigate 보다 먼저 실행되어야 새 게시글 렌더 전에 로컬 상태가 비워진다.
        const resetAction = innerActions[0];
        expect(resetAction.handler).toBe('setState');
        expect((resetAction.params as any).target).toBe('local');
        expect((resetAction.params as any).isScraped).toBeNull();
        expect((resetAction.params as any).scrapCount).toBeNull();
        expect((resetAction.params as any).scrapLoading).toBeNull();

        // 2) 그 다음 navigate 로 실제 이동한다 (목록 상태 mergeQuery 승계는 기존 동작 유지).
        const navigateAction = innerActions[1];
        expect(navigateAction.handler).toBe('navigate');
        expect((navigateAction.params as any).mergeQuery).toBe(true);
    });

    it('스크랩 버튼(show.json)은 _local.isScraped 를 post?.data?.is_scraped 보다 우선 참조한다 (null 로 비워야 서버값이 다시 채택됨을 보장)', () => {
        const scrapButtons = findByComment(basicShow, '스크랩 버튼');
        const btn = scrapButtons[0];
        const str = serialized(btn);
        expect(str).toContain('_local.isScraped ?? post?.data?.is_scraped');
    });
});
