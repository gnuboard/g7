/**
 * @file display-name-header.test.ts
 * @description 헤더, 모바일 드로어, 댓글 입력 칸의 내 아바타가 표시 이름을 쓰는지
 *
 * 로그인 사용자 응답은 실명(`name`)과 닉네임(`nickname`)의 원본을 그대로 두고, 그 화면에서 보일 이름을 `display_name` 으로 따로 싣는다.
 * 실명을 표시용으로 쓰던 자리(데스크톱 헤더, 모바일 드로어의 사용자 카드, 댓글과 답글 입력 칸의 내 아바타)는
 * `display_name ?? name ?? …` 순서로 읽는다. 이름 항목을 쓰지 않는 사이트는 `name` 이 null 이어도 빈칸이나 「null」 글자가 나오지 않아야 한다.
 * 실명을 써야 하는 자리(주문서의 주문자 기본값, 내 정보의 이름 칸)는 그대로 둔다.
 *
 * 식은 실제 엔진의 식 평가기로도 계산한다.
 *
 * @scenario-manifest tests/scenarios/display-name-header.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import userBase from '../../layouts/_user_base.json';
import commentInput from '../../layouts/partials/board/show/_comment_input.json';
import commentItem from '../../layouts/partials/board/show/_comment_item.json';

type Node = Record<string, any>;

/** 트리 전체(자식, 슬롯, 속성 안의 노드 포함)를 훑어 조건에 맞는 노드를 모은다. */
function collect(tree: unknown, match: (node: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(tree)) {
    tree.forEach((child) => collect(child, match, out));
    return out;
  }
  if (tree && typeof tree === 'object') {
    const node = tree as Node;
    if (match(node)) out.push(node);
    Object.values(node).forEach((value) => collect(value, match, out));
  }
  return out;
}

