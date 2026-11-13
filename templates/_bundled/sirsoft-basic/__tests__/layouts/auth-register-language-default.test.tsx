/**
 * @file auth-register-language-default.test.tsx
 * @description 회원가입 폼 언어 Select 기본값 회귀 테스트
 *
 * 증상 1 (해당 PR 이전): 회원가입 페이지 진입 시 "언어" Select 가 unselected 상태로 표시됨.
 *   원인: _register_form.json 의 lifecycle.onMount 에서 setState 로 _local.registerForm.language 를
 *   시드하던 패턴이 dataKey 자동바인딩과 충돌 (트러블슈팅 setstate 사례 2).
 *
 * 증상 2 (PR 중간 단계 발견): init_actions 에서 {{$locale ?? 'ko'}} 로 시드하면 영문 진입 시에도 항상 'ko'
 *   가 박혀 영문 UI 에 한국어가 선택됨.
 *   원인: TemplateApp.ts 의 initialDataContext 에 $locale 미주입 → 표현식이 undefined 로 평가되어
 *   항상 fallback 'ko'.
 *
 * 해결:
 * 1. register.json init_actions 에서는 registerForm 을 빈 객체로만 시드 (language 미시드)
 * 2. _register_form.json 의 lifecycle.onMount 블록 제거 (자동바인딩 충돌 회피)
 * 3. Select 에 명시적 value 표현식 ({{_local?.registerForm?.language ?? $locale ?? 'ko'}})
 *    → 렌더 시점에는 $locale 이 컨텍스트에 들어가 있어 영문/한국어 정확히 구분
 *    → 사용자 변경 시 dataKey 자동바인딩이 _local.registerForm.language 갱신, 우선 적용
 *
 * 7.2.0: 언어 칸은 폼에 박힌 Select 가 아니라 운영자 설정을 따르는 공용 항목 조각이 그린다. 3번 장치는
 *   「항목 계약의 기본값(서버가 요청 로케일로 계산)을 입력 컴포넌트가 골라 보이되 폼에 써넣지 않는다」로 바뀌었다 —
 *   회원이 바꾸면 폼 자동 바인딩이 _local.registerForm.language 를 쓰고 그 값이 우선한다. 1, 2번 장치는 그대로다.
 *
 * 본 테스트는 위 3가지 보호 장치가 회귀로 사라지지 않도록 JSON 정합성과 렌더 결과를 검증함.
 *
 * @scenario-manifest tests/scenarios/auth-register-language-default.yaml
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';

import registerLayout from '../../layouts/auth/register.json';
import registerForm from '../../layouts/partials/auth/_register_form.json';
import memberFields from '../../layouts/partials/member/_member_fields.json';
import MemberFieldInput from '../../src/components/composite/MemberFieldInput';

type Node = {
  type?: string;
  name?: string;
  dataKey?: string;
  lifecycle?: Record<string, unknown>;
  props?: Record<string, any>;
  children?: Node[];
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

describe('회원가입 폼 언어 Select 기본값', () => {
  describe('register.json init_actions', () => {
    /**
     * @scenario layout_part=register_init_actions
     * @effects register_form_seed_without_language
     */
    it('init_actions 에 registerForm 초기 상태 setState 가 존재한다', () => {
      const initActions = (registerLayout as any).init_actions ?? [];
      expect(initActions.length).toBeGreaterThan(0);

      const seed = initActions.find(
        (a: any) =>
          a.handler === 'setState' &&
          a.params?.target === 'local' &&
          a.params?.registerForm !== undefined,
      );
      expect(seed).toBeDefined();
    });

    /**
     * @scenario layout_part=register_init_actions
     * @effects register_form_seed_without_language
     */
    it('registerForm 시드는 language 를 미리 박지 않는다 ($locale 미주입 컨텍스트라 잘못된 ko 가 박히는 회귀 방지)', () => {
      const initActions = (registerLayout as any).init_actions ?? [];
      // 앞의 `registerForm: null` 은 이전 입력을 지우는 단계(7.1.1) — 시드는 그 뒤의 객체 값이다
      const seed = initActions.find(
        (a: any) =>
          a.handler === 'setState' &&
          a.params?.target === 'local' &&
          a.params?.registerForm !== undefined &&
          a.params?.registerForm !== null,
      );
      const registerForm = seed.params.registerForm;
      expect(registerForm.language).toBeUndefined();
    });
  });

  describe('_register_form.json (lifecycle.onMount 충돌 패턴 회피)', () => {
    /**
     * @scenario layout_part=register_form_partial
     * @effects register_form_no_onmount_seed
     */
    it('Form 루트에 lifecycle.onMount 가 정의되어 있지 않다', () => {
      const form = registerForm as any;
      expect(form.type).toBe('basic');
      expect(form.name).toBe('Form');
      expect(form.dataKey).toBe('registerForm');
      expect(form.lifecycle).toBeUndefined();
    });
  });

  describe('언어 칸 (7.2.0 — 운영자 설정을 따르는 공용 항목 조각이 그린다)', () => {
    /**
     * @scenario layout_part=register_form_partial
     * @effects language_select_not_hardcoded_in_form
     */
    it('폼 조각에 언어 Select 가 박혀 있지 않다 (언어 칸은 항목 계약에 있을 때 공용 조각이 그린다)', () => {
      let found = false;
      walk(registerForm as Node, (node) => {
        if (node.props?.name === 'language') found = true;
      });
      expect(found).toBe(false);
    });

    /**
     * @scenario layout_part=member_fields_partial
     * @effects language_value_binds_to_form_without_explicit_value
     */
    it('공용 조각의 입력칸은 value 를 직접 주지 않는다 (폼 자동 바인딩이 _local.registerForm.language 를 잇는다)', () => {
      let input: Node | undefined;
      walk(memberFields as Node, (node) => {
        if (node.name === 'MemberFieldInput') input = node;
      });
      expect(input).toBeDefined();
      expect(input?.props?.name).toBe('{{field.name}}');
      expect(input?.props?.value).toBeUndefined();
    });

    /**
     * @scenario layout_part=member_fields_partial
     * @effects language_default_shown_from_contract_without_seeding
     */
    it('값이 없으면 항목 계약의 기본값(서버가 요청 로케일로 준다)을 골라 보이고, 그 값을 폼에 써넣지 않는다', () => {
      (window as any).G7Core = { t: (key: string) => key, createChangeEvent: ({ value, name }: any) => ({ target: { value, name } }) };
      const emitted: unknown[] = [];
      const field = {
        key: 'language', name: 'language', type: 'choice', label: 'Language', required: false,
        options: [{ value: 'ko', label: '한국어' }, { value: 'en', label: 'English' }],
        constraints: { display: 'select' }, default: 'en',
      };
      render(<MemberFieldInput field={field} name="language" value={undefined} onChange={(e: any) => emitted.push(e.target.value)} />);

      // 닫힌 드롭다운의 단추에 기본값의 라벨이 보인다
      expect(screen.getByText('English')).toBeTruthy();
      expect(emitted).toEqual([]);
    });
  });
});
