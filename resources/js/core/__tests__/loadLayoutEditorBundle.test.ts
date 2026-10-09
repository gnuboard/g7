// e2e:allow Vitest 테스트 파일의 훅 예산 조정만 — 제품 코드 변화 0 이라 브라우저에서 밟을 분기가 없다. 이 파일 자신이 로더 계약의 회귀 테스트다.
/**
 * loadLayoutEditorBundle 테스트 — 편집기 lazy 번들 로더 (engine-v1.51.0)
 *
 * 편집기는 별도 번들(layout-editor.min.js)로 분리되어 `/admin/layout-editor/*` 진입 시에만
 * <script> 주입으로 로드된다. 본 테스트는 로더의 계약을 검증한다:
 * - 이미 로드됨(__LayoutEditorChrome 존재) → 즉시 반환, <script> 미주입 (멱등)
 * - 미로드 → <script> 1회 주입, load 이벤트 후 컴포넌트 반환
 * - 동시 다중 호출 → in-flight promise 병합 (중복 주입 없음)
 * - 로드 실패(error) → 재시도(기본 3시도) 후 reject + 재시도 가능
 * - 실패 문구에 번들 경로 미노출 (engine-v1.63.0)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

describe('loadLayoutEditorBundle — 편집기 lazy 번들 로더', () => {
  let appendedScripts: HTMLScriptElement[];
  let originalAppendChild: typeof document.head.appendChild;
  // 모듈 레벨 in-flight promise 가 테스트 간 잔존하지 않도록 매 테스트 모듈 재로드
  let loadLayoutEditorBundle: () => Promise<any>;

  beforeEach(async () => {
    appendedScripts = [];
    (window as any).G7Core = {};
    (window as any).G7Config = { coreEditorAsset: '/build/core/layout-editor.min.js?v=123' };

    // 기존 주입 스크립트 제거
    document.getElementById('g7-layout-editor-bundle')?.remove();

    // head.appendChild 를 가로채 주입된 <script> 를 기록 (실제 네트워크 로드 없음)
    originalAppendChild = document.head.appendChild.bind(document.head);
    vi.spyOn(document.head, 'appendChild').mockImplementation((node: any) => {
      if (node.tagName === 'SCRIPT') {
        appendedScripts.push(node);
        return node;
      }
      return originalAppendChild(node);
    });

    // 모듈 재로드로 layoutEditorLoadPromise 초기화 (테스트 격리)
    vi.resetModules();
    ({ loadLayoutEditorBundle } = await import('../template-engine'));
    // 이 훅만 예산을 따로 준다 — `vi.resetModules()` 가 그래프를 통째로 무효화하므로
    // 매 테스트가 template-engine 배럴(엔진 전량)을 다시 변환·임포트한다. 격리를 포기하면
    // 모듈 레벨 in-flight promise 가 테스트 사이에 새어 단언 자체가 무의미해지므로 줄이는
    // 것은 답이 아니다. 단독 실행은 3초 안쪽이지만 전량 병렬 실행에서는 기본 10초를 넘어
    // 통과/실패가 동시 실행 부하로 갈린다 — 그러면 게이트 구실을 못 한다.
  }, 30_000);

  afterEach(() => {
    vi.restoreAllMocks();
    document.getElementById('g7-layout-editor-bundle')?.remove();
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  /**
   * 이미 로드된 번들은 다시 주입하지 않는다.
   *
   * @scenario state=already_loaded
   * @effects loader_returns_immediately_when_already_loaded
   */
  it('이미 로드됨 → 즉시 반환하고 <script> 를 주입하지 않는다', async () => {
    const fakeChrome = () => null;
    (window as any).G7Core.__LayoutEditorChrome = fakeChrome;

    const result = await loadLayoutEditorBundle();

    expect(result).toBe(fakeChrome);
    expect(appendedScripts).toHaveLength(0);
  });

  /**
   * 미로드 상태면 스크립트를 한 번 주입하고 컴포넌트를 돌려준다.
   *
   * @scenario state=not_loaded
   * @effects loader_injects_script_once_and_returns_component
   */
  it('미로드 → <script> 를 1회 주입하고 load 후 컴포넌트를 반환한다', async () => {
    const fakeChrome = () => null;
    const promise = loadLayoutEditorBundle();

    // 정확히 1개 주입
    expect(appendedScripts).toHaveLength(1);
    const script = appendedScripts[0];
    expect(script.id).toBe('g7-layout-editor-bundle');
    expect(script.src).toContain('layout-editor.min.js');
    expect(script.async).toBe(false);

    // 편집기 번들 로드 시뮬레이션: 컴포넌트 노출 + load 이벤트
    (window as any).G7Core.__LayoutEditorChrome = fakeChrome;
    script.dispatchEvent(new Event('load'));

    await expect(promise).resolves.toBe(fakeChrome);
  });

  /**
   * 동시에 여러 번 불러도 진행 중인 로드 하나로 합친다.
   *
   * @scenario state=not_loaded
   * @effects concurrent_calls_share_one_in_flight_load
   */
  it('동시 다중 호출 → in-flight promise 로 병합되어 <script> 는 1회만 주입된다', async () => {
    const fakeChrome = () => null;

    const p1 = loadLayoutEditorBundle();
    const p2 = loadLayoutEditorBundle();
    const p3 = loadLayoutEditorBundle();

    // 세 호출이 하나의 주입으로 병합
    expect(appendedScripts).toHaveLength(1);

    (window as any).G7Core.__LayoutEditorChrome = fakeChrome;
    appendedScripts[0].dispatchEvent(new Event('load'));

    const [r1, r2, r3] = await Promise.all([p1, p2, p3]);
    expect(r1).toBe(fakeChrome);
    expect(r2).toBe(fakeChrome);
    expect(r3).toBe(fakeChrome);
  });

  /**
   * 로드 실패는 세 번 시도한 뒤 거부한다.
   *
   * @scenario state=load_failed
   * @effects load_failure_rejects_after_three_attempts
   */
  it('로드 실패 → 3시도 후 reject 한다 (재시도 계층)', async () => {
    vi.useFakeTimers();

    const promise = loadLayoutEditorBundle();
    const rejection = expect(promise).rejects.toThrow(/불러오지 못했습니다/);

    // 1회차 실패 → 백오프 대기 후 2회차 주입 → … 총 3회 시도
    for (let attempt = 1; attempt <= 3; attempt++) {
      expect(appendedScripts).toHaveLength(attempt);
      appendedScripts[attempt - 1].dispatchEvent(new Event('error'));
      await vi.advanceTimersByTimeAsync(3000);
    }

    expect(appendedScripts).toHaveLength(3);
    await rejection;

    vi.useRealTimers();
  });

  /**
   * 실패 문구에 내부 번들 경로가 드러나지 않는다.
   *
   * @scenario state=load_failed
   * @effects failure_message_hides_bundle_path
   */
  it('실패 문구에 번들 경로를 싣지 않는다', async () => {
    vi.useFakeTimers();

    const promise = loadLayoutEditorBundle();
    const captured = promise.catch((e: Error) => e);

    for (let attempt = 1; attempt <= 3; attempt++) {
      appendedScripts[attempt - 1].dispatchEvent(new Event('error'));
      await vi.advanceTimersByTimeAsync(3000);
    }

    const error = (await captured) as Error;
    expect(error.message).not.toContain('layout-editor.min.js');
    expect(error.message).not.toContain('/build/core');

    vi.useRealTimers();
  });

  /**
   * 실패 뒤 다시 부르면 진행 중 로드를 비우고 새로 주입한다.
   *
   * @scenario state=load_failed
   * @effects reload_after_failure_injects_again
   */
  it('실패 후 재호출하면 다시 주입한다 (in-flight promise 초기화)', async () => {
    vi.useFakeTimers();

    const first = loadLayoutEditorBundle();
    const firstSettled = first.catch(() => undefined);

    for (let attempt = 1; attempt <= 3; attempt++) {
      appendedScripts[attempt - 1].dispatchEvent(new Event('error'));
      await vi.advanceTimersByTimeAsync(3000);
    }
    await firstSettled;

    const fakeChrome = () => null;
    const second = loadLayoutEditorBundle();

    expect(appendedScripts).toHaveLength(4);
    (window as any).G7Core.__LayoutEditorChrome = fakeChrome;
    appendedScripts[3].dispatchEvent(new Event('load'));

    await expect(second).resolves.toBe(fakeChrome);

    vi.useRealTimers();
  });
  /**
   * 편집기 번들을 받지 못한 실패 문구는 화면 언어를 따른다 — 종전에는 영어 화면에서도 한국어로 박혀 있었다
   * (7.1.0 J O-J4, 2026-10-07 재실측: html lang=en 에서 「편집기를 불러오지 못했습니다 …」).
   *
   * @scenario state=load_failed
   * @effects failure_message_follows_screen_language
   */
  it('실패 문구는 화면 언어(<html lang>)를 따른다', async () => {
    vi.useFakeTimers();
    const previousLang = document.documentElement.lang;
    document.documentElement.lang = 'en';

    const promise = loadLayoutEditorBundle();
    const captured = promise.catch((e: Error) => e);

    for (let attempt = 1; attempt <= 3; attempt++) {
      appendedScripts[attempt - 1].dispatchEvent(new Event('error'));
      await vi.advanceTimersByTimeAsync(3000);
    }

    const error = (await captured) as Error;
    expect(error.message).toBe('The editor could not be loaded. Check your network connection and try again.');
    expect(error.message).not.toMatch(/[가-힣]/);

    document.documentElement.lang = previousLang;
    vi.useRealTimers();
  });
});
