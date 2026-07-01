/**
 * registerEditorWidgets.test.ts — 편집기 커스텀 위젯 등록
 *
 * 배경: icon-picker 등록이 `initTemplate`의 `registerHandlers`(window.load + ActionDispatcher
 * 재시도 게이트) 안에 묶여 있어, 편집기 URL 을 직접 하드로드한 경로에서 등록이 편집기 셸 마운트보다
 * 늦어 위젯이 누락("Unsupported control")됐다. 등록은 ActionDispatcher 가용과 무관하게
 * `G7Core.layoutEditor` 예약 접수함(ready 큐 stub)으로 즉시 수행돼야 한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { registerSirsoftBasicEditorWidgets } from '../registerEditorWidgets';

declare const window: Window & typeof globalThis & { G7Core?: Record<string, unknown> };

const __dir = dirname(fileURLToPath(import.meta.url));
const INDEX_TS = resolve(__dir, '../../index.ts');

describe(' — basic 편집기 위젯 등록 (ActionDispatcher 무관)', () => {
  beforeEach(() => {
    delete window.G7Core;
  });
  afterEach(() => {
    delete window.G7Core;
  });

  it('실제 레지스트리가 있으면 icon-picker 를 즉시 등록한다', () => {
    const registerWidget = vi.fn();
    window.G7Core = { layoutEditor: { registerWidget } };

    registerSirsoftBasicEditorWidgets();

    expect(registerWidget).toHaveBeenCalledTimes(1);
    expect(registerWidget.mock.calls[0][0]).toBe('icon-picker');
    expect(typeof registerWidget.mock.calls[0][1]).toBe('function');
  });

  it('ActionDispatcher 부재 + ready 큐 stub 만 있어도 등록 호출이 흘러간다', () => {
    const queue: Array<[string, ...unknown[]]> = [];
    window.G7Core = {
      layoutEditor: {
        __isStub: true,
        __queue: queue,
        registerWidget: (name: string, comp: unknown) => queue.push(['widget', name, comp]),
      },
    };

    registerSirsoftBasicEditorWidgets();

    expect(queue).toHaveLength(1);
    expect(queue[0][0]).toBe('widget');
    expect(queue[0][1]).toBe('icon-picker');
  });

  it('G7Core.layoutEditor 부재 시 throw 없이 no-op', () => {
    window.G7Core = {};
    expect(() => registerSirsoftBasicEditorWidgets()).not.toThrow();
    window.G7Core = { layoutEditor: {} };
    expect(() => registerSirsoftBasicEditorWidgets()).not.toThrow();
  });

  it('index.ts 가 registerSirsoftBasicEditorWidgets 를 모듈 최상위에서 호출한다(핸들러 게이트 밖)', () => {
    const src = readFileSync(INDEX_TS, 'utf8');
    const lines = src.split(/\r?\n/);

    const fnStart = lines.findIndex((l) => /export function initTemplate\s*\(/.test(l));
    expect(fnStart).toBeGreaterThanOrEqual(0);
    let depth = 0;
    let fnEnd = -1;
    for (let i = fnStart; i < lines.length; i++) {
      depth += (lines[i].match(/{/g) || []).length;
      depth -= (lines[i].match(/}/g) || []).length;
      if (i > fnStart && depth === 0) {
        fnEnd = i;
        break;
      }
    }
    expect(fnEnd).toBeGreaterThan(fnStart);

    const invocationLines = lines
      .map((l, i) => ({ l, i }))
      .filter(({ l }) => /registerSirsoftBasicEditorWidgets\s*\(\s*\)/.test(l))
      .filter(({ l }) => !/^\s*import\b/.test(l));
    expect(invocationLines.length).toBeGreaterThan(0);
    for (const { i } of invocationLines) {
      expect(i < fnStart || i > fnEnd).toBe(true);
    }
  });
});
