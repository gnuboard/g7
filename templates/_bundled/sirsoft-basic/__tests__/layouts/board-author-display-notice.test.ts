/**
 * @file board-author-display-notice.test.ts
 * @description 게시판 글쓰기 화면이 회원에게 「이 게시판에서 보이는 이름」 한 줄을 알리는지
 *
 * 게시판마다 작성자 표시 이름의 종류(닉네임, 가린 이름 등)가 다를 수 있어, 회원이 새 글을 쓰기 전에
 * 자기 글에 어떤 이름이 붙는지 미리 알린다. 글쓰기 메타(form_meta)의 `author_display_name` 과
 * `author_display_kind_label` 을 그대로 쓴다(화면은 이름을 계산하지 않는다).
 * 비회원(값이 null), 수정 화면(작성자 정보 줄이 따로 있다), 값을 주지 않는 옛 서버에서는 그리지 않는다.
 * 식은 실제 앱과 같은 식 엔진으로 계산하고, 문구는 실제 다국어 파일에서 읽는다.
 *
 * @scenario-manifest tests/scenarios/board-author-display-notice.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';

import postForm from '../../layouts/partials/board/form/_post_form.json';
import basicForm from '../../layouts/partials/board/types/basic/form.json';
import basicShow from '../../layouts/partials/board/types/basic/show.json';
import koBoard from '../../lang/partial/ko/board.json';
import enBoard from '../../lang/partial/en/board.json';

type Node = Record<string, any>;

const engine = new DataBindingEngine();

/** 식({{ }})을 식 엔진으로 계산한다 */
function evaluate(expression: string, context: Record<string, unknown>): any {
  expect(expression.startsWith('{{') && expression.endsWith('}}')).toBe(true);
  return engine.evaluateExpression(expression.slice(2, -2), context);
}

/** 자식 노드(children)만 따라 내려가며 모은다 */
function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 실제 번역 엔진에 이 템플릿의 게시판 문구를 싣는다 */
const translations = TranslationEngine.getInstance();
(translations as any).translations.set('sirsoft-basic:ko', { board: koBoard });
(translations as any).translations.set('sirsoft-basic:en', { board: enBoard });

/** 안내 문구를 주어진 언어의 실제 문구로 푼다 */
function textIn(locale: 'ko' | 'en', context: Record<string, unknown>): string {
  return evaluate(line.text, { ...context, $templateId: 'sirsoft-basic', $locale: locale });
}

const nodes = collect(postForm as Node);
const notice = nodes.find((node) => node.id === 'board_author_display_notice')!;
const line = (notice.children as Node[]).find((node) => node.name === 'Span')!;

const guest = { currentUser: [] };
const member = { currentUser: { uuid: 'u-1', name: '홍길동', nickname: '길동이', display_name: '길동이' } };

/** 글쓰기 메타를 받은 화면의 문맥 */
function screen(meta: Record<string, unknown>, _global: Record<string, unknown> = member, route: Record<string, unknown> = { slug: 'free' }, query: Record<string, unknown> = {}) {
  return { form_meta: { data: { board: { name: '자유게시판' }, ...meta } }, form_data: { data: {} }, route, query, _global, _local: {} };
}

const nickname = { author_display_name: '길동이', author_display_kind: 'nickname', author_display_kind_label: '닉네임' };

