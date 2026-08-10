/**
 * @file auth-register-contact-fields.test.tsx
 * @description 회원가입 폼 휴대폰/전화번호 필드 정합성 회귀 테스트
 *
 * 배경: 마이페이지 프로필 편집에는 휴대폰/전화 필드가 있으나 회원가입 폼에만 빠져 있어
 *   두 화면이 불일치했다. 회원가입에도 연락처(선택) 입력 필드를 추가한다.
 *
 * 검증 항목:
 * 1. name="mobile" / name="phone" Input 노드가 각각 존재 (선택 항목이므로 required 없음)
 * 2. 라벨/placeholder 가 auth 네임스페이스 다국어 키를 사용
 * 3. 각 필드에 per-field 에러 span 이 존재 (_local.errors?.mobile / phone)
 * 4. 에러 필터 화이트리스트(폼 필드 vs 플러그인 기여 에러 구분)에 mobile/phone 이 포함
 *    → 누락 시 mobile/phone 검증 에러가 상단 목록과 필드 하단에 이중 표시되는 회귀 방지
 * 5. 마케팅/광고 수신 동의 문구·체크박스는 코어 회원가입 폼에 들어가지 않는다
 */

import { describe, it, expect } from 'vitest';

import registerForm from '../../layouts/partials/auth/_register_form.json';

type Node = {
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  iteration?: Record<string, any>;
  text?: string;
  comment?: string;
};

function walk(input: Node | Node[] | undefined, visit: (node: Node) => void): void {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes) {
    visit(node);
    if (Array.isArray(node.children)) walk(node.children, visit);
  }
}

function findInput(name: string): Node | undefined {
  let found: Node | undefined;
  walk(registerForm as Node, (node) => {
    if (node.type === 'basic' && node.name === 'Input' && node.props?.name === name) {
      found = node;
    }
  });
  return found;
}

describe('회원가입 폼 휴대폰/전화번호 필드', () => {
  describe('입력 필드 존재', () => {
    it('name="mobile" Input 이 존재한다', () => {
      expect(findInput('mobile')).toBeDefined();
    });

    it('name="phone" Input 이 존재한다', () => {
      expect(findInput('phone')).toBeDefined();
    });

    it('휴대폰/전화 필드는 선택 항목이므로 required 가 아니다', () => {
      expect(findInput('mobile')?.props?.required).toBeUndefined();
      expect(findInput('phone')?.props?.required).toBeUndefined();
    });

    it('라벨/placeholder 가 auth 다국어 키를 사용한다', () => {
      expect(findInput('mobile')?.props?.placeholder).toBe('$t:auth.mobile_placeholder');
      expect(findInput('phone')?.props?.placeholder).toBe('$t:auth.phone_placeholder');
    });
  });

  describe('per-field 에러 표시', () => {
    function hasErrorSpan(field: string): boolean {
      let found = false;
      walk(registerForm as Node, (node) => {
        if (
          node.type === 'basic' &&
          node.name === 'Span' &&
          typeof node.if === 'string' &&
          node.if.includes(`errors?.${field}`)
        ) {
          found = true;
        }
      });
      return found;
    }

    it('mobile 에러 span 이 존재한다', () => {
      expect(hasErrorSpan('mobile')).toBe(true);
    });

    it('phone 에러 span 이 존재한다', () => {
      expect(hasErrorSpan('phone')).toBe(true);
    });
  });

  describe('에러 필터 화이트리스트 (이중 표시 회귀 방지)', () => {
    it('폼 필드 화이트리스트에 mobile/phone 이 포함되어 있다', () => {
      const raw = JSON.stringify(registerForm);
      const matches = raw.match(/\['email'[^\]]*'agree_privacy'\]/g) ?? [];
      // 두 곳(상단 목록 if + iteration source) 모두에 존재해야 함
      expect(matches.length).toBeGreaterThanOrEqual(2);
      for (const list of matches) {
        expect(list).toContain("'mobile'");
        expect(list).toContain("'phone'");
      }
    });
  });

  describe('마케팅/광고 수신 동의 미포함 (코어 중립성)', () => {
    it('코어 회원가입 폼에 마케팅/광고 수신 관련 문구가 없다', () => {
      const raw = JSON.stringify(registerForm);
      expect(raw).not.toContain('marketing');
      expect(raw).not.toContain('광고');
      expect(raw).not.toContain('마케팅');
    });
  });
});
