/**
 * @file board-comment-reactions.test.tsx
 * @description 댓글 반응 버튼 레이아웃 회귀 (이슈 #525 WP-D)
 *
 * 이 화면이 지키는 계약:
 *  - 노출은 게시판이 **반응 사용과 댓글 반응 사용을 둘 다** 켰을 때뿐이다. 댓글 반응은
 *    글 반응의 하위 토글이라 상위가 꺼지면 댓글 반응만 남을 수 없다.
 *  - 삭제·블라인드된 댓글에는 새 반응을 달 수 없으므로 그 갈래에서는 줄 자체를 내지 않는다.
 *  - 한 화면에 댓글이 여럿이라 화면 상태 네 가지를 **댓글 번호로 키 잡은 맵**으로 든다.
 *    그러지 않으면 A 댓글에 누른 것이 B 댓글의 하이라이트를 건드린다.
 *  - 클릭은 낙관 갱신 → 응답으로 확정 → 실패 시 롤백이다(글 반응과 같은 규약).
 *  - 본인 댓글은 disabled 를 걸지 않고 클릭을 받아 안내한다 — disabled 버튼은 클릭 이벤트가
 *    발생하지 않아 안내를 띄울 수 없다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다.
 */

import { describe, it, expect } from 'vitest';

import commentItem from '../../layouts/partials/board/show/_comment_item.json';
// 형제 표면(글 상세 반응 버튼) — 이름을 어떻게 읽는지의 정본이다. 손으로 적은 문자열이 아니라
// 실제 저작본에서 뽑아야 양쪽이 같이 달라져도 검사가 따라간다.
import postShow from '../../layouts/partials/board/types/basic/show.json';

const json = JSON.stringify(commentItem);
const postJson = JSON.stringify(postShow);

