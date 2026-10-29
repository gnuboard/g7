/**
 * @file PopupOverlays.test.tsx
 * @description 이미지 위 글 상자 — 사용자 레이어·미리보기가 이미지 표시 사각형(프레임)에 상자를 % 로 겹쳐 그린다.
 * (매니페스트 image-overlays.yaml — layer_*, preview_*)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { PopupLayer } from '../../components/composite/PopupLayer';
import { PopupPreview } from '../../components/composite/PopupPreview';
import { resetClosedThisPage } from '../../lib/popupVisibility';
import type { PublicPopup } from '../../lib/types';
import type { PublicOverlayBox } from '../../lib/overlays';
import { installCore, setViewportMobile } from './core';

/** 공개 응답 상자 */
const box = (extra: Partial<PublicOverlayBox> = {}): PublicOverlayBox => ({
  id: 'a1b2c3d4', x: 8, y: 62, w: 84, text: '가을 할인\n전 상품 20%', size: 6.5,
  color: '#FFFFFF', bold: true, align: 'center', bg: { color: '#000000', opacity: 40 }, shadow: true, ...extra,
});

/** 글 상자가 있는 이미지형 팝업 */
const popup = (id: number, extra: Partial<PublicPopup> = {}): PublicPopup => ({
  id,
  content_type: 'image',
  page_scope: 'all',
  device: 'all',
  image: { pc: { url: `/img/pc-${id}.jpg`, width: 480, height: 520 }, mobile: { url: `/img/m-${id}.jpg`, width: 800, height: 800 } },
  image_alt: `대체 ${id}`,
  link_url: null,
  link_new_window: false,
  overlays: { pc: [box()], mobile: [box({ id: 'mobile01', y: 10, text: '모바일 글' })] },
  ...extra,
});

/** jsdom 에 없는 PointerEvent 대역 */
class TestPointerEvent extends MouseEvent {
  pointerId: number;
  pointerType: string;

  constructor(type: string, init: PointerEventInit = {}) {
    super(type, init);
    this.pointerId = init.pointerId ?? 1;
    this.pointerType = init.pointerType ?? 'mouse';
  }
}

/** 보이는 장의 상자들 */
const boxesIn = (container: HTMLElement) => [...container.querySelectorAll('.g7pp-slide:not([hidden]) .g7pp-overlay')] as HTMLElement[];

