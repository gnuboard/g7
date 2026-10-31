/**
 * @file auth-password-rules-binding.test.ts
 * @description 회원가입·비밀번호 재설정 화면의 비밀번호 규칙 안내가 보안 환경설정 값을 따르는지 보는 회귀 테스트 (#736)
 *
 * 배경: 두 화면이 「8자 이상」을 박아 두어 운영자가 최소 길이를 올려도 옛 규칙을 안내했다. 이제 PasswordInput 의
 * `enableRules` 와 자리표시 숫자를 `_global.settings.security` 에서 계산하고, 화면이 미리 확인할 수 없는 규칙
 * (재사용·아이디·반복·연속)은 켜진 것만 작은 글로 알린다. 재설정 화면은 유효한 재설정 링크가 있어야 폼이 열려
 * 브라우저 실측에서 덮지 못하므로(#736 S2 §8.3 T10) 바인딩 식을 실제 값으로 평가해 잠근다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';

import registerForm from '../../layouts/partials/auth/_register_form.json';
import resetPassword from '../../layouts/auth/reset_password.json';

type Node = { name?: string; if?: string; props?: Record<string, any>; children?: Node[] | string; text?: string };

function flatten(node: any): Node[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots && typeof node.slots === 'object' ? Object.values(node.slots).flatMap(flatten) : [];
  return [node, ...children, ...slots];
}

/** `{{식}}` 바인딩을 주어진 `_global` 로 평가한다. */
function evaluate(binding: string, _global: Record<string, any>): any {
  const expr = binding.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new Function('_global', `return (${expr});`)(_global);
}

const SCREENS = [
  ['회원가입', flatten((registerForm as any).components ?? registerForm)],
  ['비밀번호 재설정', flatten((resetPassword as any).components ?? resetPassword)],
] as const;

const passwordInput = (nodes: Node[]) => nodes.find((n) => n.name === 'PasswordInput' && n.props?.name === 'password');

/** 서버 판정 규칙 안내 단락 — 첫 자식이 server_intro 문구인 노드. */
const serverNote = (nodes: Node[]) =>
  nodes.find((n) => Array.isArray(n.children) && n.children.some((c: any) => String(c.text ?? '').includes('auth.password_rules.server_intro')));

describe.each(SCREENS)('%s 화면 — 비밀번호 규칙 바인딩', (_label, nodes) => {
  /**
   * @scenario surface=register_reset, settings=defaults
   * @effects rules_hint_reflects_settings
   */
  it('설정이 비어 있으면 최소 8자만 요구한다(종전 기본값과 같다)', () => {
    const input = passwordInput(nodes);
    expect(input, 'PasswordInput(name=password) 가 없다').toBeDefined();
    expect(evaluate(input!.props!.enableRules, { settings: {} })).toEqual(['minLength:8']);
  });

  /**
   * @scenario surface=register_reset, settings=all_rules_on
   * @effects rules_hint_reflects_settings
   */
  it('최소 길이·특수문자·조합 설정을 그대로 화면 규칙으로 옮긴다', () => {
    const input = passwordInput(nodes);
    const rules = evaluate(input!.props!.enableRules, {
      settings: { security: { password_min_length: 12, require_password_special_char: true, password_require_char_types: true } },
    });
    expect(rules).toEqual(['minLength:12', 'hasSpecial', 'minTypes:2']);
    expect(String(input!.props!.placeholder)).toContain('count={{_global.settings?.security?.password_min_length ?? 8}}');
  });

  /**
   * @scenario surface=register_reset, settings=server_rules_partial
   * @effects server_only_rules_note_shows_enabled_rules_only
   */
  it('서버만 판정하는 규칙 안내는 하나라도 켜졌을 때만 보이고, 항목도 켜진 것만 보인다', () => {
    const note = serverNote(nodes);
    expect(note, '서버 판정 규칙 안내 단락이 없다').toBeDefined();
    expect(evaluate(note!.if!, { settings: { security: {} } })).toBe(false);
    expect(evaluate(note!.if!, { settings: { security: { password_block_sequential_chars: true } } })).toBe(true);

    const items = (note!.children as Node[]).filter((c) => c.if);
    const on = items.filter((c) => evaluate(c.if!, { settings: { security: { password_block_identifier: true } } }));
    expect(on.map((c) => String(c.text ?? (c.children as any)?.[0]?.text))).toEqual([expect.stringContaining('auth.password_rules.identifier')]);
  });
});
