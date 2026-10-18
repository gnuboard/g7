// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file board-user-ability-gate.test.ts
 * @description 사용자 화면(게시판·알림·공개 페이지) abilities 게이트 (7.1.0 abilities 게이트 최종 점검 basic-board-user·G27·G28)
 *
 * - G27: 알림 읽음·삭제가 목록 응답 abilities(can_update·can_delete)로 잠긴다(헤더 알림센터는 API 호출을, 마이페이지는 버튼을)
 * - G28: 공개 페이지 [편집]·버전 배지는 can_update, 없는 페이지 [페이지 만들기]는 404 응답의 can_create
 * - #5 글쓰기 버튼: 비로그인은 눌러서 로그인으로, 로그인했는데 권한 없으면 잠금
 * - #6 카드·갤러리 비밀글 잠금: 목록에 없는 키 대신 컬렉션 비밀글 열람 권한
 * - #9 홈·인기글: 선택 인증으로 로그인 회원 권한을 반영
 * - #10 비회원 비밀번호 확인 두 창: 선택 인증
 * - #11 글쓰기 폼 401: 로그인 유도
 * - #12 답글 비밀글: 서버가 원문을 가렸을 때만 안내
 * - #13 첨부 권한 없음 카드: 비회원은 로그인 유도
 */

import { describe, it, expect } from 'vitest';
import userBase from '../../layouts/_user_base.json';
import notificationList from '../../layouts/partials/mypage/notifications/_list.json';
import pageShow from '../../layouts/page/show.json';
import writeButton from '../../layouts/partials/board/index/_write_button.json';
import cardIndex from '../../layouts/partials/board/types/card/index.json';
import galleryIndex from '../../layouts/partials/board/types/gallery/index.json';
import home from '../../layouts/home.json';
import popular from '../../layouts/board/popular.json';
import formPasswordModal from '../../layouts/partials/board/form/_password_verify_modal.json';
import showPasswordModal from '../../layouts/partials/board/show/modals/_password_verify_modal.json';
import boardForm from '../../layouts/board/form.json';
import replySection from '../../layouts/partials/board/show/_reply_section.json';
import postAttachments from '../../layouts/partials/board/show/_post_attachments.json';