describe('게시판 글쓰기 — 이 게시판에서 보이는 이름 안내', () => {
  /**
   * @scenario case=member_new_post
   * @effects notice_shown_to_member_writing_new_post
   */
  it('로그인한 회원이 새 글(답글 포함)을 쓸 때 보이는 이름과 종류 이름을 한 줄로 알린다', () => {
    expect(notice).toBeDefined();
    const ctx = screen(nickname);
    expect(evaluate(notice.if, ctx)).toBe(true);
    expect(textIn('ko', ctx)).toBe('이 게시판에서 보이는 이름: 길동이 (닉네임)');
    expect(textIn('en', ctx)).toBe('Name shown on this board: 길동이 (닉네임)');
    // 답글 쓰기(부모 글 번호가 질의에 있다)도 새 글이다
    expect(evaluate(notice.if, screen(nickname, member, { slug: 'free' }, { parent_id: '3' }))).toBe(true);
  });

  /**
   * @scenario case=member_new_post
   * @effects notice_omits_kind_when_label_missing
   */
  it('종류 이름이 없으면 괄호 없이 이름만 알린다', () => {
    for (const label of [null, undefined, '']) {
      const ctx = screen({ author_display_name: '홍*동', author_display_kind: 'masked_name', author_display_kind_label: label });
      expect(evaluate(notice.if, ctx)).toBe(true);
      expect(textIn('ko', ctx)).toBe('이 게시판에서 보이는 이름: 홍*동');
      expect(textIn('en', ctx)).toBe('Name shown on this board: 홍*동');
    }
  });

  /**
   * @scenario case=hidden
   * @effects notice_hidden_for_guest_edit_and_missing_name
   */
  it('비회원, 수정 화면, 보이는 이름을 받지 못한 경우에는 그리지 않는다', () => {
    // 비회원 — 서버가 세 값을 모두 null 로 준다
    const nulls = { author_display_name: null, author_display_kind: null, author_display_kind_label: null };
    expect(!!evaluate(notice.if, screen(nulls, guest))).toBe(false);
    expect(!!evaluate(notice.if, screen(nulls, { currentUser: null }))).toBe(false);
    // 비회원인데 값이 남아 있어도(로그아웃 직후 다시 받기 전) 그리지 않는다
    expect(!!evaluate(notice.if, screen(nickname, guest))).toBe(false);
    // 수정 화면 — 작성자 정보 줄이 따로 있다
    expect(!!evaluate(notice.if, screen(nickname, member, { slug: 'free', id: '12' }))).toBe(false);
    // 값을 주지 않는 응답(옛 서버), 빈 이름, 메타를 받기 전
    expect(!!evaluate(notice.if, screen({}, member))).toBe(false);
    expect(!!evaluate(notice.if, screen({ ...nickname, author_display_name: '' }, member))).toBe(false);
    expect(!!evaluate(notice.if, { form_meta: undefined, route: { slug: 'free' }, _global: member, _local: {} })).toBe(false);
  });

  /**
   * @scenario case=placement
   * @effects notice_sits_in_author_slot_with_safe_markup
   */
  it('수정 화면의 작성자 정보 줄과 같은 자리(폼 맨 위, 글 옵션 앞)에 있고, 긴 이름이 넘치지 않으며 다크 짝이 있다', () => {
    const siblings = (postForm as Node).children as Node[];
    const editAuthor = siblings.find((node) => String(node.if ?? '').includes('form_data?.data?.author'))!;
    const fixedBox = siblings.find((node) => node.id === 'board_guest_fixed_fields')!;
    expect(siblings.indexOf(notice)).toBe(siblings.indexOf(editAuthor) + 1);
    expect(siblings.indexOf(notice)).toBeLessThan(siblings.indexOf(fixedBox));
    // 수정 화면의 작성자 줄과 이 안내는 함께 그려지지 않는다
    const editing = screen(nickname, member, { slug: 'free', id: '12' });
    expect(!!evaluate(notice.if, editing)).toBe(false);

    expect(line.props.className).toContain('min-w-0');
    expect(line.props.className).toContain('wrap-anywhere');
    for (const node of collect(notice)) {
      const className = String(node.props?.className ?? '');
      if (/\btext-gray-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
      if (/\bbg-gray-\d+/.test(className)) expect(className).toMatch(/dark:bg-/);
      if (/\bborder-gray-\d+/.test(className)) expect(className).toMatch(/dark:border-/);
    }
    // 문구 키가 ko 와 en 에 모두 있다
    for (const board of [koBoard, enBoard] as Array<Record<string, any>>) {
      expect(Object.keys(board.author_display).sort()).toEqual(['notice', 'notice_without_kind']);
      expect(board.author_display.notice).toContain('{{name}}');
      expect(board.author_display.notice).toContain('{{kind}}');
      expect(board.author_display.notice_without_kind).toContain('{{name}}');
      expect(board.author_display.notice_without_kind).not.toContain('{{kind}}');
    }
  });
});

describe('게시판 글쓰기와 상세 — 이름이 없는 작성자(계정이 지워진 회원)', () => {
  const inKo = (text: string, context: Record<string, unknown>) => evaluate(text, { ...context, $templateId: 'sirsoft-basic', $locale: 'ko' });

  /**
   * @scenario case=missing_author_name
   * @effects author_name_falls_back_to_unknown_when_missing
   */
  it('수정 화면 머리의 작성자 배지와 상세 화면의 원글 작성자는 이름이 없으면 빈칸 대신 「알 수 없음」을 그린다', () => {
    const editBadge = collect(basicForm as Node).find((node) => typeof node.text === 'string' && node.text.includes('form_meta?.data?.author?.name'))!;
    expect(editBadge).toBeDefined();
    expect(inKo(editBadge.text, { form_meta: { data: { author: { name: '길동이' } } } })).toBe('길동이');
    expect(inKo(editBadge.text, { form_meta: { data: { author: { name: null } } } })).toBe('알 수 없음');

    const parentAuthor = collect(basicShow as Node).find((node) => typeof node.text === 'string' && node.text.includes('post?.data?.parent?.author?.name'))!;
    expect(parentAuthor).toBeDefined();
    expect(inKo(parentAuthor.text, { post: { data: { parent: { author: { name: '길동이' } } } } })).toBe('길동이');
    expect(inKo(parentAuthor.text, { post: { data: { parent: { author: { name: null } } } } })).toBe('알 수 없음');
    expect(inKo(parentAuthor.text, { post: { data: { parent: { author: null } } } })).toBe('알 수 없음');
  });
});