describe('이슈 #525 — 댓글 반응 버튼 (_comment_item.json)', () => {
    // @scenario case=comment_buttons_render_under_both_toggles
    // @effects comment_reaction_group_requires_both_toggles
    it('반응 사용과 댓글 반응 사용이 둘 다 켜져야 노출된다', () => {
        // 상위 토글만 보면 「댓글 반응 끔」 설정이 화면에 반영되지 않는다.
        expect(/board\?\.use_reaction/.test(json)).toBe(true);
        expect(/board\?\.use_comment_reaction/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_render_under_both_toggles
    // @effects comment_reaction_group_requires_both_toggles
    it('활성 유형이 하나도 없으면 빈 줄을 남기지 않는다', () => {
        expect(/reaction_type_options \?\? \[\]\)\.length > 0/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_render_under_both_toggles
    // @effects comment_reaction_group_hidden_on_deleted_or_blinded
    it('삭제·블라인드 댓글과 수정 중인 댓글에는 나오지 않는다', () => {
        // 새 반응을 달 수 없는 상태다. 이미 남긴 반응의 해제는 마이페이지 몫.
        expect(/!comment\?\.deleted_at/.test(json)).toBe(true);
        expect(/comment\?\.status !== 'blinded'/.test(json)).toBe(true);
        expect(/commentEdit\?\.editingCommentId !== comment\?\.id/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_render_under_both_toggles
    // @effects comment_reaction_group_requires_both_toggles
    it('유형은 게시판이 내려준 활성 목록을 순회해 그린다', () => {
        expect(/reaction_type_options/.test(json)).toBe(true);
        expect(/"item_var":\s*"commentReactionType"/.test(json)).toBe(true);
    });
});

describe('이슈 #525 — 댓글 반응 상태 키잉', () => {
    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_state_keyed_by_comment_id
    it('네 가지 화면 상태를 전부 댓글 번호로 키 잡는다', () => {
        // 스칼라로 들면 목록의 다른 댓글이 같은 값을 공유해 하이라이트가 번진다.
        for (const key of ['commentReacting', 'commentReactionApplied', 'commentMyReaction', 'commentReactionCounts']) {
            expect(json, key).toContain(key);
            expect(new RegExp(`_local\\?\\.${key}\\?\\.\\[comment\\?\\.id\\]`).test(json), key).toBe(true);
        }
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_state_keyed_by_comment_id
    it('갱신도 맵 통째 교체가 아니라 그 댓글 자리만 바꾼다', () => {
        // Object.assign 으로 기존 키를 보존해야 다른 댓글의 상태가 날아가지 않는다.
        expect(/Object\.assign\(\{\}, _local\?\.commentReacting \|\| \{\}, \{\[comment\?\.id\]/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_optimistic_then_confirmed_by_response
    it('개수는 override 가 있으면 그것을, 없으면 서버값을 쓴다', () => {
        expect(
            /_local\?\.commentReactionCounts\?\.\[comment\?\.id\] \?\? comment\?\.reaction_counts/.test(json),
        ).toBe(true);
    });
});

describe('이슈 #525 — 댓글 반응 클릭 흐름', () => {
    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_optimistic_then_confirmed_by_response
    it('클릭 즉시 낙관 갱신하고 같은 유형 재클릭이면 해제로 예측한다', () => {
        expect(/=== commentReactionType\.id \? null : commentReactionType\.id/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_optimistic_then_confirmed_by_response
    it('댓글 반응 주소로 유형 번호를 실어 보낸다', () => {
        expect(/\/comments\/\{\{comment\?\.id\}\}\/react/.test(json)).toBe(true);
        expect(/"reaction_type_id"/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_optimistic_then_confirmed_by_response
    it('성공하면 다시 조회하지 않고 응답값으로 확정한다', () => {
        // 목록 전체를 다시 받으면 스크롤이 튀고 느리다. 응답만으로 개수는 정확하다.
        expect(/response\.data\.my_reaction_type_id/.test(json)).toBe(true);
        expect(/response\.data\.reaction_counts/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_failure_rolls_back_overrides
    it('실패하면 그 댓글 자리의 override 를 걷어 서버값으로 되돌린다', () => {
        expect(/\{\[comment\?\.id\]: false\}/.test(json)).toBe(true);
        expect(/board\.reaction\.failed/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_self_and_guest_branch_without_redirect_helper
    it('본인 댓글·비로그인을 분기로 가르고 본인 댓글은 잠그지 않는다', () => {
        expect(/comment\?\.is_author \? 'self_comment'/.test(json)).toBe(true);
        expect(/board\.reaction\.self_comment_denied/.test(json)).toBe(true);
        expect(/board\.reaction\.login_required/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_self_and_guest_branch_without_redirect_helper
    it('비로그인은 돌아올 주소를 달고 로그인으로 보낸다', () => {
        expect(/"redirect"/.test(json)).toBe(true);
        expect(/\/login/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_icon_image_precedes_font_icon
    it('올려 둔 아이콘 이미지가 있으면 이미지로, 처리 중에는 스피너로 바뀐다', () => {
        expect(/!!commentReactionType\.icon_url/.test(json)).toBe(true);
        expect(/!commentReactionType\.icon_url/.test(json)).toBe(true);
        expect(/'fa-spinner'/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_names_the_type_when_icon_is_absent
    it('아이콘 이미지가 없는 유형은 이름을 병기해 서로 구분된다', () => {
        // 이미지를 올리지 않은 유형은 전부 같은 기본 글리프(fa-thumbs-up)로 떨어진다.
        // 그때 이름이 없으면 회원은 두 버튼을 구분할 수 없다.
        // 이름을 그리는 식은 형제 표면(글 상세)에서 파생한다 — 모집단이 비면 red.
        const namePattern = /\{\{\s*(\w+)\.name \?\? ''\s*\}\}/;
        const siblingUsesName = namePattern.test(postJson);
        expect(siblingUsesName).toBe(true);

        // 댓글 버튼도 같은 식으로 이름을 그리되, 이미지가 없을 때로 한정한다.
        expect(/commentReactionType\.name \?\? ''/.test(json)).toBe(true);
        expect(/"if":"\{\{!commentReactionType\.icon_url\}\}","text":"\{\{commentReactionType\.name \?\? ''\}\}"/.test(json)).toBe(true);
    });

    // @scenario case=comment_buttons_key_state_by_comment_id
    // @effects comment_reaction_names_the_type_when_icon_is_absent
    it('이름은 풍선도움말로 항상 안내된다', () => {
        // 이미지가 있는 유형은 화면에 이름을 내지 않으므로,
        // 보조기기·마우스 오버가 이름을 얻는 경로는 title 뿐이다.
        expect(/"title":"\{\{commentReactionType\.name \?\? ''\}\}"/.test(json)).toBe(true);
    });
});
