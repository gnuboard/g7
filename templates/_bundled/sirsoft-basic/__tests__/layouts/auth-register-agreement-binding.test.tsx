/**
 * @file auth-register-agreement-binding.test.tsx
 * @description 회원가입 필수 약관 체크박스 — 체크를 풀면 폼 값도 「동의 안 함」이 된다 (#757 후속, audit layout-checkbox-label-and-boolean)
 *
 * 결함: 체크박스가 `value="1"` + 폼 자동바인딩에만 기대고 있었다. 가입 화면의 폼 값은 빈 객체로 시작하므로
 *   엔진이 checked 바인딩이 아니라 value 바인딩을 골라, 체크할 때도 풀 때도 `"1"` 을 써 넣었다.
 *   그래서 동의했다가 체크를 풀어도 폼 값은 계속 「동의」였다(서버 규칙 `accepted` 는 `"1"` 을 통과시킨다).
 *   지금은 브라우저의 필수 입력 검사(`required`)가 제출을 막아 겉으로 드러나지 않았을 뿐이다.
 *
 * 고침: 규정 패턴 — `autoBinding:false` + `checked="{{!!_local.registerForm?.agree_x}}"` + change 에서
 *   `registerForm.agree_x = $event.target.checked` 를 쓴다(docs/frontend/state-management-forms.md 「체크박스 — boolean 값 보장」).
 *
 * 검증 방식: 실제 `_register_form.json` 의 Form 노드와 약관 줄을 그대로 렌더링하고 체크박스를 눌러 폼 상태를 읽는다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen, waitFor } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import registerForm from '../../layouts/partials/auth/_register_form.json';

type Node = Record<string, any>;

const Passthrough =
  (Tag: keyof JSX.IntrinsicElements): React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =>
  ({ className, children, text }) =>
    React.createElement(Tag, { className }, children ?? text);

/** 템플릿 Checkbox 와 같이 props 를 input 에 그대로 넘긴다 */
const TestCheckbox: React.FC<Record<string, any>> = ({ label: _label, ...props }) => (
  <input type="checkbox" data-testid={`checkbox-${props.name}`} {...props} />
);

/**
 * 약관 줄이 쓰는 컴포넌트만 등록한다. Checkbox 는 실제 매니페스트처럼 checked 바인딩 메타를 갖는다.
 *
 * @returns 테스트 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Form: { component: Passthrough('form'), metadata: { name: 'Form', type: 'basic' } },
    Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    Label: { component: Passthrough('label'), metadata: { name: 'Label', type: 'basic' } },
    Span: { component: Passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
    Button: { component: Passthrough('button'), metadata: { name: 'Button', type: 'basic' } },
    A: { component: Passthrough('a'), metadata: { name: 'A', type: 'basic' } },
    Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'basic', bindingType: 'checked' } },
  };

  return registry;
}

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 (없으면 undefined)
 */
function findNode(node: unknown, predicate: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findNode(child, predicate);
      if (hit) return hit;
    }

    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) return obj;
    for (const value of Object.values(obj)) {
      const hit = findNode(value, predicate);
      if (hit) return hit;
    }
  }

  return undefined;
}

/**
 * 폼 값 하나를 읽는다 — 시험 유틸의 setLocal 은 점 경로를 평평한 키로도 남기므로 두 모양을 다 본다.
 *
 * @param local _local 상태
 * @param name 필드 이름
 * @returns 폼 값
 */
const formValue = (local: Record<string, any>, name: string): unknown =>
  local[`registerForm.${name}`] !== undefined ? local[`registerForm.${name}`] : local.registerForm?.[name];

const agreementRow = (name: string): Node | undefined =>
  findNode(registerForm, (n) => Array.isArray(n.children) && n.children.some((c: Node) => c?.name === 'Checkbox' && c.props?.name === name));

/**
 * 실제 Form 노드(dataKey·자동바인딩 문맥)에 약관 두 줄만 넣은 레이아웃.
 *
 * @returns 레이아웃 JSON
 */
function buildLayout() {
  const form = registerForm as Node;

  return {
    version: '1.0.0',
    layout_name: 'register_agreement_binding_test',
    components: [{ ...form, actions: [], children: [agreementRow('agree_terms'), agreementRow('agree_privacy')] }],
  };
}

describe('회원가입 — 필수 약관 체크박스 값 (_register_form.json)', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  /**
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it.each(['agree_terms', 'agree_privacy'])('%s — 체크해도 풀어도 폼 값에 문자열 "1" 이 써지지 않는다(값 바인딩 차단)', async (name) => {
    expect(agreementRow(name)).toBeDefined();
    const t = createLayoutTest(buildLayout() as any, { initialState: { _local: { registerForm: {} } } });
    await t.render();

    const box = screen.getByTestId(`checkbox-${name}`) as HTMLInputElement;
    // 수정 전: 체크하면 자동바인딩이 값 바인딩으로 "1" 을 써 넣고, 풀어도 "1" 이 그대로 남았다(실측)
    await t.user.click(box);
    await waitFor(() => expect(box.checked).toBe(true));
    expect(formValue(t.getState()._local, name)).not.toBe('1');

    await t.user.click(box);
    await waitFor(() => expect(box.checked).toBe(false));
    expect(formValue(t.getState()._local, name)).not.toBe('1');
  });

  /**
   * 동의 값은 체크 상태에서 직접 온다 — 자동바인딩 끄기 · checked 를 폼 값의 참거짓으로 · change 에서 체크 상태를 그대로 저장.
   * (시험 유틸은 change 액션의 setState 결과를 상태에 비추지 않으므로 이 계약은 구조로 잠근다 — 선례 admin_uninstall_modal)
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it.each(['agree_terms', 'agree_privacy'])('%s — 체크 상태를 boolean 으로 폼 값에 잇는다(규정 패턴)', (name) => {
    const box = findNode(registerForm, (n) => n.name === 'Checkbox' && n.props?.name === name);
    expect(box).toBeDefined();
    expect(box!.props.autoBinding).toBe(false);
    expect(box!.props.value).toBeUndefined();
    expect(box!.props.checked).toBe(`{{!!_local.registerForm?.${name}}}`);
    expect(box!.props.required).toBe(true);

    const change = (box!.actions ?? []).find((a: Node) => a.type === 'change');
    expect(change?.handler).toBe('setState');
    expect(change?.params?.target).toBe('local');
    expect(change?.params?.[`registerForm.${name}`]).toBe('{{$event.target.checked}}');
  });
});
