/**
 * 관리자 게시글 상세 — 답글 첨부 펼침의 답글별 격리 회귀 테스트 (76차)
 *
 * @description
 * 답글 카드 조각은 반복(`item, itemIdx in post?.data?.replies`) 안에서 렌더되는데,
 * 첨부 펼침 상태를 `_local.showAttachments` **단일 스칼라** 하나로 두었다. 반복 안의 모든
 * 인스턴스가 같은 값을 읽고 쓰므로 한 답글의 토글이 모든 답글에 전파된다.
 *
 * 브라우저 실측(g7.dev, qna #104 — 첨부 있는 답글 2건을 만들어 대조):
 *   첫 번째 토글만 눌렀는데 chevron 이 **둘 다** `chevron-up` 으로 바뀌었다(2회 재현).
 *   새로고침·라우트 왕복 후에는 정상 초기화되므로 영속성·격리 축은 문제가 없고,
 *   오직 "같은 화면 안에서 답글끼리 상태를 공유한다" 는 점만이 결함이다.
 *
 * 원글 카드(`_post_card_content`)는 화면에 하나뿐이라 스칼라로도 맞다 — 함께 바꾸지 않는다.
 *
 * 형태는 **같은 파일이 이미 쓰고 있는 것**을 따른다 — 본문 펼침(`expandedReplies`)은 진작부터
 * `{[item.id]: bool}` 표였고 조각 머리말도 그렇게 적어 두었다. 첨부만 스칼라로 남아 있었다.
 * 새 모양을 들이는 대신 그 표 형태를 그대로 쓴다.
 *
 * @scenario reply_count=multiple
 * @effects reply_attachment_toggle_isolated_per_reply
 *
 * 계약:
 *   ① 답글 카드의 첨부 펼침 상태는 답글 식별자(`item.id`)를 키로 하는 **표**다.
 *   ② 토글·표시·아이콘 세 자리가 모두 같은 표를 본다 (한 곳만 고치면 아이콘이 어긋난다).
 *   ③ 답글 카드에는 반복과 무관한 스칼라 `showAttachments` 가 남아 있지 않다.
 */

import { describe, it, expect } from 'vitest';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';
import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';

/** 답글별 첨부 펼침 표의 상태 키 (본문 펼침 `expandedReplies` 와 같은 모양) */
const MAP_KEY = 'expandedReplyAttachments';

const replySource = JSON.stringify(replyCard);

describe('관리자 답글 카드 — 첨부 펼침 답글별 격리', () => {
  it('스칼라 showAttachments 를 쓰지 않는다 (반복 안에서 모든 답글이 공유되던 원인)', () => {
    expect(replySource).not.toContain('showAttachments');
  });

  it('펼침 상태는 답글 식별자를 키로 하는 표다', () => {
    expect(replySource).toContain(MAP_KEY);
    // 인덱스가 아니라 식별자여야 정렬·삭제에도 다른 답글로 옮겨붙지 않는다
    expect(replySource).toContain(`_local.${MAP_KEY}?.[item.id]`);
  });

  it('토글·표시·아이콘 세 자리가 모두 같은 표를 본다', () => {
    expect(replySource.includes(`"${MAP_KEY}":`), 'set-state 가 표를 갱신하지 않는다').toBe(true);

    const readCount = replySource.split(`_local.${MAP_KEY}?.[item.id]`).length - 1;
    // set-state 안의 참조 + data-if(첨부 목록) + icon(chevron 방향)
    expect(readCount, '표시 조건과 아이콘 방향 중 한 곳이 표를 보지 않는다').toBeGreaterThanOrEqual(3);
  });

  it('토글은 표를 통째로 다시 지어 그 답글의 값만 뒤집는다 (본문 펼침과 같은 규칙)', () => {
    expect(replySource).toContain(
      `Object.assign({}, _local.${MAP_KEY} || {}, {[item.id]: !_local.${MAP_KEY}?.[item.id]})`
    );
  });

  it('이미 답글별로 갈려 있던 본문 펼침은 그대로 둔다 (형태를 새로 들이지 않았다)', () => {
    expect(replySource).toContain('_local.expandedReplies?.[item.id]');
  });

  it('원글 카드는 화면에 하나뿐이라 스칼라 그대로 둔다 (불필요한 동반 변경 차단)', () => {
    expect(JSON.stringify(postCard)).toContain('showAttachments');
  });
});
