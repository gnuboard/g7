/**
 * @file user-base-mobile-drawer-escape.test.ts
 * @description 모바일 메뉴 서랍 Esc 닫기 (7.1.0 N O-N6)
 *
 * 결함: 모바일에서 [메뉴] 로 연 서랍이 Esc 로 닫히지 않았다(닫기 단추·바깥 누름만 됨). 서랍을 연 직후 초점은
 * 서랍 밖 [메뉴] 단추에 있으므로 서랍에만 키 처리를 달면 그 순간의 Esc 를 못 받는다 — 화면 뿌리(`user_layout_root`)
 * 에서 Esc 를 받아, 서랍이 열려 있을 때만 닫는다.
 *
 * 닫힌 서랍은 화면 밖으로 밀어 두기만 해서 [메뉴] 다음 Tab 이 보이지 않는 서랍의 [닫기]·메뉴로 들어갔고 화면 읽기
 * 프로그램도 닫힌 서랍 안을 읽었다(2026-10-07 재실측). 닫히면 `invisible` 로 숨기되, 밀려 나가는 움직임이 끝난 뒤
 * 숨도록 visibility 도 함께 전환한다(`transition-all`).
 *
 * 테스트 대상: layouts/_user_base.json
 */

import { describe, it, expect } from 'vitest';

type Node = Record<string, any>;

/** 레이아웃 JSON 에서 조건에 맞는 첫 객체를 찾는다. */
function find(node: unknown, pred: (n: Node) => boolean): Node | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = find(child, pred);
      if (hit) return hit;
    }
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) return node as Node;
    for (const child of Object.values(node as Node)) {
      const hit = find(child, pred);
      if (hit) return hit;
    }
  }

  return null;
}

describe('사용자 공통 레이아웃 — 모바일 서랍 Esc', () => {
  const base = require('../../layouts/_user_base.json');

  /**
   * @scenario surface=user_mobile_drawer
   * @effects mobile_drawer_closes_on_escape
   */
  it('화면 뿌리가 Esc 를 받아 서랍이 열려 있을 때만 닫는다', () => {
    const root = find(base, (n) => n.id === 'user_layout_root');
    expect(root).not.toBeNull();
    const esc = (root!.actions ?? []).find((a: Node) => a.type === 'keydown' && a.key === 'Escape');
    expect(esc).toBeDefined();
    const raw = JSON.stringify(esc);
    expect(raw).toContain('_global.mobileMenuOpen');
    expect(raw).toContain('"mobileMenuOpen":false');
  });

  /**
   * @scenario surface=user_mobile_drawer
   * @effects closed_mobile_drawer_is_hidden_from_keyboard_and_screen_readers
   */
  it('닫힌 서랍은 invisible 로 숨겨 Tab·화면 읽기에서 빠진다', () => {
    const drawer = find(base, (n) => n.id === 'mobile_nav_drawer');
    expect(drawer).not.toBeNull();
    const raw = JSON.stringify(drawer!.responsive ?? {});
    expect(raw).toContain("'translate-x-full invisible'");
    expect(raw).toContain("'translate-x-0 visible'");
    // visibility 도 전환에 넣어야 닫힐 때 밀려 나가는 움직임이 끝난 뒤 숨는다
    expect(raw).toContain('transition-all');
    expect(raw).not.toContain('transition-transform');
  });
});
