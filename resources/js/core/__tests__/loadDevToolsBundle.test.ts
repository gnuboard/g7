/**
 * loadDevToolsBundle 테스트 — DevTools lazy 번들 로더 (engine-v1.52.0)
 *
 * DevTools 디버그 전용 모듈(패널/진단엔진/서버커넥터/스타일추적기)은 별도 번들
 * (devtools.min.js)로 분리되어 디버그 모드에서만 <script> 주입으로 로드된다. 본 테스트는
 * 로더 계약을 검증한다:
 * - 이미 로드됨(__devtools 존재) → 즉시 반환, <script> 미주입 (멱등)
 * - 미로드 → <script> 1회 주입, load 이벤트 후 모듈 묶음 반환
 * - 동시 다중 호출 → in-flight promise 병합
 * - 로드 실패(error) → reject
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

describe('loadDevToolsBundle — DevTools lazy 번들 로더', () => {
  let appendedScripts: HTMLScriptElement[];
  let originalAppendChild: typeof document.head.appendChild;
  let loadDevToolsBundle: () => Promise<any>;

  const fakeBundle = {
    DiagnosticEngine: class {},
    getServerConnector: () => ({}),
    getStyleTracker: () => ({}),
    DevToolsPanel: () => null,
  };

  beforeEach(async () => {
    appendedScripts = [];
    (window as any).G7Core = {};
    (window as any).G7Config = { coreDevToolsAsset: '/build/core/devtools.min.js?v=1' };

    document.getElementById('g7-devtools-bundle')?.remove();

    originalAppendChild = document.head.appendChild.bind(document.head);
    vi.spyOn(document.head, 'appendChild').mockImplementation((node: any) => {
      if (node.tagName === 'SCRIPT') {
        appendedScripts.push(node);
        return node;
      }
      return originalAppendChild(node);
    });

    vi.resetModules();
    ({ loadDevToolsBundle } = await import('../template-engine/G7CoreGlobals'));
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.getElementById('g7-devtools-bundle')?.remove();
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  it('이미 로드됨 → 즉시 반환하고 <script> 를 주입하지 않는다', async () => {
    (window as any).G7Core.__devtools = fakeBundle;

    const result = await loadDevToolsBundle();

    expect(result).toBe(fakeBundle);
    expect(appendedScripts).toHaveLength(0);
  });

  it('미로드 → <script> 1회 주입 후 load 시 모듈 묶음 반환', async () => {
    const promise = loadDevToolsBundle();

    expect(appendedScripts).toHaveLength(1);
    const script = appendedScripts[0];
    expect(script.id).toBe('g7-devtools-bundle');
    expect(script.src).toContain('devtools.min.js');
    expect(script.async).toBe(false);

    (window as any).G7Core.__devtools = fakeBundle;
    script.dispatchEvent(new Event('load'));

    await expect(promise).resolves.toBe(fakeBundle);
  });

  it('동시 다중 호출 → <script> 1회만 주입', async () => {
    const p1 = loadDevToolsBundle();
    const p2 = loadDevToolsBundle();

    expect(appendedScripts).toHaveLength(1);

    (window as any).G7Core.__devtools = fakeBundle;
    appendedScripts[0].dispatchEvent(new Event('load'));

    const [r1, r2] = await Promise.all([p1, p2]);
    expect(r1).toBe(fakeBundle);
    expect(r2).toBe(fakeBundle);
  });

  it('로드 실패(error) → reject 한다', async () => {
    const promise = loadDevToolsBundle();
    expect(appendedScripts).toHaveLength(1);

    appendedScripts[0].dispatchEvent(new Event('error'));

    await expect(promise).rejects.toThrow(/DevTools 번들 로드 실패/);
  });
});
