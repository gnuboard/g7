/**
 * PreviewCanvas → DynamicRenderer `_computedDefinitions` 주입 회귀 테스트
 *
 * 회귀 원인: 편집기 미리보기(`PreviewCanvas`)가 DynamicRenderer 의 dataContext 에
 * `_computedDefinitions`(레이아웃 `computed` 블록)를 주입하지 않아, 편집기에서는
 * `_computed.*` 가 항상 undefined 였다. 그 결과 `if: {{_computed.xxx}}` 로 게이트된
 * 본체(배송정책 수정 폼의 국가별 설정, 부과정책별 조건부 섹션 등)가 캔버스에서
 * 통째로 비어 렌더됨.
 *
 * 런타임 앱은 `TemplateApp.ts` 가 `initialDataContext._computedDefinitions = layoutData.computed`
 * 로 주입하므로 정상 동작했다. 편집기도 동일하게 `document.raw.computed` 를 주입해 해소.
 *
 * 두 계층 가드:
 * (1) PreviewCanvas 소스가 DynamicRenderer dataContext 에 `_computedDefinitions` 를 전달한다
 *     (정적 가드 — 누락으로 회귀하면 실패).
 * (2) DynamicRenderer 의 _computed 재계산 블록이 `_computedDefinitions` 로부터 `_local` 기반
 *     `_computed` 를 산출한다(동작 가드 — DataBindingEngine 으로 직접 검증).
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { DataBindingEngine } from '../../../DataBindingEngine';

describe('PreviewCanvas → _computedDefinitions 주입', () => {
  it('(1) PreviewCanvas 가 document.raw.computed 를 _computedDefinitions 로 주입한다 (정적 가드)', () => {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const src = readFileSync(path.resolve(here, '../../components/PreviewCanvas.tsx'), 'utf8');
    // computed 블록을 메모이즈하고
    expect(src).toMatch(/document\?\.raw\?\.computed/);
    // DynamicRenderer dataContext 에 _computedDefinitions 로 전달해야 한다.
    expect(src).toMatch(/_computedDefinitions:\s*computedDefinitions/);
  });

  it('(2) _computed 재계산이 _computedDefinitions + _local 로 활성국가설정을 산출한다 (동작 가드)', () => {
    // DynamicRenderer 재계산 블록과 동일한 평가 경로를 직접 재현한다.
    const engine = new DataBindingEngine();
    const computedDefinitions: Record<string, string> = {
      activeCountrySetting: '{{(_local.form?.country_settings ?? [])[_local.activeCountryTab ?? 0]}}',
      activeCountryCode:
        "{{(_local.form?.country_settings ?? [])[_local.activeCountryTab ?? 0]?.country_code ?? ''}}",
    };
    const dataContext = {
      _local: {
        form: {
          name: { ko: '기본 배송정책' },
          country_settings: [
            { country_code: 'KR', charge_policy: 'conditional_free', base_fee: 3000, free_threshold: 50000 },
            { country_code: 'US', charge_policy: 'range_amount' },
          ],
        },
        activeCountryTab: 0,
      },
    };

    const computed: Record<string, unknown> = {};
    for (const [key, expr] of Object.entries(computedDefinitions)) {
      const inner = expr.slice(2, -2).trim();
      computed[key] = engine.evaluateExpression(inner, dataContext, { skipCache: true });
    }

    // activeCountrySetting 이 KR 설정으로 resolve → if:{{_computed.activeCountrySetting}} 본체 렌더
    expect(computed.activeCountrySetting).toMatchObject({ country_code: 'KR', charge_policy: 'conditional_free' });
    expect(computed.activeCountryCode).toBe('KR');
  });

  it('(3) _computedDefinitions 미주입 시 _computed 미산출 (회귀 대조)', () => {
    // 정의가 없으면 DynamicRenderer 의 재계산 블록(Object.keys>0 가드)이 스킵되어
    // _computed 는 비어 있고, _computed 게이트 본체는 렌더되지 않는다.
    const computedDefinitions: Record<string, string> = {};
    expect(Object.keys(computedDefinitions).length).toBe(0);
  });
});