/** 표현식({{ }}) 하나를 엔진의 식 평가기로 계산한다. */
function engineExpression(expression: string, context: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

const ROOT = path.resolve(__dirname, '../..');

/** 표시 이름과 실명이 다른 회원, 옛 응답(표시 이름 없음), 이름 항목을 쓰지 않는 사이트의 회원 */
const nicknameMember = { uuid: 'u-1', name: '홍길동', nickname: '길동이', display_name: '길동이', avatar: null };
const legacyMember = { uuid: 'u-2', name: '홍길동', avatar: null };
const namelessMember = { uuid: 'u-3', name: null, nickname: null, display_name: 'gil***', avatar: null };
const emptyMember = { uuid: 'u-4', name: null, avatar: null };

describe('표시 이름 — 헤더와 모바일 드로어', () => {
  const drawerName = collect(userBase, (node) => node.id === 'mobile_drawer_user_name')[0];
  const drawerCard = collect(userBase, (node) => Array.isArray(node.children) && node.children.some((child: Node) => collect(child, (n) => n.id === 'mobile_drawer_user_name').length > 0) && node.children.some((child: Node) => child.name === 'Avatar'))[0];

  /**
   * @scenario place=desktop_header
   * @effects header_receives_current_user_with_display_name
   */
  it('데스크톱 헤더는 로그인 사용자 객체를 통째로 받는다(표시 이름은 컴포넌트가 고른다)', () => {
    const header = collect(userBase, (node) => node.id === 'desktop_header')[0];
    expect(header.name).toBe('Header');
    expect(header.props.user).toBe('{{_global.currentUser}}');
    // 로그인 사용자는 current_user 소스가 전역 currentUser 로 올린다 — 저장 뒤 이 소스를 다시 받으면 헤더가 따라 바뀐다
    const source = (userBase as Node).data_sources.find((item: Node) => item.id === 'current_user');
    expect(source.initGlobal).toBe('currentUser');
    expect(source.endpoint).toBe('/api/auth/user');
  });

  /**
   * @scenario place=mobile_drawer
   * @effects drawer_card_shows_display_name_before_real_name
   */
  it('모바일 드로어의 사용자 카드는 표시 이름을 먼저, 없으면 실명을 보인다', () => {
    expect(drawerName.text).toBe("{{_global.currentUser?.display_name ?? _global.currentUser?.name ?? '?'}}");
    expect(engineExpression(drawerName.text, { _global: { currentUser: nicknameMember } })).toBe('길동이');
    expect(engineExpression(drawerName.text, { _global: { currentUser: legacyMember } })).toBe('홍길동');

    const avatar = drawerCard.children.find((child: Node) => child.name === 'Avatar');
    expect(avatar.props.name).toBe("{{_global.currentUser?.display_name ?? _global.currentUser?.name ?? '?'}}");
    expect(engineExpression(avatar.props.name, { _global: { currentUser: nicknameMember } })).toBe('길동이');
  });

  /**
   * @scenario place=mobile_drawer
   * @effects drawer_card_is_null_safe
   */
  it('이름이 null 이어도(이름 항목을 쓰지 않는 사이트) 빈칸이나 null 글자가 나오지 않는다', () => {
    expect(engineExpression(drawerName.text, { _global: { currentUser: namelessMember } })).toBe('gil***');
    expect(engineExpression(drawerName.text, { _global: { currentUser: emptyMember } })).toBe('?');
    // 로그인 사용자를 아직 못 받았을 때도 터지지 않는다
    expect(engineExpression(drawerName.text, { _global: {} })).toBe('?');
  });
});

describe('표시 이름 — 댓글 입력 칸의 내 아바타', () => {
  const inputAvatar = collect(commentInput, (node) => node.name === 'Avatar')[0];
  const replyAvatar = collect(commentItem, (node) => node.name === 'Avatar' && String(node.if ?? '').includes('_global.currentUser?.uuid'))[0];

  /**
   * @scenario place=comment_input
   * @effects comment_avatar_uses_display_name_then_real_name_then_guest_name
   */
  it('댓글 입력 칸의 아바타는 표시 이름, 실명, 비회원이 적은 이름 순서로 읽는다', () => {
    expect(inputAvatar.props.name).toBe("{{_global.currentUser?.display_name ?? _global.currentUser?.name ?? _local.guest_comment_author_name ?? ''}}");
    const nameOf = (currentUser: unknown, guest?: string) => engineExpression(inputAvatar.props.name, { _global: { currentUser }, _local: { guest_comment_author_name: guest } });
    expect(nameOf(nicknameMember)).toBe('길동이');
    expect(nameOf(legacyMember)).toBe('홍길동');
    expect(nameOf(namelessMember)).toBe('gil***');
    // 비회원은 입력한 작성자 이름, 아무것도 없으면 빈 글자
    expect(nameOf(undefined, '손님')).toBe('손님');
    expect(nameOf(undefined)).toBe('');
    expect(nameOf(emptyMember)).toBe('');
  });

  /**
   * @scenario place=reply_input
   * @effects reply_avatar_uses_display_name_and_is_null_safe
   */
  it('답글 입력 칸의 아바타도 표시 이름을 먼저 읽고, 이름이 없으면 빈 글자다', () => {
    expect(replyAvatar.props.name).toBe("{{_global.currentUser?.display_name ?? _global.currentUser?.name ?? ''}}");
    const nameOf = (currentUser: unknown) => engineExpression(replyAvatar.props.name, { _global: { currentUser } });
    expect(nameOf(nicknameMember)).toBe('길동이');
    expect(nameOf(legacyMember)).toBe('홍길동');
    expect(nameOf(emptyMember)).toBe('');
  });
});

describe('표시 이름 — 실명을 유지하는 자리와 번역', () => {
  /**
   * @scenario place=real_name_kept
   * @effects orderer_default_and_profile_name_keep_real_name
   */
  it('주문서의 주문자 기본값과 내 정보의 이름 자리는 실명을 그대로 쓴다', () => {
    const read = (relative: string) => fs.readFileSync(path.join(ROOT, 'layouts', relative), 'utf-8');
    for (const file of ['shop/checkout.g7.html', 'partials/shop/_checkout_orderer.g7.html', 'partials/shop/_checkout_shipping.g7.html']) {
      const text = read(file);
      expect(text, file).toContain('_global.currentUser?.name');
      expect(text, file).not.toContain('currentUser?.display_name');
    }
    // 내 정보 수정의 아바타 대체 글자는 본인 실명의 첫 글자 그대로다
    expect(read('partials/mypage/profile/_edit.g7.html')).toContain("user.data?.name?.charAt(0) ?? 'U'");
  });

  /**
   * @scenario place=translations
   * @effects display_name_keys_exist_in_ko_and_en
   */
  it('표시 이름 선택 칸의 번역 키가 ko 와 en 에 같은 모양으로 있다', () => {
    const read = (locale: string) => JSON.parse(fs.readFileSync(path.join(ROOT, 'lang/partial', locale, 'member.json'), 'utf-8')).display_name;
    const ko = read('ko');
    const en = read('en');
    expect(Object.keys(en).sort()).toEqual(Object.keys(ko).sort());
    for (const key of ['label', 'option_default', 'preview', 'preview_sample', 'requires_nickname', 'reselect']) {
      expect(typeof ko[key], key).toBe('string');
      expect(typeof en[key], key).toBe('string');
    }
    // 문구가 쓰는 인자 이름이 두 언어에서 같다
    const params = (text: string) => [...text.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort();
    for (const key of Object.keys(ko)) {
      expect(params(en[key]), key).toEqual(params(ko[key]));
    }
  });
});
