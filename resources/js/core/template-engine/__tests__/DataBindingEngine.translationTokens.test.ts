/**
 * DataBindingEngine.translationTokens.test.ts
 *
 * 표현식 결과 문자열의 `$t:` 토큰 2차 해석 회귀 가드.
 *
 * 결함: `{{route.id ? '$t:board.edit' : '$t:board.write'}}` 형태의 삼항 표현식 평가
 * 결과 문자열이 `$t:board.write` 같은 raw 키로 노출되던 결함. 원인은 `$t` 함수가
 * `context.$templateId` 미존재 시 `window.__templateApp.getConfig()` 로 fallback
 * 하는데, 편집기 격리 store 의 façade 에는 `getConfig` 가 없어 templateId 가
 * 빈 문자열로 떨어져 사전 조회 실패.
 *
 * 본 테스트는 4단계 절차의 1단계(재현) — 수정 전 fail, 수정 후 green.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';

describe('DataBindingEngine — 표현식 결과 $t: 토큰 2차 해석', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
    TranslationEngine.resetInstance();
    translationEngine = TranslationEngine.getInstance();

    // 편집 대상 템플릿 사전 누적 (편집기에서 useEditorTemplateAssets 가 하는 동작 시뮬레이션)
    (translationEngine as any).translations.set('sirsoft-basic:ko', {
      board: {
        write: '글쓰기',
        edit: '글 수정',
        form: { submit: '등록' },
      },
    });
  });

  afterEach(() => {
    TranslationEngine.resetInstance();
    vi.restoreAllMocks();
  });

  it('context.$templateId 가 명시되면 삼항 표현식의 `$t:` 토큰이 정확히 해석된다', () => {
    // 글쓰기 라우트: route.id 미존재 → false 분기 → $t:board.write → "글쓰기"
    const expr = "route.id ? '$t:board.edit' : '$t:board.write'";
    const context = {
      route: {},
      $templateId: 'sirsoft-basic',
      $locale: 'ko',
    };
    const result = bindingEngine.evaluateExpression(expr, context);
    expect(result).toBe('글쓰기');
  });

  it('글 수정 라우트: route.id 존재 → true 분기 → $t:board.edit → "글 수정"', () => {
    const expr = "route.id ? '$t:board.edit' : '$t:board.write'";
    const context = {
      route: { id: '42' },
      $templateId: 'sirsoft-basic',
      $locale: 'ko',
    };
    const result = bindingEngine.evaluateExpression(expr, context);
    expect(result).toBe('글 수정');
  });

  it('window.__templateApp.getConfig() 가 없는 격리 환경에서도 context.$templateId 우선 사용 (회귀 가드)', () => {
    // 편집기 격리 store 환경: getConfig 미제공
    const originalTemplateApp = (window as any).__templateApp;
    (window as any).__templateApp = {
      // getConfig 의도적 미제공 — 격리 façade 시뮬레이션
      getGlobalState: () => ({}),
    };
    try {
      const expr = "true ? '$t:board.form.submit' : 'fallback'";
      const context = {
        $templateId: 'sirsoft-basic',
        $locale: 'ko',
      };
      const result = bindingEngine.evaluateExpression(expr, context);
      expect(result).toBe('등록');
    } finally {
      (window as any).__templateApp = originalTemplateApp;
    }
  });

  it('context.$templateId 미제공 + getConfig 도 없는 경우: raw key 반환 (현재 동작)', () => {
    // 격리 환경에서 $templateId 도 안 들어가면 폴백 실패 — 본 케이스는 PreviewCanvas
    // 가 dataContext 에 $templateId 명시 주입으로 해소되어야 함
    const originalTemplateApp = (window as any).__templateApp;
    (window as any).__templateApp = {};
    try {
      const expr = "true ? '$t:board.write' : 'fallback'";
      const context = {};
      const result = bindingEngine.evaluateExpression(expr, context);
      // $templateId 도 없고 getConfig 도 없으면 templateId = '' → 사전 미발견 → key 반환
      expect(result).toBe('board.write');
    } finally {
      (window as any).__templateApp = originalTemplateApp;
    }
  });

  it('일반 사이트 렌더 경로(window.__templateApp.getConfig 제공): 종전과 동일하게 풀린다 (회귀 가드)', () => {
    const originalTemplateApp = (window as any).__templateApp;
    (window as any).__templateApp = {
      getConfig: () => ({ templateId: 'sirsoft-basic', locale: 'ko' }),
    };
    try {
      const expr = "true ? '$t:board.write' : 'fallback'";
      const context = {}; // $templateId 미명시 — fallback 경로
      const result = bindingEngine.evaluateExpression(expr, context);
      expect(result).toBe('글쓰기');
    } finally {
      (window as any).__templateApp = originalTemplateApp;
    }
  });
});
