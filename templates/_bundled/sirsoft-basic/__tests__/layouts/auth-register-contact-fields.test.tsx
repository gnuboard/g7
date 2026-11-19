/**
 * @file auth-register-contact-fields.test.tsx
 * @description 회원가입 폼의 연락처(휴대폰, 전화번호) 칸 정합성 회귀 테스트
 *
 * 배경: 처음에는 내 정보 수정에만 휴대폰/전화 칸이 있고 가입 폼에는 없어 두 화면이 어긋났다. 그래서 가입 폼에도
 *   연락처 칸을 넣었고, 오류가 위쪽 상자와 칸 아래에 두 번 뜨지 않게 필드 이름 배열로 갈랐다.
 * 7.2.0: 가입 폼의 회원 항목(이름, 닉네임, 휴대폰, 전화, 언어)은 운영자 설정을 따르는 공용 항목 조각이 그린다.
 *   연락처 칸은 폼에 박혀 있지 않고 서버가 준 항목 계약에 들어 있을 때 그려진다. 이 테스트는 옛 보호 장치가
 *   새 구조에서도 성립하는지 본다.
 *
 * 검증 항목:
 * 1. 가입 폼이 공용 항목 조각을 include 한다 (연락처 칸이 그려지는 경로)
 * 2. 폼에 name="mobile" / name="phone" 입력이 박혀 있지 않다 (설정과 화면이 어긋나는 회귀 방지)
 * 3. 공용 항목 조각이 칸마다 오류 줄을 가진다
 * 4. 연락처 칸의 오류가 위쪽 상자에 다시 뜨지 않는다 (이중 표시 회귀 방지)
 * 5. 마케팅/광고 수신 동의 문구와 체크박스는 코어 가입 폼에 들어가지 않는다
 *
 * @scenario-manifest tests/scenarios/auth-register-contact-fields.yaml
 */

import { describe, it, expect } from 'vitest';

import registerLayout from '../../layouts/auth/register.json';
import registerForm from '../../layouts/partials/auth/_register_form.json';
import memberFields from '../../layouts/partials/member/_member_fields.json';

type Node = {
  id?: string;
  type?: string;
  name?: string;
  if?: string;
  partial?: string;
  props?: Record<string, any>;
  children?: Node[];
  iteration?: Record<string, any>;
  text?: string;
  comment?: string;
};

function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

function evaluate(expression: string, scope: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

const formNodes = collect(registerForm as Node);
const fieldNodes = collect(memberFields as Node);

describe('회원가입 폼 휴대폰/전화번호 필드', () => {
  describe('입력 칸이 그려지는 경로', () => {
    /**
     * @scenario part=path
     * @effects contact_fields_drawn_by_member_fields_partial
     */
    it('가입 폼이 공용 항목 조각을 include 한다', () => {
      expect(formNodes.some((node) => node.partial === 'partials/member/_member_fields.json')).toBe(true);
    });

    /**
     * @scenario part=path
     * @effects contact_inputs_not_hardcoded_in_form
     */
    it('폼에 name="mobile" / name="phone" 입력이 박혀 있지 않다', () => {
      const names = formNodes.map((node) => node.props?.name);
      expect(names).not.toContain('mobile');
      expect(names).not.toContain('phone');
    });

    /**
     * @scenario part=path
     * @effects contact_required_follows_contract
     */
    it('공용 항목 조각의 입력칸은 항목의 필수 여부를 계약에서 받는다 (선택 항목에 required 를 박지 않는다)', () => {
      const input = fieldNodes.find((node) => node.name === 'MemberFieldInput');
      expect(input?.props?.field).toBe('{{field}}');
      expect(input?.props?.required).toBeUndefined();
    });
  });

  describe('per-field 에러 표시', () => {
    /**
     * @scenario part=error
     * @effects contact_error_shown_under_field
     */
    it('공용 항목 조각이 칸마다 오류 줄(mf_error_{키})을 가진다', () => {
      const error = fieldNodes.find((node) => node.id === 'mf_error_{{field.key}}');
      expect(error).toBeDefined();
      const scope = (errors: Record<string, unknown>) => ({ field: { name: 'mobile' }, $computed: { memberFieldCtx: { errors } } });
      expect(evaluate(error!.if!, scope({ mobile: ['형식이 맞지 않습니다'] }))).toBe(true);
      expect(evaluate(error!.if!, scope({ phone: ['다른 칸'] }))).toBe(false);
      expect(evaluate(error!.text!, scope({ mobile: ['형식이 맞지 않습니다'] }))).toBe('형식이 맞지 않습니다');
    });
  });

  describe('이중 표시 회귀 방지', () => {
    /**
     * @scenario part=error
     * @effects contact_error_not_repeated_in_top_box
     */
    it('연락처 칸의 오류는 위쪽 상자 목록에 들어가지 않는다', () => {
      const orphan = evaluate((registerLayout as any).computed.registerOrphanErrors, {
        _local: { errors: { mobile: ['휴대폰 형식'], phone: ['전화 형식'], verification_token: ['만료'] } },
        registerFields: { data: { fields: [{ name: 'mobile' }, { name: 'phone' }] } },
      });
      expect(orphan).toEqual(['만료']);
    });
  });

  describe('마케팅/광고 수신 동의 미포함 (코어 중립성)', () => {
    /**
     * @scenario part=neutrality
     * @effects core_form_has_no_marketing_text
     */
    it('코어 회원가입 폼에 마케팅/광고 수신 관련 문구가 없다', () => {
      // 7.2.0: 확장이 선언한 동의 항목은 공용 항목 조각이 서버 항목 계약대로 그린다. 폼 조각에는 그 자리를 설명하는
      // 개발자 주석(「마케팅 수신 동의 등」)만 있고, 주석은 화면에 그려지지 않으므로 주석을 뺀 나머지에서 문구를 찾는다.
      const raw = JSON.stringify(registerForm, (key, value) => (key === 'comment' ? undefined : value));
      expect(raw).not.toContain('marketing');
      expect(raw).not.toContain('광고');
      expect(raw).not.toContain('마케팅');
    });

    /**
     * @scenario part=neutrality
     * @effects core_form_has_no_marketing_text
     */
    it('확장의 동의 항목은 폼에 박힌 체크박스가 아니라 항목 계약의 약관 묶음(agreements)으로만 그려진다', () => {
      const hardcoded = formNodes.filter((node) => node.name === 'Checkbox').map((node) => String(node.props?.name ?? ''));
      expect(hardcoded.sort()).toEqual(['agree_privacy', 'agree_terms']);

      const slot = formNodes.find((node) => node.id === 'register_extension_agreements');
      expect(slot).toBeDefined();
      const scope = (fields: unknown[]) => ({ registerFields: { data: { fields } } });
      expect(evaluate(slot!.if!, scope([{ name: 'marketing_consent', ui: { group: 'agreements' } }]))).toBe(true);
      expect(evaluate(slot!.if!, scope([{ name: 'mobile', ui: { group: 'contact' } }]))).toBe(false);
      expect(collect(slot).some((node) => node.partial === 'partials/member/_member_fields.json')).toBe(true);
    });
  });
});
