/**
 * useBindingCandidates 단위 테스트
 *
 * 검증:
 *  - useBindingCandidates: data_sources/_computed 후보 빌드, evaluateComputed 옵션 평가값 부착
 *
 * (SEO 후보(useSeoBindingCandidates)는 useSeoBindingCandidates.test 가 별도로 잠근다 — SSoT.
 *  캔버스 동작 무변경 회귀는 EditorCanvasOverlay.history + bindingCandidates 스위트가 잠근다.)
 */

import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useBindingCandidates } from '../../hooks/useBindingCandidates';
import type { EditorSpec } from '../../spec/specTypes';

const SPEC: EditorSpec = {
  sampleGlobal: { settings: { site_name: '테스트몰' } },
};

describe('useBindingCandidates', () => {
  it('data_sources + _computed 후보를 빌드한다', () => {
    const raw = {
      data_sources: [{ id: 'products', label_key: '$t:ds.products' }],
      computed: { itemCount: '{{ (products.data ?? []).length }}' },
    };
    const { result } = renderHook(() => useBindingCandidates({ raw, spec: SPEC }));
    const exprs = result.current.map((c) => c.expression);
    // _computed 후보가 노출된다.
    expect(exprs.some((e) => e.includes('_computed.itemCount'))).toBe(true);
    // _global(sampleGlobal) 후보도 노출.
    expect(exprs.some((e) => e.includes('_global.settings.site_name'))).toBe(true);
  });

  it('evaluateComputed=true 면 _computed 후보 미리보기에 평가값을 부착한다', () => {
    const raw = {
      data_sources: [],
      // computed 는 scope 경로(_local.items)로 데이터를 참조한다(운영 식 형태).
      computed: { total: '{{ (_local.items ?? []).length }}' },
    };
    const spec: EditorSpec = {
      sampleGlobal: {},
      // states 의 initialState 로 items 샘플 주입 → 평가 컨텍스트(_local)에 노출.
      states: {
        groups: [
          { scope: { kind: 'route', match: '/' }, items: [{ id: 's', initialState: { local: { items: [1, 2, 3] } } }] },
        ],
      },
    };
    const { result } = renderHook(() =>
      useBindingCandidates({ raw, spec, evaluateComputed: true }),
    );
    const totalCandidate = result.current.find((c) => c.expression.includes('_computed.total'));
    expect(totalCandidate).toBeDefined();
    // _local.items=[1,2,3] → length 3.
    expect(totalCandidate?.preview).toBe('3');
  });

  it('evaluateComputed 미지정(캔버스 기본)은 표현식 그대로 — 평가 안 함', () => {
    const raw = {
      data_sources: [],
      computed: { total: '{{ (_local.items ?? []).length }}' },
    };
    const spec: EditorSpec = {
      sampleGlobal: {},
      states: {
        groups: [
          { scope: { kind: 'route', match: '/' }, items: [{ id: 's', initialState: { local: { items: [1, 2, 3] } } }] },
        ],
      },
    };
    const { result } = renderHook(() => useBindingCandidates({ raw, spec }));
    const totalCandidate = result.current.find((c) => c.expression.includes('_computed.total'));
    expect(totalCandidate).toBeDefined();
    // 평가 안 함 → preview 가 평가값(3)이 아니다(표현식 문자열의 scalar 폴백).
    expect(totalCandidate?.preview).not.toBe('3');
  });
});
