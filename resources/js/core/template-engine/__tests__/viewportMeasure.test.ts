/**
 * viewportMeasure — 엔진이 반응형 판정에 쓸 화면 폭 측정
 *
 * 회귀 배경(engine-v1.97.1): 휴대폰에서 다른 앱(카카오톡 등)으로 로그인·결제·본인확인을 하고 브라우저로
 * 돌아오면 사이트가 PC 화면으로 축소되어 굳었다. 엔진이 `window.innerWidth`(보이는 영역 폭)만 읽었는데,
 * PC 화면이 한 번 그려지면 모바일 브라우저가 화면을 축소해 `innerWidth` 가 부풀고(390 → 909·980),
 * 다음 resize 에서도 그 부푼 값을 다시 읽어 PC·태블릿 폭에 머물렀다.
 *
 * 판정 규칙: 평소에는 `innerWidth`(PC 스크롤 막대·브라우저 확대·소수 배율에서 지금과 같은 값),
 * CSS 폭(미디어 쿼리 기준)이 `innerWidth` 와 어긋나고 레이아웃 기준 폭(`clientWidth`)과 맞으면 레이아웃 기준 폭.
 *
 * 배경: 내부 트러블슈팅 사례 「확장 코드의 화면 폭 판정 관련 이슈」 사례 2
 * @scenario-manifest tests/scenarios/responsive-viewport-background-return.yaml
 */

import { describe, it, expect, vi } from 'vitest';
import { measureViewportWidth, VIEWPORT_MATCH_TOLERANCE_PX } from '../viewportMeasure';

/**
 * 질의의 `(min-width: Apx)`·`(max-width: Bpx)` 를 읽어 cssWidth 와 비교하는 가짜 matchMedia 를 만든다.
 *
 * @param cssWidth 미디어 쿼리가 보는 폭(px)
 * @return 호출 기록이 남는 가짜 matchMedia
 */
function fakeMatchMedia(cssWidth: number) {
  return vi.fn((query: string) => {
    const min = /min-width:\s*([\d.]+)px/.exec(query);
    const max = /max-width:\s*([\d.]+)px/.exec(query);
    const okMin = min ? cssWidth >= Number(min[1]) : true;
    const okMax = max ? cssWidth <= Number(max[1]) : true;

    return { matches: okMin && okMax };
  });
}

/**
 * 측정 대상 창 모양을 만든다.
 *
 * @param iw innerWidth
 * @param cw documentElement.clientWidth
 * @param matchMedia 가짜 matchMedia (없으면 생략)
 * @return 측정 함수에 넘길 창 모양
 */
function fakeWin(iw: number, cw: number, matchMedia?: any) {
  return {
    innerWidth: iw,
    document: { documentElement: { clientWidth: cw } },
    ...(matchMedia !== undefined ? { matchMedia } : {}),
  };
}

describe('measureViewportWidth — 엔진 화면 폭 판정 (engine-v1.97.1)', () => {
  // @scenario case=viewport_measure
  // @effects engine_width_uses_inner_width_without_layout_width
  it('V1 레이아웃 폭이 0 이면 innerWidth', () => {
    expect(measureViewportWidth(fakeWin(1280, 0))).toBe(1280);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_null_when_unmeasurable
  it('V2 둘 다 0 이면 null', () => {
    expect(measureViewportWidth(fakeWin(0, 0))).toBeNull();
    expect(measureViewportWidth(fakeWin(Number.NaN, Number.NaN))).toBeNull();
  });

  // @scenario case=viewport_measure
  // @effects engine_width_equal_values_skip_media_query
  it('V3 두 값이 같으면 matchMedia 를 부르지 않는다', () => {
    const mm = fakeMatchMedia(390);

    expect(measureViewportWidth(fakeWin(390, 390, mm))).toBe(390);
    expect(mm).not.toHaveBeenCalled();
  });

  // @scenario case=viewport_measure
  // @effects engine_width_keeps_inner_width_on_desktop
  it('V4 PC 스크롤 막대 — innerWidth 유지', () => {
    expect(measureViewportWidth(fakeWin(1920, 1903, fakeMatchMedia(1920)))).toBe(1920);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_keeps_inner_width_on_desktop
  it('V5 소수 배율 경계 — innerWidth 1023 유지', () => {
    expect(measureViewportWidth(fakeWin(1023, 1006, fakeMatchMedia(1023.00004)))).toBe(1023);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_keeps_inner_width_on_desktop
  it('V6 브라우저 확대(Ctrl +) — innerWidth 유지', () => {
    expect(measureViewportWidth(fakeWin(1280, 1265, fakeMatchMedia(1280.4)))).toBe(1280);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_uses_layout_width_when_zoomed_out
  it('V7 폰 화면 축소로 부푼 innerWidth 대신 레이아웃 폭', () => {
    expect(measureViewportWidth(fakeWin(980, 390, fakeMatchMedia(390)))).toBe(390);
    // 실측(Chrome 모바일 흉내): PC DOM 이 그려져 가로 넘침 519px → innerWidth 909, 레이아웃 390
    expect(measureViewportWidth(fakeWin(909, 390, fakeMatchMedia(390)))).toBe(390);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_falls_back_to_inner_width
  it('V8 matchMedia 가 늘 false · undefined 반환 · 예외 · 없음 → innerWidth', () => {
    expect(measureViewportWidth(fakeWin(1280, 1265, vi.fn(() => ({ matches: false }))))).toBe(1280);
    expect(measureViewportWidth(fakeWin(1280, 1265, vi.fn(() => undefined)))).toBe(1280);
    expect(
      measureViewportWidth(
        fakeWin(
          1280,
          1265,
          vi.fn(() => {
            throw new Error('matchMedia unavailable');
          })
        )
      )
    ).toBe(1280);
    expect(measureViewportWidth(fakeWin(1280, 1265))).toBe(1280);
    expect(measureViewportWidth(fakeWin(1280, 1265, null))).toBe(1280);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_uses_layout_width_when_zoomed_out
  it('V9 innerWidth 1 이면 min-width 0 으로 질의한다', () => {
    const mm = fakeMatchMedia(390);

    expect(measureViewportWidth(fakeWin(1, 390, mm))).toBe(390);
    expect(mm.mock.calls.map(([q]) => q).some((q: string) => q.includes('min-width: 0px'))).toBe(true);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_keeps_inner_width_on_desktop
  it('허용 오차는 2px 이다', () => {
    expect(VIEWPORT_MATCH_TOLERANCE_PX).toBe(2);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_uses_layout_width_when_zoomed_out
  it('손가락 확대로 innerWidth 가 줄어든 상태(200)도 레이아웃 폭(390)을 쓴다', () => {
    expect(measureViewportWidth(fakeWin(200, 390, fakeMatchMedia(390)))).toBe(390);
  });

  // @scenario case=viewport_measure
  // @effects engine_width_uses_inner_width_without_layout_width
  it('소수 innerWidth 는 반올림한 정수로 돌려준다', () => {
    expect(measureViewportWidth(fakeWin(1023.4, 0))).toBe(1023);
  });
});
