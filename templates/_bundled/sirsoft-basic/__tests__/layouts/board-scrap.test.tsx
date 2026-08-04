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
 * @effects detail_button_reflects_is_scraped,detail_shows_scrap_count,guest_toggle_rejected_401,mypage_list_includes_deleted_post,mypage_delete_requires_ownership
 */

import { describe, it, expect } from 'vitest';

import basicShow from '../../layouts/partials/board/types/basic/show.json';
import scrapPage from '../../layouts/mypage/scrap.json';
import scrapList from '../../layouts/partials/mypage/scrap/_list.json';
import scrapDeleteModal from '../../layouts/partials/mypage/scrap/_modal_scrap_delete.json';
import tabNavigation from '../../layouts/partials/mypage/_tab_navigation.json';

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

describe('이슈 #524 — 스크랩 목록 카드 (_list.json)', () => {
    it('카드가 scrap.data.data 배열을 반복 렌더링한다', () => {
        const iterated = collectNodes(scrapList, (n) => Boolean(n.iteration));
        expect(iterated.length).toBeGreaterThan(0);
        expect(serialized(iterated[0].iteration)).toContain('scrap.data.data');
    });

    it('살아있는 원글 카드는 제목·게시판명·작성자·댓글수·스크랩일을 노출한다', () => {
        const aliveCards = findByComment(scrapList, '살아있는 원글 카드');
        expect(aliveCards.length).toBeGreaterThan(0);
        const str = serialized(aliveCards[0]);
        expect(str).toContain('item.title');
        expect(str).toContain('item.board_name');
        expect(str).toContain('item.author_name');
        expect(str).toContain('mypage.scrap.comments');
        expect(str).toContain('mypage.scrap.scrapped_at');
    });

    it('삭제된 원글 카드는 톤다운되고 "삭제된 게시글" 을 표시한다 (is_deleted)', () => {
        const deletedCards = findByComment(scrapList, '삭제된 원글 카드');
        expect(deletedCards.length).toBeGreaterThan(0);
        const card = deletedCards[0];
        expect(String(card.if)).toContain('item.is_deleted');
        expect(serialized(card)).toContain('mypage.scrap.deleted_post');
    });

    it('✕ 버튼은 즉시 삭제가 아니라 확인 모달을 연다 (openModal scrap_delete_modal)', () => {
        const removeBtns = findByComment(scrapList, '스크랩 해제 버튼');
        expect(removeBtns.length).toBeGreaterThan(0);
        const str = serialized(removeBtns[0]);
        expect(str).toContain('openModal');
        expect(str).toContain('scrap_delete_modal');
        // ✕ 자체에서는 DELETE 를 직접 호출하지 않는다 (확인 모달 경유)
        expect(str).not.toContain('"method":"DELETE"');
    });

    it('회귀(#524): ✕ 버튼의 최상위 트리거 액션은 type 을 가져야 한다 (setState+openModal 을 sequence 로 묶음)', () => {
        // 최상위 actions 항목에 type/event 가 없으면 getEventHandlerName 이 charAt(undefined) 로 터진다.
        const removeBtns = findByComment(scrapList, '스크랩 해제 버튼');
        const btn = removeBtns[0] as { actions?: Array<Record<string, unknown>> };
        expect(Array.isArray(btn.actions)).toBe(true);
        for (const action of btn.actions!) {
            expect(Boolean(action.type || action.event)).toBe(true);
        }
        // 여러 동작(setState→openModal)은 단일 트리거 안 sequence 로 묶여야 한다.
        expect(serialized(btn.actions![0])).toContain('sequence');
    });

    it('빈 상태 안내가 항상 노출 탭 규약에 따라 존재한다', () => {
        const empty = findByComment(scrapList, '스크랩 목록 없음');
        expect(empty.length).toBeGreaterThan(0);
        expect(serialized(empty[0])).toContain('mypage.scrap.empty');
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
