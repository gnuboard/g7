/**
 * 엔진 병합 함수 번들 — 엔진 원본과 같은 결과, 공용 픽스처 전 사례, 확장 앞글자 계산.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';
import { REPO_ROOT, loadExtensionPrefixes, loadMergeEngine } from '../lib/merge-engine.mjs';

const fixture = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'tests', 'Fixtures', 'class-merge', 'cases.json'), 'utf8'));

function fnsOf(testCase) {
  if (testCase.fn === 'both') return ['mergeClasses', 'applyResponsiveClassMerge'];
  return [testCase.fn === 'applyResponsive' ? 'applyResponsiveClassMerge' : testCase.fn];
}

describe('엔진 병합 함수 번들', () => {
  it('공용 픽스처가 비어 있지 않다', () => {
    expect(fixture.cases.length).toBeGreaterThan(20);
  });

  it.each(fixture.cases.map((c) => [c.id, c]))('공용 픽스처 %s', async (_id, testCase) => {
    const engine = await loadMergeEngine({ prefixes: fixture.prefixes });
    for (const fn of fnsOf(testCase)) {
      expect(engine[fn](testCase.base, testCase.override), `${fn}`).toBe(testCase.expected);
    }
  });

  it('엔진 원본(TS 직접 import)과 모든 픽스처 입력에서 같은 결과를 낸다', async () => {
    const engine = await loadMergeEngine({ prefixes: fixture.prefixes });
    const original = await import('../../../resources/js/core/template-engine/helpers/StyleHelpers.ts');
    const hadWindow = 'window' in globalThis;
    const saved = globalThis.window;
    globalThis.window = { G7Config: { extensionClassPrefixes: fixture.prefixes } };
    try {
      for (const c of fixture.cases) {
        for (const fn of ['mergeClasses', 'applyResponsiveClassMerge']) {
          expect(engine[fn](c.base, c.override), `${c.id} ${fn}`).toBe(original[fn](c.base, c.override));
        }
      }
    } finally {
      if (hadWindow) globalThis.window = saved;
      else delete globalThis.window;
    }
  });

  it('앞글자 목록은 번들 안에서만 쓰이고 전역 window 를 만들지 않는다', async () => {
    const engine = await loadMergeEngine({ prefixes: ['ecommerce'] });
    expect(engine.splitExtensionClassPrefix('ecommerce:p-4')).toEqual({ prefix: 'ecommerce:', rest: 'p-4' });
    expect(engine.splitExtensionClassPrefix('board:p-4')).toEqual({ prefix: '', rest: 'board:p-4' });
    expect('window' in globalThis).toBe(false);
  });
});

describe('확장 앞글자 계산 (ExtensionClassPrefixes 와 같은 계산)', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'g7-classmerge-prefix-'));
  afterAll(() => fs.rmSync(tmp, { recursive: true, force: true }));

  const manifest = (dir, file, json) => {
    fs.mkdirSync(path.join(tmp, dir), { recursive: true });
    fs.writeFileSync(path.join(tmp, dir, file), JSON.stringify(json));
  };

  it('namespace 에서 영문 소문자만 남기고, 빈 값·없는 값은 빼고, 중복은 한 번만 둔다', () => {
    manifest('modules/_bundled/a-shop', 'module.json', { assets: { css: { namespace: 'ecommerce' } } });
    manifest('modules/_bundled/b-none', 'module.json', { assets: { css: 'dist/x.css' } });
    manifest('modules/_bundled/c-empty', 'module.json', { assets: { css: { namespace: '' } } });
    manifest('modules/_bundled/d-dup', 'module.json', { assets: { css: { namespace: 'e-commerce' } } });
    manifest('plugins/_bundled/a-pay', 'plugin.json', { assets: { css: { namespace: 'pay-kginicis' } } });
    manifest('plugins/_bundled/b-upper', 'plugin.json', { assets: { css: { namespace: 'Foo1bar' } } });
    manifest('plugins/_bundled/c-digits', 'plugin.json', { assets: { css: { namespace: '123' } } });
    expect(loadExtensionPrefixes(tmp)).toEqual(['ecommerce', 'paykginicis', 'oobar']);
  });

  it('저장소 번들 확장에서 ecommerce·board 를 찾는다', () => {
    const prefixes = loadExtensionPrefixes();
    expect(prefixes).toContain('ecommerce');
    expect(prefixes).toContain('board');
  });
});