describe('이미지 위 글 상자 — 사용자 레이어', () => {
  beforeEach(() => {
    setViewportMobile(false);
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    installCore();
    resetClosedThisPage();
    (window as any).PointerEvent = TestPointerEvent;
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.useRealTimers();
    delete (window as any).G7Core;
  });

  // @scenario surface=layer_frame_ratio_from_image_size
  // @effects boxes_follow_image_scale
  it('응답의 이미지 크기로 프레임 비율을 정하고 이미지를 프레임 안에 둔다', () => {
    const { container } = render(<PopupLayer popups={[popup(1)]} settings={{ autoplay: false }} path="/" />);

    const frame = container.querySelector('.g7pp-media .g7pp-media__frame') as HTMLElement;
    expect(frame.style.getPropertyValue('--g7pp-ratio')).toBe(String(480 / 520));
    expect(frame.className).not.toContain('--unsized');
    expect(frame.querySelector('img.g7pp-media__img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=layer_frame_ratio_from_natural_size
  it('크기를 모르면 이미지를 받은 뒤 실제 크기로 비율을 정하고, 그 전에는 상자를 그리지 않는다', () => {
    const p = popup(1, { image: { pc: { url: '/img/x.jpg', width: null, height: null }, mobile: null } });
    const { container } = render(<PopupLayer popups={[p]} settings={{ autoplay: false }} path="/" />);

    const frame = container.querySelector('.g7pp-media__frame') as HTMLElement;
    expect(frame.className).toContain('g7pp-media__frame--unsized');
    expect(boxesIn(container)).toHaveLength(0);

    const img = frame.querySelector('img') as HTMLImageElement;
    Object.defineProperty(img, 'naturalWidth', { value: 1200, configurable: true });
    Object.defineProperty(img, 'naturalHeight', { value: 600, configurable: true });
    fireEvent.load(img);

    expect(frame.style.getPropertyValue('--g7pp-ratio')).toBe('2');
    expect(boxesIn(container)).toHaveLength(1);
  });

  // @scenario surface=layer_boxes_positioned_in_percent
  // @effects boxes_follow_image_scale
  it('상자는 프레임 기준 % 로 놓이고 저장 순서대로 겹친다(뒤가 위)', () => {
    const p = popup(1, { overlays: { pc: [box(), box({ id: 'second01', x: 0, y: 0, w: 50, text: '둘째' })], mobile: null } });
    const { container } = render(<PopupLayer popups={[p]} settings={{ autoplay: false }} path="/" />);

    const [first, second] = boxesIn(container);
    expect(first.parentElement?.className).toContain('g7pp-media__frame');
    expect([first.style.left, first.style.top, first.style.width]).toEqual(['8%', '62%', '84%']);
    expect(second.getAttribute('data-overlay-id')).toBe('second01');
    expect(first.textContent).toBe('가을 할인\n전 상품 20%');
  });

  // @scenario surface=layer_box_style_from_format
  it('서식이 스타일이 된다 — 글자 크기는 프레임 폭 단위 변수, 바탕은 진하기 rgba, 그림자는 text-shadow', () => {
    const p = popup(1, { overlays: { pc: [box(), box({ id: 'plainbox', bold: false, align: 'right', bg: null, shadow: false, color: '#112233', size: 3 })], mobile: null } });
    const { container } = render(<PopupLayer popups={[p]} settings={{ autoplay: false }} path="/" />);

    const [styled, plain] = boxesIn(container);
    expect(styled.style.getPropertyValue('--g7pp-ov-size')).toBe('6.5');
    expect(styled.style.fontWeight).toBe('700');
    expect(styled.style.textAlign).toBe('center');
    expect(styled.style.backgroundColor).toBe('rgba(0, 0, 0, 0.4)');
    expect(styled.style.textShadow).not.toBe('');
    expect(plain.style.getPropertyValue('--g7pp-ov-size')).toBe('3');
    expect(plain.style.fontWeight).toBe('400');
    expect(plain.style.textAlign).toBe('right');
    expect(plain.style.color).toBe('rgb(17, 34, 51)');
    expect(plain.style.backgroundColor).toBe('');
    expect(plain.style.textShadow).toBe('');
  });

  // @scenario surface=layer_boxes_not_interactive
  // @effects image_link_and_swipe_unchanged
  it('상자는 누름·글자 선택을 받지 않고 링크 밖에 있어, 상자 위 누름은 덮는 링크로·상자 위 끌기는 장 넘김으로 간다', () => {
    vi.useFakeTimers();
    const css = readFileSync(resolve(__dirname, '../../../css/popup.css'), 'utf8');
    const rule = css.match(/\.g7pp-overlay \{[^}]*\}/)?.[0] ?? '';
    expect(rule).toContain('pointer-events: none');
    expect(rule).toContain('user-select: none');

    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      const w = this.classList.contains('g7pp-body') ? 400 : 0;

      return { width: w, height: 0, top: 0, left: 0, right: w, bottom: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
    const { container } = render(<PopupLayer popups={[popup(1, { link_url: '/event' }), popup(2)]} settings={{ autoplay: false }} path="/" />);
    const overlay = boxesIn(container)[0];
    // 덮는 링크 안에 넣지 않는다(링크 안에 다른 요소를 두지 않는 규칙) — 링크는 프레임 위 형제
    expect(overlay.closest('a')).toBeNull();
    expect(container.querySelector('.g7pp-slide:not([hidden]) .g7pp-cover-link')).not.toBeNull();

    // 상자 위에서 왼쪽으로 끌면 다음 장
    const base = { pointerId: 3, pointerType: 'touch', button: 0, bubbles: true };
    fireEvent.pointerDown(overlay, { ...base, clientX: 300, clientY: 200 });
    fireEvent.pointerMove(overlay, { ...base, clientX: 220, clientY: 200 });
    fireEvent.pointerMove(overlay, { ...base, clientX: 140, clientY: 200 });
    fireEvent.pointerUp(overlay, { ...base, clientX: 140, clientY: 200 });
    act(() => { vi.advanceTimersByTime(400); });
    expect(container.querySelector('.g7pp-slide:not([hidden]):not([aria-hidden="true"])')?.getAttribute('data-popup-id')).toBe('2');
  });

  // @scenario surface=layer_mobile_boxes_with_mobile_image
  it('휴대폰에서 모바일 이미지를 쓰면 모바일 배치를 그린다', () => {
    setViewportMobile(true);
    const { container } = render(<PopupLayer popups={[popup(1)]} settings={{ autoplay: false }} path="/" />);

    expect(container.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/m-1.jpg');
    expect(boxesIn(container).map((el) => el.getAttribute('data-overlay-id'))).toEqual(['mobile01']);
  });

  // @scenario surface=layer_mobile_without_mobile_image_uses_pc_boxes
  it('모바일 이미지가 없으면 휴대폰도 PC 이미지와 PC 배치, 모바일 배치가 null 이면 모바일 이미지에 PC 배치', () => {
    setViewportMobile(true);
    const noMobileImage = popup(1, { image: { pc: { url: '/img/pc-1.jpg', width: 480, height: 520 }, mobile: null } });
    const { container, unmount } = render(<PopupLayer popups={[noMobileImage]} settings={{ autoplay: false }} path="/" />);
    expect(boxesIn(container).map((el) => el.getAttribute('data-overlay-id'))).toEqual(['a1b2c3d4']);
    unmount();

    resetClosedThisPage();
    const pcLayout = popup(2, { overlays: { pc: [box()], mobile: null } });
    const second = render(<PopupLayer popups={[pcLayout]} settings={{ autoplay: false }} path="/" />);
    expect(second.container.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/m-2.jpg');
    expect(boxesIn(second.container).map((el) => el.getAttribute('data-overlay-id'))).toEqual(['a1b2c3d4']);
  });

  // @scenario surface=layer_no_boxes_without_overlays
  it('글 상자가 없으면 프레임만 있고 상자는 없다', () => {
    const { container } = render(<PopupLayer popups={[popup(1, { overlays: null })]} settings={{ autoplay: false }} path="/" />);

    expect(container.querySelector('.g7pp-media__frame')).not.toBeNull();
    expect(boxesIn(container)).toHaveLength(0);
  });
});

describe('이미지 위 글 상자 — 관리자 미리보기', () => {
  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  // @scenario surface=preview_picks_tab_locale_text
  it('폼의 언어별 글을 탭 언어 → 기본 언어로 골라 그리고, 글이 빈 상자는 빼며, 모바일 이미지가 없으면 PC 배치', () => {
    installCore();
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const formBox = (id: string, text: Record<string, string>) => ({ id, x: 10, y: 10, w: 50, text, size: 5, color: '#FFFFFF', bold: false, align: 'left' as const, bg: null, shadow: false });

    const { container } = render(
      <PopupPreview
        device="mobile"
        locale="en"
        defaultLocale="ko"
        form={{
          content_type: 'image',
          images: { pc: { url: '/img/pc.jpg', width: 480, height: 520 }, mobile: null },
          overlays: {
            pc: [formBox('enboxone', { ko: '한국어', en: 'English' }), formBox('koonly01', { ko: '한국어만' }), formBox('emptybox', { ko: ' ' })],
            mobile: [formBox('mobile01', { ko: '모바일' })],
          },
        }}
      />,
    );

    const shown = [...container.querySelectorAll('.g7pp-overlay')];
    expect(shown.map((el) => el.getAttribute('data-overlay-id'))).toEqual(['enboxone', 'koonly01']);
    expect(shown.map((el) => el.textContent)).toEqual(['English', '한국어만']);
  });
});

describe('이미지 위 글 상자 — 경계·구형 브라우저', () => {
  const css = readFileSync(resolve(__dirname, '../../../css/popup.css'), 'utf8');

  // @scenario surface=layer_clips_boxes_to_image
  it('이미지 프레임은 넘친 글을 이미지 경계에서 자르고, 편집기 캔버스만 넘친 부분을 보인다', () => {
    const frameRule = css.match(/\n\.g7pp-media__frame \{[^}]*\}/)?.[0] ?? '';
    expect(frameRule).toContain('overflow: hidden');
    expect(css).toMatch(/\.g7pp-ov-editor__canvas \.g7pp-media__frame \{\s*overflow: visible;/);
  });

  // @scenario surface=layer_hides_boxes_without_container_queries
  it('컨테이너 단위를 모르는 브라우저에서는 이미지만 칸에 담고 글 상자를 숨긴다', () => {
    const block = css.slice(css.indexOf('@supports not (width: 1cqw)'));
    expect(block.length).toBeGreaterThan(0);
    const body = block.slice(0, block.indexOf('\n}\n') + 3);
    expect(body).toMatch(/\.g7pp-media__frame \{[^}]*position: absolute;[^}]*inset: 0;[^}]*width: auto;/);
    expect(body).toMatch(/\.g7pp-overlay \{\s*display: none;/);
  });
});

