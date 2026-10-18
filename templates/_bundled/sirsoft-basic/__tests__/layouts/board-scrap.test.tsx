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
import scrapBulkDeleteModal from '../../layouts/partials/mypage/scrap/_modal_scrap_bulk_delete.json';
import tabNavigation from '../../layouts/partials/mypage/_tab_navigation.json';
import boardNavigation from '../../layouts/partials/board/show/_navigation.json';

// 레이아웃 JSON 은 컴파일러가 단일 원소 배열을 객체로 접는다 — 모양 의존 접근 대신 헬퍼로 읽는다(D67 ⓐ).
import { asArray, actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
    it('스크랩 버튼이 존재하고 use_scrap 활성 게시판에서만 노출된다', () => {
        expect(scrapButtons.length).toBeGreaterThan(0);
        const btn = scrapButtons[0];
        expect(String(btn.if)).toContain('board?.use_scrap');
    });

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_shows_scrap_count
    it('스크랩 수(scrap_count)를 버튼에 노출한다', () => {
        const btn = scrapButtons[0];
        expect(serialized(btn)).toContain('scrap_count');
    });

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects guest_toggle_rejected_401
    it('비회원은 로그인 유도(redirectToLoginWithReturn), 회원은 toggle 엔드포인트 호출', () => {
        const btn = scrapButtons[0];
        const str = serialized(btn);
        // 비로그인 분기: 로그인 복귀 핸들러
        expect(str).toContain('redirectToLoginWithReturn');
        // 회원 분기: 토글 엔드포인트 + auth_mode required (401 게이트)
        expect(str).toContain('/scrap/toggle');
        expect(str).toContain('auth_mode');
    });

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
    it('담김 여부를 aria-pressed 로도 알린다 (반응 버튼과 같은 방식, 화면 글자 「스크랩됨」에만 기대지 않는다)', () => {
        // 7.1.0 실측 O-K2-5: 버튼에 aria-pressed 가 없어 담김 상태가 글자로만 전달됐다.
        const btn = scrapButtons[0];
        const props = btn.props as Record<string, unknown>;
        const raw = String(props['aria-pressed'] ?? '');
        expect(raw).toMatch(/^\{\{.*\}\}$/);
        const expr = raw.slice(2, -2);
        // 화면 글자·아이콘과 같은 출처(_local.isScraped 우선, 없으면 서버 is_scraped)를 읽는다.
        expect(expr).toContain('_local.isScraped ?? post?.data?.is_scraped');
        const evaluate = (_local: Record<string, unknown>, isScraped: boolean) =>
            // eslint-disable-next-line no-new-func
            new Function('_local', 'post', `return (${expr});`)(_local, { data: { is_scraped: isScraped } });
        expect(evaluate({}, true)).toBe(true);
        expect(evaluate({}, false)).toBe(false);
        // 방금 누른 값(_local)이 서버 값보다 앞선다.
        expect(evaluate({ isScraped: false }, true)).toBe(false);
        expect(evaluate({ isScraped: true }, false)).toBe(true);
    });

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_button_reflects_is_scraped
    it('회귀: 스크랩 아이콘은 size prop 대신 text-sm 고정 클래스를 쓴다 (fa-sm 상대값 → 부모 text-sm 축소 체인에서 높이 0.88px 로 붕괴)', () => {
        // size="sm" 은 fa-sm(0.875em 상대값)으로 매핑되어 부모 text-sm 과 겹치면 아이콘이 찌그러진다.
        // 부모 버튼 글자 크기(text-sm=14px)에 맞춘 고정 클래스로 렌더해야 아이콘 높이가 보존된다.
        const btn = scrapButtons[0];
        const icon = collectNodes(
            btn,
            (n) => (n as any).name === 'Icon' && typeof (n as any).props === 'object',
        )[0] as any;
        expect(icon).toBeTruthy();
        expect(icon.props.size).toBeUndefined();
        expect(String(icon.props.className)).toContain('text-sm');
    });
});

