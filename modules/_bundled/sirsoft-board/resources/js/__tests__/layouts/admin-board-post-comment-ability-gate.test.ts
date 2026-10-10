// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file admin-board-post-comment-ability-gate.test.ts
 * @description 게시판 관리자 게시글·댓글 화면 abilities 게이트 (7.1.0 abilities 게이트 최종 점검 board-admin-b)
 *
 * - 댓글 점 메뉴: 키 `can_write_comments`(댓글 응답에 없는 키) → `can_write`, 수정은 쓰기 AND (본인 OR 관리)(#1·#3)
 * - 글 점 메뉴 수정: 수정 API 가 글쓰기 권한을 요구하므로 쓰기 AND (본인 OR 관리)(#2)
 * - 글 수정 폼: form-meta 의 can_modify(저장과 같은 판정)로 남의 글은 읽기 전용(#4), html-editor 에 readOnly 도 전달(#5)
 * - 첨부 업로더: 업로드 권한(can_upload)으로 잠금 + 안내(#6)
 * - 첨부 내려받기: 관리자 다운로드 라우트 + can_download(#7)
 * - 신고 목록 체크박스: 관리 권한(#9)
 */

import { describe, it, expect } from 'vitest';
import commentPartial from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';
import postForm from '../../../layouts/admin/admin_board_post_form.json';
import formFields from '../../../layouts/admin/partials/admin_board_post_form/_form_fields.json';
import attachments from '../../../layouts/admin/partials/admin_board_post_form/_attachments.json';
import reportsIndex from '../../../layouts/admin/admin_board_reports_index.json';

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

/** 점 메뉴(ActionMenu) items 를 평가해 id → disabled 맵을 돌려준다 */
function menuDisabled(tree: unknown, scope: Record<string, unknown>): Record<string, boolean> {
  const menu = collect(tree, (n) => n.name === 'ActionMenu' && String(n.props?.items ?? '').includes("id: 'edit'"))[0];
  expect(menu, 'ActionMenu 가 없습니다').toBeTruthy();
  const items = evaluate(menu.props.items, scope) as Array<{ id: string; disabled: boolean }>;
  return Object.fromEntries(items.map((i) => [i.id, i.disabled]));
}

describe('댓글 점 메뉴 (#1·#3)', () => {
  const scope = (abilities: Record<string, boolean>, isAuthor: boolean) => ({
    comment: { id: 1, status: 'published', is_author: isAuthor, abilities },
    post: { data: {} },
  });

  /**
   * 댓글 응답 키(can_write)로 판정한다 — 작성자 본인은 쓰기 권한만으로 수정·삭제가 열린다.
   *
   * @scenario case=comment_owner_write_only
   *
   * @effects own_comment_editable_with_comments_write
   */
  it('본인 + can_write 면 수정·삭제가 열린다', () => {
    const d = menuDisabled(commentPartial, scope({ can_write: true, can_manage: false }, true));
    expect(d.edit).toBe(false);
    expect(d.delete).toBe(false);
  });

  /**
   * 관리 권한만 있고 쓰기 권한이 없으면 수정은 잠기고(수정 API 가 쓰기 권한을 요구) 삭제는 열린다.
   *
   * @scenario case=comment_manage_only
   *
   * @effects comment_edit_requires_write
   */
  it('관리만 있고 쓰기가 없으면 수정은 잠기고 삭제는 열린다', () => {
    const d = menuDisabled(commentPartial, scope({ can_write: false, can_manage: true }, false));
    expect(d.edit).toBe(true);
    expect(d.delete).toBe(false);
    // 댓글 응답 abilities 에는 can_write_comments 가 없다(글 응답 키) — 댓글 판정에서 그 키를 읽지 않는다
    expect(JSON.stringify(commentPartial)).not.toContain('comment?.abilities?.can_write_comments');
  });
});

describe('글 점 메뉴 수정 (#2)', () => {
  /**
   * 관리 권한만 있고 글쓰기 권한이 없으면 수정이 잠긴다.
   *
   * @scenario case=post_manage_only
   *
   * @effects post_edit_requires_write
   */
  it('관리만 있고 쓰기가 없으면 수정이 잠긴다', () => {
    const s = (abilities: Record<string, boolean>, isAuthor: boolean) => ({ post: { data: { status: 'published', is_author: isAuthor, abilities } } });
    expect(menuDisabled(postCard, s({ can_write: false, can_manage: true }, false)).edit).toBe(true);
    expect(menuDisabled(postCard, s({ can_write: true, can_manage: true }, false)).edit).toBe(false);
    expect(menuDisabled(postCard, s({ can_write: true, can_manage: false }, true)).edit).toBe(false);
    expect(menuDisabled(postCard, s({ can_write: true, can_manage: false }, false)).edit).toBe(true);
  });
});

describe('글 수정 폼 (#4·#5·#6)', () => {
  /**
   * 남의 글(can_modify=false)은 쓰기 권한이 있어도 읽기 전용이다.
   *
   * @scenario case=form_meta_can_modify
   *
   * @effects form_read_only_for_others_post
   */
  it('isReadOnly 가 can_modify 를 본다', () => {
    const expr = (postForm as Node).computed.isReadOnly;
    const meta = (canWrite: boolean, canModify: boolean | undefined) => ({ route: { id: 5 }, form_meta: { data: { can_modify: canModify, board: { user_abilities: { can_write: canWrite } } } } });
    expect(evaluate(expr, meta(true, false))).toBe(true);
    expect(evaluate(expr, meta(true, undefined))).toBe(true);
    expect(evaluate(expr, meta(true, true))).toBe(false);
    expect(evaluate(expr, meta(false, true))).toBe(true);
  });

  /**
   * 본문 편집기는 readOnly 로도 잠긴다.
   *
   * @scenario case=form_meta_can_modify
   *
   * @effects editor_read_only_prop
   */
  it('html-editor 에 readOnly 가 함께 전달된다', () => {
    const editor = collect(formFields, (n) => n.name === 'HtmlEditor')[0];
    expect(editor.props.readOnly).toBe('{{_computed.isReadOnly}}');
  });

  /**
   * 공지 체크박스는 서버 공지 판정과 같은 can_write_notice 로 잠긴다 — 관리 권한(can_manage) 없이 공지 등록 권한만
   * 가진 관리자도 열리고(B7-2), 둘 다 없으면 잠긴다. 비밀글이 꺼진 게시판에서도 옵션 구획이 보인다.
   *
   * @scenario case=form_meta_can_write_notice
   *
   * @effects notice_checkbox_follows_can_write_notice
   */
  it('공지 체크박스가 can_write_notice 를 본다', () => {
    const scope = (abilities: Record<string, boolean>) => ({
      _computed: { isReadOnly: false },
      _local: { form: {} },
      form_meta: { data: { board: { secret_mode: 'disabled', user_abilities: abilities } } },
    });
    const checkbox = collect(formFields, (n) => n.name === 'Input' && n.props?.name === 'is_notice')[0];
    const card = collect(formFields, (n) => n.id === 'notice_card')[0];
    const section = collect(formFields, (n) => n.id === 'post_options_section')[0];

    const noticeOnly = scope({ can_manage: false, can_write_notice: true });
    expect(evaluate(checkbox.props.disabled, noticeOnly)).toBe(false);
    expect(evaluate(card.props.disabled, noticeOnly)).toBe(false);
    expect(evaluate(section.if, noticeOnly)).toBe(true);

    const writerOnly = scope({ can_manage: false, can_write_notice: false });
    expect(evaluate(checkbox.props.disabled, writerOnly)).toBe(true);
    expect(evaluate(card.props.disabled, writerOnly)).toBe(true);
    expect(evaluate(section.if, writerOnly)).toBe(false);
  });

  /**
   * 업로드 권한이 없으면 업로더가 잠기고 안내가 보인다.
   *
   * @scenario case=upload_without_permission
   *
   * @effects uploader_locked_without_upload
   */
  it('can_upload=false 면 업로더 잠금 + 안내', () => {
    const scope = { _computed: { isReadOnly: false }, form_meta: { data: { board: { user_abilities: { can_upload: false } } } } };
    const uploader = collect(attachments, (n) => n.name === 'FileUploader')[0];
    expect(evaluate(uploader.props.disabled, scope)).toBe(true);
    const hint = collect(attachments, (n) => n.id === 'attachments_no_permission')[0];
    expect(evaluate(hint.if, scope)).toBe(true);
  });
});

describe('첨부 내려받기 (#7)', () => {
  /**
   * 관리자 다운로드 라우트를 쓰고 can_download 가 없으면 실행하지 않는다.
   *
   * @scenario case=download_without_permission
   *
   * @effects download_uses_admin_route_and_gate
   */
  it('글·답글 카드의 내려받기 액션이 관리자 라우트 + can_download 게이트', () => {
    for (const tree of [postCard, replyCard]) {
      const action = collect(tree, (n) => n.handler === 'sirsoft-board.downloadAttachment')[0];
      expect(action, '내려받기 액션이 없습니다').toBeTruthy();
      expect(evaluate(action.if, { post: { data: { abilities: { can_download: false } } } })).toBe(false);
      expect(evaluate(action.if, { post: { data: { abilities: { can_download: true } } } })).toBe(true);
      const url = evaluate(action.params.url, { route: { slug: 'free' }, attachment: { hash: 'abc' } });
      expect(url).toBe('/api/modules/sirsoft-board/admin/board/free/attachments/download/abc');
    }
  });
});

describe('신고 목록 체크박스 (#9)', () => {
  /**
   * 관리 권한이 없으면 행 선택 체크박스를 그리지 않는다.
   *
   * @scenario case=reports_selectable
   *
   * @effects reports_selectable_by_manage
   */
  it('selectable 이 can_manage 를 본다', () => {
    const grid = collect(reportsIndex, (n) => n.name === 'DataGrid')[0];
    expect(evaluate(grid.props.selectable, { reports: { data: { abilities: { can_manage: false } } } })).toBe(false);
    expect(evaluate(grid.props.selectable, { reports: { data: { abilities: { can_manage: true } } } })).toBe(true);
  });
});
