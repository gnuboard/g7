/**
 * 파이프 분기 누락 검출 (구조 스캔)
 *
 * 사용자가 작성한 `{{...}}` 식을 `evaluateExpression` 으로 평가하는 지점은 반드시
 * 그 앞에서 `hasPipes()` 로 파이프 여부를 갈라야 한다. 갈라지 않으면 `|` 가 JS 비트 OR 로
 * 평가되어, 인자 있는 파이프는 예외(값 소실·원본 `{{...}}` 누출), 인자 없는 파이프는
 * 조용한 오답이 된다. #87 이 그 사례였고, 같은 결함이 엔진 곳곳에 복제되어 있었다.
 *
 * 손으로 열거한 케이스 목록은 "지금 아는 지점" 만 지킨다. 이 테스트는 반대로
 * **평가 지점 자체를 모집단으로 삼아** 새로 추가되는 지점까지 강제한다.
 * 의도적으로 파이프를 지원하지 않는 지점은 아래 EXEMPT 에 사유와 함께 등재한다.
 *
 * @since engine-v1.54.10
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 스캔 모집단은 `resources/js/core` 전체다.
 *
 * template-engine 디렉토리로 좁히면 `TemplateApp.ts` 처럼 밖에 있는 평가 지점이
 * 조용히 빠진다 — 모집단을 좁히는 순간 이 테스트는 통과하면서 아무것도 지키지 않는다.
 */
const ENGINE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/** 엔진 인스턴스를 통한 표현식 평가 호출 */
const EVAL_CALL = /\b(?:this\.)?(?:bindingEngine|dataBindingEngine|engine)\.evaluateExpression\s*\(/;

/**
 * 파이프 분기 가드가 있어야 하는 범위(호출 지점 기준 위쪽 라인 수).
 *
 * 가드는 보통 같은 if/else 체인의 첫 분기에 있고, 그 사이에 배경 설명 주석이 들어간다.
 * 좁게 잡으면 정상 지점이 위반으로 잡히므로 넉넉히 둔다 — 이 테스트의 목적은
 * "가드가 아예 없는 지점" 을 잡는 것이다.
 */
const GUARD_LOOKBEHIND = 30;

/**
 * 파이프를 의도적으로 지원하지 않는 지점.
 *
 * key: `<엔진 루트 기준 상대경로>::<해당 라인에 포함된 고유 문자열>`
 */
const EXEMPT: { file: string; marker: string; reason: string }[] = [
  {
    file: 'template-engine/DynamicRenderer.tsx',
    marker: 'blurTrackingInfo',
    reason:
      'blur_until_loaded 는 truthiness 게이트다. 파이프 결과는 대부분 비어있지 않은 문자열이라 ' +
      '분기를 추가하면 블러가 영구히 켜진다. 레이아웃 사용은 정적 검사로 차단한다.',
  },
  {
    file: 'template-engine/G7CoreGlobals.ts',
    marker: 'devToolsCore.getState()',
    reason: 'DevTools 콘솔의 임의 식 평가 — 레이아웃 작성 문법이 아니라 개발자 입력이다.',
  },
  {
    file: 'template-engine/TranslationEngine.ts',
    marker: 'dataBindingEngine.evaluateExpression(expression, dataContext)',
    reason:
      '`$t:key|name=값` 의 `|` 는 파라미터 구분자다. 이 자리에서 `|` 를 파이프로 해석하면 ' +
      '구분자와 충돌하므로 파이프를 지원하지 않는다(별개 문법 영역).',
  },
];

function collectSourceFiles(dir: string, acc: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === '__tests__' || entry.name === 'node_modules') continue;
      collectSourceFiles(full, acc);
      continue;
    }
    if (/\.(ts|tsx)$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) {
      acc.push(full);
    }
  }
  return acc;
}

interface CallSite {
  relative: string;
  line: number;
  text: string;
  guarded: boolean;
}

function collectCallSites(): CallSite[] {
  const sites: CallSite[] = [];
  for (const file of collectSourceFiles(ENGINE_ROOT)) {
    const relative = path.relative(ENGINE_ROOT, file).replace(/\\/g, '/');
    // 평가기 자신과 라우터는 스캔 대상이 아니다 —
    // DataBindingEngine 은 evaluateExpression/evaluatePipeExpression 의 구현체이고,
    // BindingShape 는 classifyExpression 으로 파이프를 먼저 갈라내는 라우터다.
    if (relative === 'template-engine/DataBindingEngine.ts') continue;
    if (relative === 'template-engine/BindingShape.ts') continue;

    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    lines.forEach((text, index) => {
      if (!EVAL_CALL.test(text)) return;
      const from = Math.max(0, index - GUARD_LOOKBEHIND);
      const guarded = lines.slice(from, index + 1).some((l) => l.includes('hasPipes('));
      sites.push({ relative, line: index + 1, text: text.trim(), guarded });
    });
  }
  return sites;
}

describe('파이프 분기 누락 검출 — evaluateExpression 호출 지점 전수 스캔', () => {
  const sites = collectCallSites();

  it('스캔 모집단이 비어 있지 않다 (스캐너 자체 회귀 방지)', () => {
    expect(sites.length).toBeGreaterThan(5);
  });

  it('모든 표현식 평가 지점이 파이프 분기를 갖거나 사유와 함께 면제되어 있다', () => {
    const violations = sites
      .filter((s) => !s.guarded)
      .filter(
        (s) =>
          !EXEMPT.some((e) => e.file === s.relative && s.text.includes(e.marker))
      )
      .map((s) => `${s.relative}:${s.line}  ${s.text}`);

    expect(violations).toEqual([]);
  });

  it('면제 목록의 각 항목이 실제 코드에 존재한다 (좀비 면제 방지)', () => {
    const stale = EXEMPT.filter(
      (e) => !sites.some((s) => s.relative === e.file && s.text.includes(e.marker))
    ).map((e) => `${e.file} :: ${e.marker}`);

    expect(stale).toEqual([]);
  });
});