describe('이슈 #524 — 마이페이지 스크랩 탭 (scrap.json)', () => {
    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_sorted_recent_first
    it('scrap 데이터소스가 me/scraps 엔드포인트를 auth_required 로 조회한다', () => {
        const ds = (scrapPage as any).data_sources?.find((d: any) => d.id === 'scrap');
        expect(ds).toBeTruthy();
        expect(ds.endpoint).toContain('/api/modules/sirsoft-board/me/scraps');
        expect(ds.auth_required).toBe(true);
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_sorted_recent_first
    it('scrap 데이터소스 엔드포인트는 _local.scrapSearch 를 search 쿼리 파라미터로 전달한다', () => {
        const ds = (scrapPage as any).data_sources?.find((d: any) => d.id === 'scrap');
        expect(ds.endpoint).toContain('search={{_local.scrapSearch');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
    it('init_actions 가 scrapSearch 를 빈 문자열로 초기화한다 (레이아웃 재진입 시 이전 검색어 잔존 방지)', () => {
        const initActions = (scrapPage as any).init_actions;
        expect(Array.isArray(initActions)).toBe(true);
        const setStateAction = initActions.find((a: any) => a.handler === 'setState');
        expect(setStateAction.params.scrapSearch).toBe('');
        expect(setStateAction.params.target).toBe('local');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_opens_confirm_modal
    it('탭 컨텐츠에 목록 partial 과 해제 확인 모달 partial 을 포함한다', () => {
        const str = serialized(scrapPage);
        expect(str).toContain('partials/mypage/scrap/_list.json');
        expect(str).toContain('partials/mypage/scrap/_modal_scrap_delete.json');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_opens_confirm_modal
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
    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_includes_deleted_post
    it('PC 표와 모바일 카드 모두 scrap.data.data 배열을 반복 렌더링한다', () => {
        const iterated = collectNodes(scrapList, (n) => Boolean(n.iteration));
        // Tbody(PC) + 모바일 카드 컨테이너, 최소 2곳
        expect(iterated.length).toBeGreaterThanOrEqual(2);
        iterated.forEach((n) => {
            expect(serialized(n.iteration)).toContain('scrap.data.data');
        });
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_includes_deleted_post
    it('회귀: scrapped_at 표시는 프론트 파이프가 아니라 백엔드(FormatsBoardDate)가 포맷한 값을 그대로 쓴다', () => {
        // relativeTime 파이프는 로케일을 항상 'ko' 로 고정해 en/ja 사이트에서도 한국어가 노출되는 결함이 있었다.
        // 게시판 게시글 목록·상세와 동일하게 PostScrapResource 가 서버에서 포맷·다국어를 처리하도록 되돌렸다 —
        // 프론트에서 relativeTime 파이프를 다시 붙이지 말 것.
        const str = serialized(scrapList);
        expect(str).not.toContain('relativeTime');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
    it('회귀: 상시 안내 배너는 제거되어 검색 영역이 헤더 바로 아래에 온다 (배너 제거 결정)', () => {
        const str = serialized(scrapList);
        expect(str).not.toContain('mypage.scrap.notice"');
        expect(str).not.toContain('mypage.scrap.notice_sub');
        expect(str).not.toContain('circle-info');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
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

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
    it('검색 버튼은 페이지를 1로 되돌리고 scrap 데이터소스를 재조회한다 (my_posts 탭과 동일 패턴)', () => {
        const searchBtn = findSearchAreaButton('$t:common.search');
        const str = serialized((searchBtn as any).actions);
        expect(str).toContain('scrapPage');
        expect(str).toContain('refetchDataSource');
        expect(str).toContain('"dataSourceId":"scrap"');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
    it('초기화 버튼은 검색어를 비우고 페이지를 1로 되돌린 뒤 재조회한다', () => {
        const resetBtn = findSearchAreaButton('$t:common.reset');
        const str = serialized((resetBtn as any).actions);
        expect(str).toContain('"scrapSearch":""');
        expect(str).toContain('scrapPage');
        expect(str).toContain('refetchDataSource');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
    it('회귀: 검색/초기화 버튼은 각자 독립된 로딩 상태(scrapSearching/scrapResetting)를 켜고, refetch 완료 후에야 끈다 (sequence 순서: on → refetch → off)', () => {
        const cases = [
            { labelKey: '$t:common.search', stateKey: 'scrapSearching' },
            { labelKey: '$t:common.reset', stateKey: 'scrapResetting' },
        ];
        for (const { labelKey, stateKey } of cases) {
            const btn = findSearchAreaButton(labelKey);
            const actions = callbacksOf(actionsOf(btn as any)[0] as any, 'actions') as Array<Record<string, unknown>>;
            expect(actions.length).toBe(3);
            expect(serialized(actions[0])).toContain(`"${stateKey}":true`);
            expect(actions[1].handler).toBe('refetchDataSource');
            expect(serialized(actions[2])).toContain(`"${stateKey}":false`);
        }
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
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

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_filters_by_title
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

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_pagination_always_visible
    it('회귀: 페이지네이션은 마이페이지 문의내역 탭과 동일하게 if 조건 없이 항상 노출된다 (1페이지뿐이어도 표시)', () => {
        const pagination = collectNodes(scrapList, (n) => (n as any).name === 'Pagination');
        expect(pagination.length).toBe(1);
        expect(pagination[0].if).toBeUndefined();
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_empty_state_uses_card_box
    it('빈 상태 안내가 항상 노출 탭 규약에 따라 존재한다', () => {
        const empty = findByComment(scrapList, '스크랩 목록 없음');
        expect(empty.length).toBeGreaterThan(0);
        expect(serialized(empty[0])).toContain('mypage.scrap.empty');
    });

    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_empty_state_uses_card_box
    it('회귀: 빈 상태는 마이페이지 마일리지 탭과 동일하게 카드 박스(테두리+배경)로 감싸진다 (배경에 텍스트만 떠 있지 않도록)', () => {
        const empty = findByComment(scrapList, '스크랩 목록 없음')[0];
        const className = String((empty as any).props?.className ?? '');
        expect(className).toContain('bg-white');
        expect(className).toContain('dark:bg-gray-800');
        expect(className).toContain('border');
        expect(className).toMatch(/\brounded/);
    });

    describe('PC 표 (Table/Tbody/Tr)', () => {
        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_table_and_card_are_exclusive
        it('표는 모바일에서 숨겨지고(hidden) 그 반대(모바일 카드)는 PC에서 숨겨진다 (반응형 상호배타)', () => {
            const tableWrap = findByComment(scrapList, '스크랩 목록 표 (PC)');
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            expect(tableWrap.length).toBe(1);
            expect(cardWrap.length).toBe(1);
            expect((tableWrap[0].responsive as any)?.portable?.props?.className).toContain('hidden');
            expect((cardWrap[0].props as any)?.className).toContain('hidden');
            expect((cardWrap[0].responsive as any)?.portable?.props?.className).not.toContain('hidden');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
        it('회귀: 살아있는 행 자체(Tr)에는 navigate 액션이 없다 (해제 버튼과의 이벤트 버블링 충돌 방지 — 게시판명·제목에 개별 클릭으로 대체)', () => {
            const aliveRows = findByComment(scrapList, '살아있는 원글 행');
            const row = aliveRows[0];
            expect(row.actions).toBeUndefined();
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
        it('회귀: 게시판명은 클릭 시 게시판 목록(/board/{slug})으로, 제목은 클릭 시 원글 상세(/board/{slug}/{id})로 각각 독립 이동한다', () => {
            const aliveRow = findByComment(scrapList, '살아있는 원글 행')[0];
            const tds = collectNodes(aliveRow, (n) => (n as any).name === 'Td');
            // tds[0] 은 선택 체크박스 열(#524) — 내용 열은 그 다음부터다
            const boardTd = tds[1];
            const titleTd = tds[2];

            const boardSpan = collectNodes(boardTd, (n) => (n as any).name === 'Span' && actionsOf(n as any).length > 0)[0];
            expect(boardSpan).toBeDefined();
            const boardAction = actionsOf(boardSpan as any)[0] as any;
            expect(boardAction.handler).toBe('navigate');
            expect(boardAction.params.path).toContain('item.board_slug');
            expect(boardAction.params.path).not.toContain('item.post_id');

            const titleSpan = collectNodes(titleTd, (n) => (n as any).name === 'Span' && actionsOf(n as any).length > 0)[0];
            expect(titleSpan).toBeDefined();
            const titleAction = actionsOf(titleSpan as any)[0] as any;
            expect(titleAction.handler).toBe('navigate');
            expect(titleAction.params.path).toContain('item.board_slug');
            expect(titleAction.params.path).toContain('item.post_id');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_deleted_post_exposes_board_and_author
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_tab_follows_board_activity
        it('회귀: 표 셀 여백은 마이페이지 마일리지 탭과 동일한 px-4 py-3 이다 (결정 — 지나치게 빡빡했던 px-2 py-2 에서 마일리지 탭 기준으로 복원)', () => {
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
        it('표 헤더는 컬럼 라벨 다국어 키를 사용한다', () => {
            const str = serialized(scrapList);
            expect(str).toContain('mypage.scrap.table.board');
            expect(str).toContain('mypage.scrap.table.title');
            expect(str).toContain('mypage.scrap.table.author');
            expect(str).toContain('mypage.scrap.table.comments');
            expect(str).toContain('mypage.scrap.table.scrapped_at');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
        it('회귀: 게시판·제목 컬럼은 왼쪽 정렬이고 나머지(작성자/댓글/스크랩일/관리) 헤더는 가운데 정렬이다 (결정에 따라 게시판 컬럼을 가운데→왼쪽 정렬로 변경)', () => {
            const headerRow = collectNodes(scrapList, (n) => (n as any).name === 'Tr' && !n.if && !n.actions).find((tr) =>
                serialized(tr).includes('mypage.scrap.table.board'),
            );
            const ths = collectNodes(headerRow, (n) => (n as any).name === 'Th');
            // 선택 체크박스 열 1 + 내용 열 6 (#524 선택 삭제)
            expect(ths.length).toBe(7);
            const [, board, title, author, comments, scrappedAt, manage] = ths as any[];
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
        it('회귀: 살아있는 행/삭제된 행 모두 게시판·제목 셀은 왼쪽 정렬이고, 나머지 셀은 가운데 정렬이다', () => {
            for (const rowComment of ['살아있는 원글 행', '삭제된 원글 행']) {
                const row = findByComment(scrapList, rowComment)[0];
                const tds = collectNodes(row, (n) => (n as any).name === 'Td');
                // 선택 체크박스 열 1 + 내용 열 6 (#524 선택 삭제)
                expect(tds.length).toBe(7);
                const [, board, title, author, comments, scrappedAt, manage] = tds as any[];
                expect(String(board.props?.className ?? '')).toContain('text-left');
                expect(String(board.props?.className ?? '')).not.toContain('text-center');
                expect(String(title.props?.className ?? '')).not.toContain('text-center');
                for (const td of [author, comments, scrappedAt, manage]) {
                    expect(td.props.className).toContain('text-center');
                    expect(td.props.className).not.toContain('text-right');
                }
            }
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
        it('회귀: 해제 버튼 컬럼도 빈 텍스트가 아니라 "관리" 라벨을 갖는다 (컬럼명 없어 보이는 회귀 방지)', () => {
            const headerRow = collectNodes(scrapList, (n) => (n as any).name === 'Tr' && !n.if && !n.actions).find(
                (tr) => serialized(tr).includes('mypage.scrap.table.board'),
            );
            expect(headerRow).toBeDefined();
            const ths = collectNodes(headerRow, (n) => (n as any).name === 'Th');
            // 선택 체크박스 열 1 + 내용 열 6 (#524 선택 삭제)
            expect(ths.length).toBe(7);
            const lastTh = ths[ths.length - 1];
            expect((lastTh as any).text).toBe('$t:mypage.scrap.table.manage');
            expect((lastTh as any).text).not.toBe('');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
        it('회귀: 살아있는 행의 해제 버튼은 stopPropagation 없이도 안전하다 (행 자체에 navigate 가 없으므로 이벤트 버블링 충돌 자체가 발생하지 않음 — stopPropagation 핸들러는 엔진 미구현이라 실질 no-op)', () => {
            const aliveRow = findByComment(scrapList, '살아있는 원글 행')[0];
            const removeBtn = findByComment(aliveRow, '스크랩 해제 버튼')[0];
            const actions = (removeBtn.actions as Array<Record<string, unknown>>) ?? [];
            expect(actions.some((a) => a.handler === 'stopPropagation')).toBe(false);
            expect(actions[0].handler).toBe('sequence');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
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
        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_includes_deleted_post
        it('회귀: 게시판 뱃지와 제목이 같은 줄(Div)에 배치된다 (별도 줄로 분리 시 세로 여백 회귀)', () => {
            const aliveCards = findByComment(scrapList, '살아있는 원글 카드');
            const titleRow = collectNodes(
                aliveCards[0],
                (n) => (n as any).name === 'Div' && serialized(n).includes('item.board_name') && serialized(n).includes('item.title'),
            );
            expect(titleRow.length).toBeGreaterThan(0);
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_list_deleted_post_exposes_board_and_author
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
        it('모바일 카드의 ✕ 버튼은 즉시 삭제가 아니라 확인 모달을 연다 (openModal scrap_delete_modal)', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeBtns = findByComment(cardWrap[0], '스크랩 해제 버튼');
            expect(removeBtns.length).toBeGreaterThan(0);
            const str = serialized(removeBtns[0]);
            expect(str).toContain('openModal');
            expect(str).toContain('scrap_delete_modal');
            expect(str).not.toContain('"method":"DELETE"');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
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

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
        it('회귀: 모바일 해제 버튼은 텍스트 버튼("해제")이며 카드 하단에 우측 정렬로 배치된다 (아이콘 절대위치 버튼 아님)', () => {
            const cardWrap = findByComment(scrapList, '스크랩 카드 목록 (모바일)');
            const removeWrap = findByComment(cardWrap[0], '스크랩 해제 버튼')[0];
            const str = serialized(removeWrap);
            expect(str).toContain('mypage.scrap.table.remove');
            expect(str).not.toContain('absolute');
            expect(str).not.toContain('"name":"Icon"');
        });

        // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
        // @effects mypage_delete_opens_confirm_modal
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
    // @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_opens_confirm_modal
    it('모달 id 가 목록의 openModal target 과 일치한다 (scrap_delete_modal)', () => {
        expect((scrapDeleteModal as any).id).toBe('scrap_delete_modal');
    });

    // @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_opens_confirm_modal
    it('해제 버튼이 me/scraps/{id} DELETE 후 목록을 갱신한다', () => {
        const str = serialized(scrapDeleteModal);
        expect(str).toContain('/api/modules/sirsoft-board/me/scraps/');
        expect(str).toContain('DELETE');
        expect(str).toContain('_global.scrapDeleteModal');
        expect(str).toContain('refetchDataSource');
    });

    // @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_requires_ownership
    it('원글 삭제 여부에 따라 안내 문구가 분기된다', () => {
        const str = serialized(scrapDeleteModal);
        expect(str).toContain('mypage.scrap.delete_confirm');
        expect(str).toContain('mypage.scrap.delete_confirm_deleted');
        expect(str).toContain('mypage.scrap.delete_notice');
    });

    // @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_delete_requires_ownership
    it('회귀: 원글 보존 안내 문구는 별도 강조 박스 없이 본문 텍스트 바로 아래 작은 회색 글씨로 붙는다 (1안 — 결정에 따라 박스형에서 인라인 텍스트로 변경)', () => {
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
    // @scenario action=mypage_list,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_tab_follows_board_activity
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_scrap_state_not_inherited_by_sibling
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

    // @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects detail_scrap_state_not_inherited_by_sibling
    it('스크랩 버튼(show.json)은 _local.isScraped 를 post?.data?.is_scraped 보다 우선 참조한다 (null 로 비워야 서버값이 다시 채택됨을 보장)', () => {
        const scrapButtons = findByComment(basicShow, '스크랩 버튼');
        const btn = scrapButtons[0];
        const str = serialized(btn);
        expect(str).toContain('_local.isScraped ?? post?.data?.is_scraped');
    });
});

// ── #524 WP-A: 마이페이지 스크랩 검색 구분 · 선택 삭제 · 스크랩 중지 행 ──────────────
//
// 고치기 전 마이페이지 스크랩 탭은 검색이 없었고, 해제는 한 건씩만 됐다. 스크랩을 꺼 둔
// 게시판의 글도 살아 있는 글과 똑같이 보여, 눌러 보고서야 원글로 갈 수 없다는 것을 알았다.
// 이 묶음은 그 셋을 고정한다 — 검색 세 갈래 · 선택 삭제 · 세 상태(정상·중지·삭제)의 행 구분.

describe('#524 WP-A — 마이페이지 스크랩 검색·선택 삭제·중지 행', () => {
    const listStr = serialized(scrapList);

    // @scenario action=mypage_search, search_field=title_content
    // @effects mypage_list_datasource_passes_search_field
    it('목록 데이터소스가 검색어와 검색 대상을 함께 보낸다', () => {
        const ds = (scrapPage as any).data_sources.find((d: any) => d.id === 'scrap');

        expect(ds.endpoint).toContain('search={{_local.scrapSearch');
        expect(ds.endpoint).toContain("search_field={{_local.scrapSearchField ?? 'title_content'}}");
    });

    // @scenario action=mypage_search, search_field=title_content
    // @effects mypage_list_init_defaults_search_and_selection
    it('init 이 검색 대상과 선택 목록의 초기값을 심는다', () => {
        const init = (scrapPage as any).init_actions[0];

        expect(init.params.target).toBe('local');
        expect(init.params.scrapSearchField).toBe('title_content');
        // 선택은 빈 배열로 시작해야 한다 — undefined 면 첫 체크에서 spread 가 터진다.
        expect(init.params.scrapSelectedIds).toEqual([]);
        expect(init.params.scrapPage).toBe(1);
    });

    // @scenario action=mypage_search, search_field=title
    // @effects mypage_list_search_field_offers_three_targets
    it('검색 대상 셀렉트가 제목+내용·제목·내용 셋을 주고 고른 값만 담아 둔다', () => {
        const select = collectNodes(scrapList, (n) => (n.props as any)?.['data-testid'] === 'scrap-search-field')[0];

        expect(asArray((select.props as any).options).map((o: any) => o.value)).toEqual([
            'title_content', 'title', 'content',
        ]);

        const action = actionsOf(select)[0];
        expect(action.handler).toBe('setState');
        expect((action.params as any).scrapSearchField).toContain('$event.target.value');
    });

    // @scenario action=mypage_search, search_field=content
    // @effects mypage_list_enter_runs_same_search_sequence
    it('입력칸 엔터가 검색 버튼과 같은 순서를 탄다', () => {
        const input = collectNodes(scrapList, (n) => (n.props as any)?.['data-testid'] === 'scrap-search-input')[0];
        const enter = actionsOf(input).find((a: any) => a.key === 'Enter');

        expect(enter.handler).toBe('sequence');

        const steps = callbacksOf(enter, 'actions');
        expect((steps[0].params as any).scrapPage).toBe(1);
        expect(steps[1].handler).toBe('refetchDataSource');
    });

    // @scenario action=mypage_search, search_field=title
    // @effects mypage_list_search_clears_selection
    it('검색·초기화·쪽 이동은 선택을 비운다 — 화면에 없는 건이 일괄 삭제에 실리면 안 된다', () => {
        const clearing = collectNodes(scrapList, (n) =>
            n.handler === 'setState' && Array.isArray((n.params as any)?.scrapSelectedIds)
            && ((n.params as any).scrapSelectedIds as unknown[]).length === 0);

        // 검색(버튼·엔터)·초기화·쪽 이동 — 선택을 비우는 자리가 여럿이다.
        expect(clearing.length).toBeGreaterThanOrEqual(3);
    });

    // @scenario action=mypage_bulk_delete
    // @effects mypage_list_select_all_uses_every_and_map
    it('전체 선택은 현재 쪽 전부를 담고, 체크 상태는 그 쪽이 모두 담겼는지로 읽는다', () => {
        const selectAll = collectNodes(scrapList, (n) =>
            String((n.props as any)?.['data-testid'] ?? '').startsWith('scrap-select-all'));

        expect(selectAll.length).toBeGreaterThanOrEqual(1);

        for (const node of selectAll) {
            const action = actionsOf(node)[0];
            expect((action.params as any).scrapSelectedIds).toContain('map(r => r.id)');
            expect((node.props as any).checked).toContain('every(r =>');
        }
    });

    // @scenario action=mypage_bulk_delete
    // @effects mypage_list_row_checkbox_toggles_membership
    it('행 체크는 이미 들어 있으면 빼고 없으면 더한다 — 엔진에 토글 핸들러가 없다', () => {
        const checkboxes = collectNodes(scrapList, (n) =>
            String((n.props as any)?.['data-testid'] ?? '').startsWith('scrap-check-'));

        expect(checkboxes.length).toBeGreaterThanOrEqual(2); // PC 표 + 모바일 카드

        for (const node of checkboxes) {
            const expression = (actionsOf(node)[0].params as any).scrapSelectedIds;
            expect(expression).toContain('includes(item.id)');
            expect(expression).toContain('filter(v => v !== item.id)');
            expect(expression).toContain('...(_local.scrapSelectedIds ?? [])');
        }
    });

    // @scenario action=mypage_bulk_delete
    // @effects mypage_list_bulk_button_opens_modal_when_selected
    it('선택 삭제 버튼은 고른 것이 없으면 잠기고, 눌리면 확인 창을 연다', () => {
        const button = collectNodes(scrapList, (n) => (n.props as any)?.['data-testid'] === 'scrap-bulk-delete-btn')[0];

        expect((button.props as any).disabled).toContain('scrapSelectedIds');
        expect(actionsOf(button)[0].handler).toBe('openModal');
        expect(actionsOf(button)[0].target).toBe('scrap_bulk_delete_modal');
    });

    // @scenario action=mypage_bulk_delete
    // @effects mypage_bulk_modal_sends_ids_and_clears_selection
    it('일괄 모달이 선택 목록을 ids 로 DELETE 하고 성공 시 비운다', () => {
        const call = collectNodes(scrapBulkDeleteModal, (n) => n.handler === 'apiCall')[0];

        expect((call.params as any).method).toBe('DELETE');
        expect(call.target).toBe('/api/modules/sirsoft-board/me/scraps');
        expect((call.params as any).body.ids).toContain('_local.scrapSelectedIds');

        const cleared = callbacksOf(call, 'onSuccess')
            .find((a: any) => a.handler === 'setState' && 'scrapSelectedIds' in (a.params ?? {}));
        expect((cleared!.params as any).scrapSelectedIds).toEqual([]);
        // 선택 배열은 목록(부모 화면)의 지역 상태다 — 창의 지역 상태에 쓰면 목록의 「N개 선택」이 남고
        // 다음 일괄 해제가 빈 배열로 422 를 받았다(K2 실측 2026-10-06).
        expect((cleared!.params as any).target).toBe('$parent._local');
        expect((call.params as any).body.ids).toContain('$parent._local.scrapSelectedIds');
    });

    // @scenario action=mypage_delete
    // @effects mypage_single_delete_removes_id_from_selection
    it('단건 해제는 지운 id 를 선택 목록에서도 뺀다', () => {
        const call = collectNodes(scrapDeleteModal, (n) => n.handler === 'apiCall')[0];
        const cleaned = callbacksOf(call, 'onSuccess')
            .find((a: any) => a.handler === 'setState' && 'scrapSelectedIds' in (a.params ?? {}));

        // 남아 있으면 다음 일괄 삭제가 이미 없는 ID 를 싣는다.
        expect((cleaned!.params as any).scrapSelectedIds).toContain('filter(v => v !== _global.scrapDeleteModal?.id)');
        // 목록의 선택을 고쳐야 하므로 부모 화면의 지역 상태에 쓴다(K2 실측 2026-10-06 — 「1개 선택」이 남았다).
        expect((cleaned!.params as any).target).toBe('$parent._local');
        expect((cleaned!.params as any).scrapSelectedIds).toContain('$parent._local.scrapSelectedIds');
    });

    // @scenario board_setting=use_scrap_off
    // @effects mypage_list_marks_scrap_disabled_row
    it('스크랩 중지 행은 살아있는 행과 분리된 노드이고 원글로 가지 않는다', () => {
        // 액션 레벨 if 가 없어 세 상태를 각각의 Tr 로 둔다 — 조건이 셋 다 있어야 한다.
        expect(listStr).toContain('!item.is_deleted && item.scrap_enabled');
        expect(listStr).toContain('!item.is_deleted && !item.scrap_enabled');
        expect(listStr).toContain('item.is_deleted');

        const disabledRows = collectNodes(scrapList, (n) =>
            String((n as any).if ?? '') === '{{!item.is_deleted && !item.scrap_enabled}}');

        expect(disabledRows.length).toBeGreaterThanOrEqual(1);

        for (const row of disabledRows) {
            // 눌러도 갈 수 없는 곳으로 보내지 않는다.
            expect(collectNodes(row, (n) => n.handler === 'navigate')).toHaveLength(0);
        }
    });

    // @scenario board_setting=board_deleted
    // @effects mypage_list_marks_deleted_board_row
    it('게시판이 지워진 행은 게시판명 자리에 삭제 표시를 낸다', () => {
        expect(listStr).toContain('item.board_deleted');
        expect(listStr).toMatch(/mypage\.scrap\.(board_deleted|scrap_disabled|deleted)/);
    });

    // @scenario action=mypage_bulk_delete
    // @effects mypage_list_testids_cover_pc_and_mobile
    it('PC 와 모바일이 서로 다른 testid 를 쓴다 — E2E 가 둘을 구별해야 한다', () => {
        const testIds = new Set(
            collectNodes(scrapList, (n) => typeof (n.props as any)?.['data-testid'] === 'string')
                .map((n) => (n.props as any)['data-testid'] as string)
        );

        for (const id of [
            'scrap-search-field', 'scrap-search-input', 'scrap-select-all', 'scrap-select-all-m',
            'scrap-bulk-delete-btn', 'scrap-card-{{item.id}}', 'scrap-card-m-{{item.id}}',
            'scrap-check-{{item.id}}', 'scrap-check-m-{{item.id}}',
        ]) {
            expect(testIds).toContain(id);
        }
    });
});

describe('마이페이지 스크랩 — 검색 0건 안내', () => {
    // 검색어를 건 채 0건이면 「아직 스크랩한 글이 없다」가 아니라 검색 결과가 없다는 뜻이다(실측 2026-10-03).
    // @scenario action=mypage_search,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board,search_field=title
    // @effects mypage_filtered_empty_says_no_results
    it('검색 중 0건은 검색 결과 없음, 조건이 없을 때만 아직 없음', () => {
        const json = JSON.stringify(scrapList);
        expect(json.includes('$t:board.no_search_results')).toBe(true);
        expect(json.includes('{{!!_local.scrapSearchApplied}}')).toBe(true);
        expect(json.includes('{{!(!!_local.scrapSearchApplied)}}')).toBe(true);
    });
});

describe('K2 실측(2026-10-06) — 스크랩 목록 후속 수정', () => {
    // @scenario action=mypage_list,actor=owner,board_setting=board_deleted,post_state=soft_deleted,post_scope=same_board
    // @effects mypage_list_board_deleted_row_shows_board_deleted_flag
    it('게시판이 지워진 스크랩은 삭제글 행·카드에서도 「게시판 삭제됨」을 단다', () => {
        // 게시판을 지우면 글도 함께 하드 삭제돼 이 스크랩은 늘 is_deleted 분기로 온다. 「게시판 삭제됨」
        // 표시가 is_deleted 가 아닌 분기에만 있어 화면에는 게시판 칸이 빈 채 「삭제됨」만 보였다.
        const deletedBranches = collectNodes(scrapList, (n) => String((n as any).if ?? '') === '{{item.is_deleted}}');

        expect(deletedBranches.length).toBe(2); // PC 행 + 모바일 카드

        for (const branch of deletedBranches) {
            const flags = collectNodes(branch, (n) =>
                String((n as any).if ?? '') === '{{item.board_deleted}}'
                && JSON.stringify(n).includes('$t:mypage.scrap.board_deleted_flag'));

            expect(flags.length).toBe(1);

            // 게시판명은 게시판이 남아 있을 때만 그린다.
            const names = collectNodes(branch, (n) =>
                String((n as any).if ?? '') === '{{!item.board_deleted}}'
                && JSON.stringify(n).includes('item.board_name'));

            expect(names.length).toBe(1);
        }
    });

    // @scenario action=mypage_search,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_list_search_input_shrinks_on_narrow_screen
    it('검색 입력칸이 줄어들 수 있어 좁은 화면에서 검색 줄이 칸 밖으로 밀리지 않는다', () => {
        // 입력칸의 고유 최소 폭(약 214px) 때문에 390px 에서 「검색」·「초기화」가 화면 밖(오른쪽 455px)으로
        // 밀리고 페이지가 가로로 스크롤됐다.
        const inputs = collectNodes(scrapList, (n) => (n.props as any)?.['data-testid'] === 'scrap-search-input');

        expect(inputs.length).toBe(1);
        expect((inputs[0].props as any).className).toContain('min-w-0');
    });

    // @scenario action=mypage_bulk_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_scrap_modal_buttons_show_pointer
    it('단건·일괄 해제 창의 취소·해제 버튼이 손가락 커서를 쓴다', () => {
        for (const modal of [scrapDeleteModal, scrapBulkDeleteModal]) {
            const buttons = collectNodes(modal, (n) => (n as any).name === 'Button');

            expect(buttons.length).toBeGreaterThanOrEqual(2);
            for (const button of buttons) {
                expect(String((button.props as any)?.className ?? '')).toContain('cursor-pointer');
            }
        }
    });

    // @scenario action=mypage_bulk_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
    // @effects mypage_scrap_bulk_button_says_remove
    it('일괄 버튼 이름이 창·토스트와 같은 「해제」 말을 쓴다', () => {
        // 버튼만 「선택 삭제」라 원글을 지우는 것처럼 읽혔다 — 창은 「해제」, 영어는 「Remove selected」, 내 반응 탭은 「선택 해제」.
        const ko = require('../../lang/partial/ko/mypage.json');
        const en = require('../../lang/partial/en/mypage.json');
        const scrapKo = ko.scrap ?? ko.mypage?.scrap;
        const scrapEn = en.scrap ?? en.mypage?.scrap;

        expect(scrapKo.bulk_delete).toBe('선택 해제');
        expect(scrapEn.bulk_delete).toBe('Remove selected');
    });
});
