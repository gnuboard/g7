/**
 * boxIntersectsFrame 회귀 테스트
 *
 * 닫힌 모바일 드로어(`fixed translate-x-full`)처럼 frame 밖으로 밀려 overflow:hidden
 * 으로 시각적으로 가려진 노드는 getBoundingClientRect 좌표가 그대로라 오버레이
 * 레이어(overflow:visible)가 그 자리에 드래그 핸들/점선/박스를 그려 편집기 회색
 * 배경에 노출시킨다. boxIntersectsFrame 으로 frame 가시 영역과 겹치지 않는 박스를
 * 걸러냄을 가드한다.
 */

import { describe, it, expect, vi } from 'vitest';
import { boxIntersectsFrame, type OverlayBox } from '../../utils/overlayGeometry';

function box(left: number, top: number, width: number, height: number): OverlayBox {
  return { left, top, width, height, scale: 1 };
}

/** width×height frame 의 getBoundingClientRect 모킹 element */
function frameOf(width: number, height: number): Element {
  const el = document.createElement('div');
  vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({
    left: 0,
    top: 0,
    width,
    height,
    right: width,
    bottom: height,
    x: 0,
    y: 0,
    toJSON: () => ({}),
  } as DOMRect);
  return el;
}

describe('boxIntersectsFrame', () => {
  const frame = frameOf(400, 800);

  it('frame 안에 완전히 들어온 박스 → true', () => {
    expect(boxIntersectsFrame(box(10, 10, 100, 100), frame)).toBe(true);
  });

  it('frame 우측 밖으로 완전히 밀린 박스(닫힌 드로어 translate-x-full) → false', () => {
    // left=400 (frame width) 이상 → 우측 밖
    expect(boxIntersectsFrame(box(400, 0, 320, 800), frame)).toBe(false);
    expect(boxIntersectsFrame(box(460, 100, 288, 158), frame)).toBe(false);
  });

  it('frame 좌측 밖으로 완전히 밀린 박스 → false', () => {
    expect(boxIntersectsFrame(box(-320, 0, 320, 800), frame)).toBe(false);
  });

  it('frame 하단 밖 박스 → false', () => {
    expect(boxIntersectsFrame(box(0, 800, 400, 100), frame)).toBe(false);
  });

  it('frame 상단 밖 박스 → false', () => {
    expect(boxIntersectsFrame(box(0, -100, 400, 100), frame)).toBe(false);
  });

  it('가장자리에 부분 교차한 박스(우측에 일부 걸침) → true (정상 노드 보존)', () => {
    // left=380, width 100 → 우측 80px 는 밖이지만 frame 안에 20px 걸침
    expect(boxIntersectsFrame(box(380, 10, 100, 100), frame)).toBe(true);
  });

  it('box null → false', () => {
    expect(boxIntersectsFrame(null, frame)).toBe(false);
  });

  it('frameEl null → true (판정 불가 시 보수적으로 필터 비적용)', () => {
    expect(boxIntersectsFrame(box(9999, 9999, 10, 10), null)).toBe(true);
  });
});
