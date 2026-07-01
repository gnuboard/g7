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
 * 본 테스트는 위 3가지 보호 장치가 회귀로 사라지지 않도록 JSON 정합성을 검증함.
 */

import { describe, it, expect } from 'vitest';

import registerLayout from '../../layouts/auth/register.json';
import registerForm from '../../layouts/partials/auth/_register_form.json';

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

    it('registerForm 시드는 language 를 미리 박지 않는다 ($locale 미주입 컨텍스트라 잘못된 ko 가 박히는 회귀 방지)', () => {
      const initActions = (registerLayout as any).init_actions ?? [];
      const seed = initActions.find(
        (a: any) =>
          a.handler === 'setState' &&
          a.params?.target === 'local' &&
          a.params?.registerForm !== undefined,
      );
      const registerForm = seed.params.registerForm;
      expect(registerForm.language).toBeUndefined();
    });
  });

  describe('_register_form.json (lifecycle.onMount 충돌 패턴 회피)', () => {
    it('Form 루트에 lifecycle.onMount 가 정의되어 있지 않다', () => {
      const form = registerForm as any;
      expect(form.type).toBe('basic');
      expect(form.name).toBe('Form');
      expect(form.dataKey).toBe('registerForm');
      expect(form.lifecycle).toBeUndefined();
    });
  });

  describe('_register_form.json 언어 Select', () => {
    function findLanguageSelect(): Node | undefined {
      let found: Node | undefined;
      walk(registerForm as Node, (node) => {
        if (
          node.type === 'basic' &&
          node.name === 'Select' &&
          node.props?.name === 'language'
        ) {
          found = node;
        }
      });
      return found;
    }

    it('Select 에 name="language" 가 존재한다', () => {
      const select = findLanguageSelect();
      expect(select).toBeDefined();
    });

    it('Select 에 명시적 value prop 이 정의되어 있다 (렌더 시점 $locale 평가)', () => {
      const select = findLanguageSelect();
      expect(select?.props?.value).toBeDefined();
    });

    it('value 표현식이 _local.registerForm.language 우선, $locale fallback 패턴이다', () => {
      const select = findLanguageSelect();
      const value = select?.props?.value as string;
      expect(value).toContain('_local');
      expect(value).toContain('registerForm');
      expect(value).toContain('language');
      expect(value).toContain('$locale');
    });

    it('options 가 $locales 배열을 매핑한다', () => {
      const select = findLanguageSelect();
      const options = select?.props?.options as string;
      expect(options).toContain('$locales');
      expect(options).toContain('value');
      expect(options).toContain('label');
    });
  });
});
