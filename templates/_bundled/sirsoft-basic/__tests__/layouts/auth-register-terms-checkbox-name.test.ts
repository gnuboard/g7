/**
 * @file auth-register-terms-checkbox-name.test.ts
 * @description 가입 화면 필수 약관 체크박스의 접근 가능한 이름 (7.1.0 N O-N6)
 *
 * 결함: 「이용약관」「개인정보처리방침」 필수 동의 체크박스가 이름 없이 읽혔다(스냅샷 `checkbox` 이름 공백).
 * 곁의 `<label>` 은 for/감싸기 연결이 없고, 안에 전문 보기 버튼이 있어 체크박스를 감쌀 수도 없다.
 * 그래서 체크박스에 동의 문장 전체를 `aria-label` 로 준다(선택 동의 체크박스들처럼 이름이 읽힌다).
 *
 * 테스트 대상: layouts/partials/auth/_register_form.json
 */

import { describe, it, expect } from 'vitest';

type Node = Record<string, any>;

/** 레이아웃 JSON 전체에서 조건에 맞는 객체를 모두 모은다. */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, pred, out));
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node as Node).forEach((child) => collect(child, pred, out));
  }

  return out;
}

describe('가입 화면 — 필수 약관 체크박스 이름', () => {
  const form = require('../../layouts/partials/auth/_register_form.json');

  /**
   * @scenario surface=user_register
   * @effects register_required_agreements_have_accessible_names
   */
  it.each([
    ['agree_terms', '$t:auth.agree_terms_label'],
    ['agree_privacy', '$t:auth.agree_privacy_label'],
  ])('%s 체크박스는 동의 문장을 aria-label 로 갖는다', (name, label) => {
    const boxes = collect(form, (n) => n.name === 'Checkbox' && n.props?.name === name);
    expect(boxes).toHaveLength(1);
    expect(boxes[0].props['aria-label']).toBe(label);
  });

  /**
   * 곁 문구를 <label> 로 두면 연결 없는 label 이 안의 [이용약관] 버튼을 대상으로 삼아 버튼 이름이
   * 「(필수)」 로 읽혔다(2026-10-07 재실측 스냅샷 `button "(Required)"`). 곁 문구는 label 이 아니다.
   *
   * @scenario surface=user_register
   * @effects register_required_agreements_have_accessible_names
   */
  it('필수 동의 곁 문구는 label 이 아니다(전문 보기 버튼 이름이 「(필수)」 로 읽히지 않게)', () => {
    for (const name of ['agree_terms', 'agree_privacy']) {
      const row = collect(form, (n) => Array.isArray(n.children) && n.children.some((c: Node) => c?.name === 'Checkbox' && c.props?.name === name))[0];
      expect(row, name).toBeDefined();
      const labels = (row.children as Node[]).filter((c) => c?.name === 'Label');
      expect(labels, name).toHaveLength(0);
    }
  });
});