type Node = Record<string, any>;

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): any {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 특정 엔드포인트를 부르는 apiCall 액션들 */
function apiCalls(tree: unknown, pred: (target: string, method: string) => boolean): Node[] {
  return collect(tree, (n) => n.handler === 'apiCall' && pred(String(n.target ?? ''), String(n.params?.method ?? '')));
}

describe('G27 알림 읽음·삭제', () => {
  /**
   * 헤더(PC·모바일) 알림센터의 읽음·삭제 API 호출이 abilities 로 막힌다.
   *
   * @scenario case=notification_abilities_false
   *
   * @effects header_notification_calls_gated
   */
  it('_user_base 의 읽음 4종·삭제 호출이 can_update/can_delete 로 막힌다', () => {
    const denied = { notifications: { data: { abilities: { can_update: false, can_delete: false } } } };
    const allowed = { notifications: { data: { abilities: { can_update: true, can_delete: true } } } };
    const reads = apiCalls(userBase, (t, m) => t.startsWith('/api/user/notifications') && ['POST', 'PATCH'].includes(m) && /read/.test(t));
    expect(reads.length).toBe(6);
    const deletes = apiCalls(userBase, (t, m) => t.startsWith('/api/user/notifications/{{$args') && m === 'DELETE');
    expect(deletes.length).toBe(2);
    for (const call of [...reads, ...deletes]) {
      expect(evaluate(call.if, denied), call.target).toBe(false);
      expect(evaluate(call.if, allowed), call.target).toBe(true);
    }
    const openDeleteAll = collect(userBase, (n) => n.handler === 'openModal' && n.target === 'notification_delete_all_confirm_modal');
    expect(openDeleteAll.length).toBeGreaterThan(0);
    openDeleteAll.forEach((a) => expect(evaluate(a.if, denied)).toBe(false));
  });

  /**
   * 마이페이지 알림함의 모두 읽음·모두 삭제·개별 삭제 버튼이 잠긴다.
   *
   * @scenario case=notification_abilities_false
   *
   * @effects mypage_notification_buttons_locked
   */
  it('마이페이지 알림함 버튼이 잠기고 행 클릭 읽음 호출이 막힌다', () => {
    const denied = { userNotifications: { data: { data: [{ id: 1 }], abilities: { can_update: false, can_delete: false } } }, notification: { id: 1, read_at: null } };
    const buttons = collect(notificationList, (n) => n.name === 'Button' && typeof n.props?.disabled === 'string' && n.props.disabled.includes('userNotifications'));
    expect(buttons.length).toBe(3);
    buttons.forEach((b) => expect(evaluate(b.props.disabled, denied)).toBe(true));
    const patch = apiCalls(notificationList, (t, m) => m === 'PATCH')[0];
    expect(evaluate(patch.if, denied)).toBe(false);
  });
});

describe('G28 공개 페이지 관리자 링크', () => {
  /**
   * [편집]·버전 배지는 can_update, [페이지 만들기]는 404 응답의 can_create 로 연다.
   *
   * @scenario case=page_links_by_abilities
   *
   * @effects page_admin_links_follow_abilities
   */
  it('is_admin 대신 abilities 를 본다', () => {
    const text = JSON.stringify(pageShow);
    expect(text).not.toContain('currentUser?.is_admin');
    const edit = collect(pageShow, (n) => n.id === 'page_admin_edit_button')[0];
    expect(evaluate(edit.if, { page: { data: { abilities: { can_update: false } } } })).toBe(false);
    expect(evaluate(edit.if, { page: { data: { abilities: { can_update: true } } } })).toBe(true);
    const create = collect(pageShow, (n) => n.id === 'page_create_button')[0];
    expect(evaluate(create.if, { _local: {} })).toBe(false);
    expect(evaluate(create.if, { _local: { pageCanCreate: true } })).toBe(true);
    const handler = collect(pageShow, (n) => n.handler === 'setState' && n.params?.pageCanCreate)[0];
    expect(evaluate(handler.params.pageCanCreate, { error: { errors: { abilities: { can_create: true } } } })).toBe(true);
    expect(evaluate(handler.params.pageCanCreate, { error: {} })).toBe(false);
  });
});

describe('게시판 사용자 화면 (#5·#6·#9~#13)', () => {
  /**
   * 비로그인은 글쓰기 버튼이 열려 로그인 유도로, 로그인했는데 권한이 없으면 잠긴다.
   *
   * @scenario case=write_button_guest
   *
   * @effects write_button_guides_guest_to_login
   */
  it('#5 글쓰기 버튼', () => {
    const btn = collect(writeButton, (n) => n.name === 'Button')[0];
    const scope = (uuid: string | undefined, canWrite: boolean) => ({ _global: { currentUser: uuid ? { uuid } : null }, posts: { data: { abilities: { can_write: canWrite } } } });
    expect(evaluate(btn.props.disabled, scope(undefined, false))).toBe(false);
    expect(evaluate(btn.props.disabled, scope('u1', false))).toBe(true);
    expect(evaluate(btn.props.disabled, scope('u1', true))).toBe(false);
  });

  /**
   * 카드·갤러리 목록의 비밀글 잠금이 컬렉션 비밀글 열람 권한을 본다.
   *
   * @scenario case=secret_overlay_reader
   *
   * @effects secret_overlay_follows_collection_ability
   */
  it('#6 비밀글 잠금 오버레이', () => {
    for (const tree of [cardIndex, galleryIndex]) {
      expect(JSON.stringify(tree)).not.toContain('post?.abilities?.can_view');
      const overlay = collect(tree, (n) => typeof n.if === 'string' && n.if.includes('post?.is_secret') && n.if.includes('can_read_secret'))[0];
      expect(overlay, '잠금 오버레이가 없습니다').toBeTruthy();
      expect(evaluate(overlay.if, { post: { is_secret: true }, posts: { data: { abilities: { can_read_secret: true } } } })).toBe(false);
      expect(evaluate(overlay.if, { post: { is_secret: true }, posts: { data: { abilities: { can_read_secret: false } } } })).toBe(true);
    }
  });

  /**
   * 홈·인기글·비밀번호 확인 창이 선택 인증으로 토큰을 보낸다.
   *
   * @scenario case=optional_auth_sources
   *
   * @effects optional_auth_sends_member_token
   */
  it('#9·#10 선택 인증', () => {
    const homeSources = ((home as Node).data_sources as Node[]).filter((d) => ['stats', 'recent_posts', 'popular_boards', 'home_boards'].includes(d.id));
    expect(homeSources.length).toBe(4);
    homeSources.forEach((d) => expect(d.auth_mode, d.id).toBe('optional'));
    const popularSource = ((popular as Node).data_sources as Node[]).find((d) => d.id === 'popularPosts')!;
    expect(popularSource.auth_mode).toBe('optional');
    for (const modal of [formPasswordModal, showPasswordModal]) {
      const call = collect(modal, (n) => n.handler === 'apiCall')[0];
      expect(call.auth_mode).toBe('optional');
    }
  });

  /**
   * 글쓰기 폼이 401 이면 로그인으로 안내한다.
   *
   * @scenario case=form_unauthenticated
   *
   * @effects form_401_redirects_to_login
   */
  it('#11 글쓰기 폼 401', () => {
    const meta = ((boardForm as Node).data_sources as Node[]).find((d) => d.id === 'form_meta')!;
    expect(JSON.stringify(meta.errorHandling ?? meta)).toContain('redirectToLoginWithReturn');
  });

  /**
   * 답글 비밀글 안내는 서버가 원문을 가렸을 때(content null)만 보인다.
   *
   * @scenario case=secret_reply_viewer
   *
   * @effects secret_reply_body_for_authorized
   */
  it('#12 답글 비밀글', () => {
    const notice = collect(replySection, (n) => n.text === '$t:board.posts.detail.reply_secret_content')[0];
    expect(evaluate(notice.if, { reply: { is_secret: true, content: '본문', status: 'published' } })).toBe(false);
    expect(evaluate(notice.if, { reply: { is_secret: true, content: null, status: 'published' } })).toBe(true);
  });

  /**
   * 첨부 권한 없음 카드는 비회원을 로그인으로 안내한다.
   *
   * @scenario case=attachment_guest
   *
   * @effects attachment_guest_login_redirect
   */
  it('#13 첨부 권한 없음 카드', () => {
    const redirect = collect(postAttachments, (n) => n.handler === 'redirectToLoginWithReturn')[0];
    expect(redirect, '로그인 유도가 없습니다').toBeTruthy();
    expect(evaluate(redirect.if, { _global: { currentUser: null } })).toBe(true);
    expect(evaluate(redirect.if, { _global: { currentUser: { uuid: 'u1' } } })).toBe(false);
  });
});
