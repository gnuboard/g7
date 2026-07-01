/**
 * registerEditorWidgets.test.ts — 편집기 커스텀 위젯 등록
 *
 * 배경: icon-picker 등록이 `initTemplate`의 `registerHandlers`(window.load + ActionDispatcher
 * 재시도 게이트) 안에 묶여 있어, 편집기 URL 을 직접 하드로드한 경로에서 등록이 편집기 셸 마운트보다
 * 늦어 위젯이 누락("Unsupported control")됐다. 등록은 ActionDispatcher 가용과 무관하게
 * `G7Core.layoutEditor` 예약 접수함(ready 큐 stub)으로 즉시 수행돼야 한다.
 *
 * 본 테스트는 등록 함수가:
 *  1. 실제 레지스트리(`registerWidget`)가 있으면 즉시 `icon-picker` 를 등록한다.
 *  2. ready 큐 stub(register* 가 큐에 적재) 만 있어도 등록 호출을 흘려보낸다(ActionDispatcher 불필요).
 *  3. `G7Core.layoutEditor` 부재 시 throw 없이 no-op.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { registerSirsoftAdminBasicEditorWidgets } from '../registerEditorWidgets';

const __dir = dirname(fileURLToPath(import.meta.url));
const INDEX_TS = resolve(__dir, '../../index.ts');

declare const window: Window & typeof globalThis & { G7Core?: Record<string, unknown> };

describe(' — admin 편집기 위젯 등록 (ActionDispatcher 무관)', () => {
  beforeEach(() => {
    delete window.G7Core;
  });
  afterEach(() => {
    delete window.G7Core;
  });

  it('실제 레지스트리가 있으면 icon-picker 를 즉시 등록한다', () => {
    const registerWidget = vi.fn();
    window.G7Core = { layoutEditor: { registerWidget } };

    registerSirsoftAdminBasicEditorWidgets();

    expect(registerWidget).toHaveBeenCalledTimes(1);
    expect(registerWidget.mock.calls[0][0]).toBe('icon-picker');
    expect(typeof registerWidget.mock.calls[0][1]).toBe('function');
  });

  /**
   * 부록4-bis — registerCanvasOverlay('tabnav') 레퍼런스 등록 가드.
   * @scenario unit=overlay_register
   * @effects template_registerCanvasOverlay_mounts_in_live_editor
   */
  it('registerCanvasOverlay 가 있으면 tabnav 오버레이를 등록한다', () => {
    const registerWidget = vi.fn();
    const registerCanvasOverlay = vi.fn();
    window.G7Core = { layoutEditor: { registerWidget, registerCanvasOverlay } };

    registerSirsoftAdminBasicEditorWidgets();

    expect(registerCanvasOverlay).toHaveBeenCalledTimes(1);
    expect(registerCanvasOverlay.mock.calls[0][0]).toBe('tabnav');
    expect(typeof registerCanvasOverlay.mock.calls[0][1]).toBe('function');
  });

  it('registerCanvasOverlay 부재 시(구 코어) icon-picker 만 등록하고 throw 없음', () => {
    const registerWidget = vi.fn();
    window.G7Core = { layoutEditor: { registerWidget } }; // registerCanvasOverlay 없음
    expect(() => registerSirsoftAdminBasicEditorWidgets()).not.toThrow();
    expect(registerWidget).toHaveBeenCalledTimes(1);
  });

  it('ActionDispatcher 부재 + ready 큐 stub 만 있어도 등록 호출이 흘러간다', () => {
    // stub: register* 가 큐에 적재되는 형태(메인 번들 initLayoutEditorStub 동형).
    const queue: Array<[string, ...unknown[]]> = [];
    window.G7Core = {
      // getActionDispatcher 미정의 — 핸들러 경로와 독립임을 보장
      layoutEditor: {
        __isStub: true,
        __queue: queue,
        registerWidget: (name: string, comp: unknown) => queue.push(['widget', name, comp]),
      },
    };

    registerSirsoftAdminBasicEditorWidgets();

    expect(queue).toHaveLength(1);
    expect(queue[0][0]).toBe('widget');
    expect(queue[0][1]).toBe('icon-picker');
  });

  it('G7Core.layoutEditor 부재 시 throw 없이 no-op', () => {
    window.G7Core = {};
    expect(() => registerSirsoftAdminBasicEditorWidgets()).not.toThrow();
    window.G7Core = { layoutEditor: {} }; // registerWidget 없음
    expect(() => registerSirsoftAdminBasicEditorWidgets()).not.toThrow();
  });

  // 결함#3 핵심 회귀 가드: 등록 호출은 모듈 최상위(initTemplate/registerHandlers 바깥)에 있어야 한다.
  // window.load + ActionDispatcher 재시도 게이트 안에 있으면 편집기 직접 하드로드 시 등록이 늦어 누락된다.
  it('index.ts 가 registerSirsoftAdminBasicEditorWidgets 를 모듈 최상위에서 호출한다(핸들러 게이트 밖)', () => {
    const src = readFileSync(INDEX_TS, 'utf8');
    const lines = src.split(/\r?\n/);

    // initTemplate 함수 본문 범위 산출(중괄호 깊이 추적).
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

    const callLines = lines
      .map((l, i) => ({ l, i }))
      .filter(({ l }) => /registerSirsoftAdminBasicEditorWidgets\s*\(\s*\)/.test(l));
    // import 라인은 제외하고 실제 호출만.
    const invocationLines = callLines.filter(({ l }) => !/^\s*import\b/.test(l));
    expect(invocationLines.length).toBeGreaterThan(0);
    // 모든 실제 호출이 initTemplate 본문 밖(모듈 최상위)이어야 한다.
    for (const { i } of invocationLines) {
      expect(i < fnStart || i > fnEnd).toBe(true);
    }
  });
});
