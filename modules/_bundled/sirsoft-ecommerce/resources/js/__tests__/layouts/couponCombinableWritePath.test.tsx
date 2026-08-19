/**
 * @file couponCombinableWritePath.test.tsx
 * @description 쿠폰 is_combinable 라디오 쓰기 경로 boolean 회귀 — 공개 이슈 #97
 *
 * 결함: RadioGroup 이 `name` 자동바인딩만으로 폼에 묶이면 클릭 시
 * `e.target.value`(DOM 문자열 "true"/"false")가 그대로 폼 상태에 저장되어
 * 서버 `boolean` 규칙에서 422 가 된다. 표시 계층은 느슨 비교(String===String)로
 * 정상이라 저장 시점에야 드러난다.
 *
 * 정정: 레이아웃이 `autoBinding: false` + `value: String(...)` 표시 바인딩 +
 * `change` 액션의 `=== 'true'` 캐스팅으로 쓰기 경로를 boolean 으로 고정한다.
 *
 * 실제 partial(_partial_usage_conditions.json)의 field_is_combinable 노드를
 * 실제 메인 레이아웃과 동일한 폼 컨텍스트(dataKey: "form" + debounce: 300)로
 * 렌더하고, 폼 상태의 타입은 레이아웃 바인딩 프로브(엄격 === 비교 삼항식)로
 * 관측한다 — 렌더러 내부 폼 상태는 테스트 하네스 getState() 로는 보이지 않는다.
 * (수정 전에는 클릭 후 프로브가 string-false 라 RED)
 *
 * @vitest-environment jsdom
 */

import React, { useState, useEffect } from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  fireEvent,
  waitFor,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import usageConditionsPartial from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json';

/** JSON 트리에서 id로 노드를 재귀 탐색 */
function findById(node: any, id: string): any {
  if (!node) return null;
  if (node.id === id) return node;
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      const found = findById(child, id);
      if (found) return found;
    }
  }
  return null;
}

/**
 * FormField 더블 — 라벨 + children 렌더 (구조 통과용)
 */
const TestFormField: React.FC<{
  label?: string;
  error?: string;
  children?: React.ReactNode;
}> = ({ label, error, children }) => (
  <div data-testid="form-field">
    {label && <span>{label}</span>}
    {children}
    {error && <span role="alert">{error}</span>}
  </div>
);

/**
 * RadioGroup 더블 — 실제 sirsoft-admin_basic RadioGroup 의 핵심 동작 복제:
 * - 내부 상태 + prop 동기화
 * - 표시: String(option.value) === String(value) 느슨 비교 (표시 계층 구제 동작 그대로)
 * - 클릭: DOM ChangeEvent 를 onChange 로 그대로 전달 (e.target.value 는 문자열)
 */
const TestRadioGroup: React.FC<{
  name: string;
  value?: string;
  options: Array<{ value: string; label: string }>;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  inline?: boolean;
}> = ({ name, value: valueProp, options, onChange }) => {
  const [value, setValue] = useState(() => valueProp ?? '');

  useEffect(() => {
    setValue(valueProp ?? '');
  }, [valueProp]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setValue(e.target.value);
    if (onChange) onChange(e);
  };

  return (
    <div data-testid={`radio-group-${name}`}>
      {options.map((option) => (
        <label key={String(option.value)}>
          <input
            type="radio"
            name={name}
            value={option.value}
            checked={String(option.value) === String(value)}
            onChange={handleChange}
          />
          <span>{option.label}</span>
        </label>
      ))}
    </div>
  );
};

/**
 * 폼 상태 타입 프로브 — 엄격 === 비교로 boolean 과 문자열을 구분해 텍스트로 노출.
 * 자동바인딩/액션의 쓰기 결과는 렌더러 내부 _local 에 반영되므로
 * 레이아웃 바인딩으로만 정확한 타입을 관측할 수 있다.
 */
const TYPE_PROBE_EXPR =
  "{{_local.form?.is_combinable === true ? 'bool-true' : (_local.form?.is_combinable === false ? 'bool-false' : (_local.form?.is_combinable === 'true' ? 'string-true' : (_local.form?.is_combinable === 'false' ? 'string-false' : 'other')))}}";

/**
 * 실제 partial 의 field_is_combinable 노드만 잘라 프로브 레이아웃 구성.
 * 폼 컨텍스트는 실제 메인 레이아웃(admin_ecommerce_promotion_coupon_form.json
 * #form_sections)과 동일하게 dataKey: "form" + debounce: 300 을 재현한다 —
 * 자동바인딩 쓰기 경로는 이 컨텍스트가 있어야 발화한다.
 */
function buildProbeLayout() {
  const fieldNode = findById(usageConditionsPartial, 'field_is_combinable');
  if (!fieldNode) throw new Error('field_is_combinable 노드를 찾지 못함');

  return {
    version: '1.0.0',
    layout_name: 'test/coupon-combinable-write-path',
    state: {
      form: {
        is_combinable: true,
      },
    },
    components: [
      {
        type: 'basic',
        name: 'Div',
        dataKey: 'form',
        debounce: 300,
        children: [
          fieldNode,
          {
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'combinable-type-probe' },
            text: TYPE_PROBE_EXPR,
          },
        ],
      },
    ],
  };
}

describe('쿠폰 is_combinable 쓰기 경로 (공개 #97)', () => {
  let registry: MockComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'FormField', TestFormField);
    registry.register('composite', 'RadioGroup', TestRadioGroup);

    testUtils = createLayoutTest(buildProbeLayout(), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
  });

  afterEach(() => {
    testUtils.cleanup();
    vi.clearAllMocks();
  });

  it('초기 상태: is_combinable 은 boolean true, "가능" 라디오 checked', async () => {
    await testUtils.render();

    expect(screen.getByTestId('combinable-type-probe').textContent).toBe('bool-true');

    const radios = document.querySelectorAll<HTMLInputElement>('input[name=is_combinable]');
    expect(radios).toHaveLength(2);
    expect(radios[0].checked).toBe(true); // value "true" (가능)
    expect(radios[1].checked).toBe(false); // value "false" (불가능)
  });

  it('"불가능" 라디오 클릭 → 폼 상태가 boolean false 로 저장된다 (문자열 "false" 금지)', async () => {
    await testUtils.render();

    const radios = document.querySelectorAll<HTMLInputElement>('input[name=is_combinable]');
    fireEvent.click(radios[1]);

    // debounce 300ms 를 넘겨 최종 저장값의 타입을 판정한다
    await waitFor(
      () => {
        expect(screen.getByTestId('combinable-type-probe').textContent).toBe('bool-false');
      },
      { timeout: 2000 }
    );
  });

  it('"불가능" → "가능" 재클릭 → boolean true 복원', async () => {
    await testUtils.render();

    const radios = document.querySelectorAll<HTMLInputElement>('input[name=is_combinable]');
    fireEvent.click(radios[1]);
    await waitFor(
      () => {
        expect(screen.getByTestId('combinable-type-probe').textContent).toBe('bool-false');
      },
      { timeout: 2000 }
    );

    fireEvent.click(radios[0]);
    await waitFor(
      () => {
        expect(screen.getByTestId('combinable-type-probe').textContent).toBe('bool-true');
      },
      { timeout: 2000 }
    );
  });
});
