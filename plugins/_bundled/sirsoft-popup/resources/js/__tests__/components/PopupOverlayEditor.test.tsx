/**
 * @file PopupOverlayEditor.test.tsx
 * @description 이미지 위 글 상자 편집기 — 추가·선택·끌기·폭 조절·키보드·서식·순서·기기 탭·언어별 글, 바뀐 값은 onChange 로.
 * (매니페스트 image-overlays.yaml — editor_*)
 *
 * @vitest-environment jsdom
 */
import React, { useState } from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
import { PopupOverlayEditor, type PopupOverlayEditorProps } from '../../components/composite/PopupOverlayEditor';
import { MAX_BOXES, type OverlayBox, type Overlays } from '../../lib/overlays';
import { installCore } from './core';

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

/** 폼 상자 */
const box = (extra: Partial<OverlayBox> = {}): OverlayBox => ({
  id: 'a1b2c3d4', x: 10, y: 20, w: 50, text: { ko: '가을 할인', en: 'Autumn sale' }, size: 6,
  color: '#FFFFFF', bold: true, align: 'center', bg: null, shadow: true, ...extra,
});

const PC = { url: '/img/pc.jpg', width: 800, height: 600 };
const MOBILE = { url: '/img/m.jpg', width: 800, height: 800 };

/**
 * 레이아웃처럼 값을 들고 있는 틀 — onChange 값을 다시 value 로 넣는다.
 *
 * @param props 처음 값과 편집기 props
 * @returns 편집기
 */
function Harness({ initial, onChange, ...props }: Omit<PopupOverlayEditorProps, 'value'> & { initial?: Overlays<OverlayBox> | null }) {
  const [value, setValue] = useState<Overlays<OverlayBox> | null>(initial ?? null);

  return <PopupOverlayEditor {...props} value={value} onChange={(next) => { setValue(next); onChange?.(next); }} />;
}

/** 마지막 onChange 값 */
const last = (spy: ReturnType<typeof vi.fn>) => spy.mock.calls[spy.mock.calls.length - 1]?.[0] as Overlays<OverlayBox> | null;

/** 캔버스의 상자 */
const boxEl = (container: HTMLElement, id: string) => container.querySelector(`[data-overlay-canvas] [data-overlay-id="${id}"]`) as HTMLElement;

describe('PopupOverlayEditor', () => {
  beforeEach(() => {
    installCore();
    (window as any).PointerEvent = TestPointerEvent;
    // 프레임 표시 크기 400×300 (이미지 800×600 을 반으로)
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      const frame = this.classList.contains('g7pp-media__frame');
      const w = frame ? 400 : 0;
      const h = frame ? 300 : 0;

      return { width: w, height: h, top: 0, left: 0, right: w, bottom: h, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  // @scenario surface=editor_needs_pc_image
  it('PC 이미지가 없으면 안내만 보인다', () => {
    const { container, getByText } = render(<PopupOverlayEditor images={{ pc: null, mobile: null }} />);

    expect(getByText('PC 이미지를 먼저 올리면 그 위에 글 상자를 놓을 수 있습니다.')).toBeTruthy();
    expect(container.querySelector('[data-overlay-canvas]')).toBeNull();
  });

  // @scenario surface=editor_add_box_centered
  it('「글 상자 추가」는 기본 언어 글이 든 상자를 가운데 쯤에 놓고 고른다', () => {
    const onChange = vi.fn();
    const { container, getByText } = render(<Harness images={{ pc: PC }} locales={['ko', 'en']} defaultLocale="ko" onChange={onChange} />);

    fireEvent.click(getByText('글 상자 추가'));

    const added = last(onChange)!.pc[0];
    expect(added.id).toMatch(/^[a-z0-9]{8}$/);
    expect(added.text).toEqual({ ko: '새 글' });
    expect(added.x + added.w / 2).toBe(50);
    expect(last(onChange)!.mobile).toBeNull();
    expect(boxEl(container, added.id).getAttribute('aria-pressed')).toBe('true');
    expect(container.querySelector(`[data-overlay-panel="${added.id}"]`)).not.toBeNull();
  });

  // @scenario surface=editor_add_locked_at_ten
  it(`${MAX_BOXES}개면 추가 단추가 잠기고 한도를 알린다`, () => {
    const full = Array.from({ length: MAX_BOXES }, (_, i) => box({ id: `box${String(i).padStart(5, '0')}` }));
    const { container, getByText } = render(<Harness images={{ pc: PC }} initial={{ pc: full, mobile: null }} defaultLocale="ko" />);

    expect((container.querySelector('[data-overlay-action="add"]') as HTMLButtonElement).disabled).toBe(true);
    expect(getByText(`글 상자는 기기마다 ${MAX_BOXES}개까지 둘 수 있습니다.`)).toBeTruthy();
  });

  // @scenario surface=editor_text_per_locale
  it('글은 고른 언어에만 쓰고 서식은 공통이다 — 비우면 그 언어 키를 지운다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box()], mobile: null }} locales={['ko', 'en']} defaultLocale="ko" localeNames={{ ko: '한국어', en: 'English' }} onChange={onChange} />);

    fireEvent.focus(boxEl(container, 'a1b2c3d4'));
    fireEvent.click(container.querySelector('[data-overlay-locale="en"]')!);
    const textarea = container.querySelector('#g7pp-ov-text') as HTMLTextAreaElement;
    expect(textarea.value).toBe('Autumn sale');
    expect(container.querySelector('[data-overlay-locale="en"]')?.textContent).toBe('English');

    fireEvent.change(textarea, { target: { value: 'Fall sale\n20% off' } });
    expect(last(onChange)!.pc[0].text).toEqual({ ko: '가을 할인', en: 'Fall sale\n20% off' });

    fireEvent.change(textarea, { target: { value: '' } });
    expect(last(onChange)!.pc[0].text).toEqual({ ko: '가을 할인' });
    expect(last(onChange)!.pc[0].x).toBe(10);
  });

  // @scenario surface=editor_drag_moves_and_clamps
  it('상자를 끌면 프레임 대비 % 로 옮기고 이미지 밖으로 나가지 않게 자른다 — 놓을 때 한 번만 알린다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box()], mobile: null }} defaultLocale="ko" onChange={onChange} />);
    const el = boxEl(container, 'a1b2c3d4');

    fireEvent.pointerDown(el, { button: 0, clientX: 100, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 140, clientY: 130 });
    // 끄는 동안은 화면만 따라오고 폼에 알리지 않는다
    expect(onChange).not.toHaveBeenCalled();
    expect(el.style.left).toBe('20%');
    fireEvent.pointerUp(window, { clientX: 140, clientY: 130 });
    expect(onChange).toHaveBeenCalledTimes(1);
    expect([last(onChange)!.pc[0].x, last(onChange)!.pc[0].y]).toEqual([20, 30]);

    // 오른쪽·위로 멀리 끌면 x 는 100 - w, y 는 0 에서 멈춘다
    fireEvent.pointerDown(boxEl(container, 'a1b2c3d4'), { button: 0, clientX: 100, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 900, clientY: -500 });
    fireEvent.pointerUp(window, {});
    expect([last(onChange)!.pc[0].x, last(onChange)!.pc[0].y]).toEqual([50, 0]);
  });

  // @scenario surface=editor_resize_width_clamps
  it('오른쪽 손잡이로 폭을 바꾸고 5% 아래·오른쪽 끝 밖으로는 가지 않는다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box()], mobile: null }} defaultLocale="ko" onChange={onChange} />);
    fireEvent.focus(boxEl(container, 'a1b2c3d4'));
    const handle = () => container.querySelector('[data-overlay-resize]') as HTMLElement;

    fireEvent.pointerDown(handle(), { button: 0, clientX: 300, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 340, clientY: 100 });
    fireEvent.pointerUp(window, {});
    expect(last(onChange)!.pc[0]).toMatchObject({ x: 10, w: 60 });

    fireEvent.pointerDown(handle(), { button: 0, clientX: 300, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 2000, clientY: 100 });
    fireEvent.pointerUp(window, {});
    expect(last(onChange)!.pc[0].w).toBe(90);

    fireEvent.pointerDown(handle(), { button: 0, clientX: 300, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: -2000, clientY: 100 });
    fireEvent.pointerUp(window, {});
    expect(last(onChange)!.pc[0].w).toBe(5);
  });

  // @scenario surface=editor_keyboard_moves_1_and_5
  it('고른 상자는 화살표로 1%, Shift+화살표로 5% 옮기고 끝에서 멈춘다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box({ x: 1 })], mobile: null }} defaultLocale="ko" onChange={onChange} />);
    const el = () => boxEl(container, 'a1b2c3d4');

    fireEvent.keyDown(el(), { key: 'ArrowRight' });
    expect(last(onChange)!.pc[0].x).toBe(2);
    fireEvent.keyDown(el(), { key: 'ArrowDown', shiftKey: true });
    expect(last(onChange)!.pc[0].y).toBe(25);
    fireEvent.keyDown(el(), { key: 'ArrowLeft', shiftKey: true });
    expect(last(onChange)!.pc[0].x).toBe(0);
  });

  // @scenario surface=editor_delete_key_removes
  it('Delete 로 고른 상자를 지우고, 마지막 상자를 지우면 값은 null', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box(), box({ id: 'second01' })], mobile: null }} defaultLocale="ko" onChange={onChange} />);

    fireEvent.keyDown(boxEl(container, 'a1b2c3d4'), { key: 'Delete' });
    expect(last(onChange)!.pc.map((b) => b.id)).toEqual(['second01']);
    fireEvent.keyDown(boxEl(container, 'second01'), { key: 'Backspace' });
    expect(last(onChange)).toBeNull();
  });

  // @scenario surface=editor_format_panel_updates
  it('옆 패널로 크기·색·굵게·정렬·바탕(색·진하기)·그림자를 바꾼다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box()], mobile: null }} defaultLocale="ko" onChange={onChange} />);
    fireEvent.focus(boxEl(container, 'a1b2c3d4'));
    const field = (name: string) => container.querySelector(`[data-overlay-field="${name}"]`) as HTMLInputElement;
    const now = () => last(onChange)!.pc[0];

    fireEvent.change(field('size'), { target: { value: '9.5' } });
    expect(now().size).toBe(9.5);
    fireEvent.input(field('color'), { target: { value: '#ff0000' } });
    fireEvent.change(field('color'), { target: { value: '#ff0000' } });
    expect(now().color).toBe('#FF0000');
    fireEvent.click(field('bold'));
    expect(now().bold).toBe(false);
    fireEvent.click(container.querySelector('[data-overlay-align="right"]')!);
    expect(now().align).toBe('right');
    fireEvent.click(field('shadow'));
    expect(now().shadow).toBe(false);
    fireEvent.click(field('bg'));
    expect(now().bg).toEqual({ color: '#000000', opacity: 40 });
    fireEvent.change(field('bg_opacity'), { target: { value: '70' } });
    expect(now().bg).toEqual({ color: '#000000', opacity: 70 });
    fireEvent.change(field('bg_color'), { target: { value: '#00ff00' } });
    expect(now().bg).toEqual({ color: '#00FF00', opacity: 70 });
    fireEvent.click(field('bg'));
    expect(now().bg).toBeNull();
    // 캔버스 상자에도 바로 보인다
    expect(boxEl(container, 'a1b2c3d4').style.textAlign).toBe('right');
  });

  // @scenario surface=editor_order_forward_back
  it('앞으로·뒤로는 겹침 순서(배열 순서)를 한 칸씩 바꾸고 끝에서는 잠긴다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box(), box({ id: 'second01' })], mobile: null }} defaultLocale="ko" onChange={onChange} />);
    fireEvent.focus(boxEl(container, 'a1b2c3d4'));
    const action = (name: string) => container.querySelector(`[data-overlay-action="${name}"]`) as HTMLButtonElement;

    expect(action('backward').disabled).toBe(true);
    fireEvent.click(action('forward'));
    expect(last(onChange)!.pc.map((b) => b.id)).toEqual(['second01', 'a1b2c3d4']);
    expect(action('forward').disabled).toBe(true);
    fireEvent.click(action('backward'));
    expect(last(onChange)!.pc.map((b) => b.id)).toEqual(['a1b2c3d4', 'second01']);
  });

  // @scenario surface=editor_mobile_tab_only_with_mobile_image
  it('기기 탭은 모바일 이미지가 있을 때만 보인다', () => {
    const { container, rerender } = render(<PopupOverlayEditor images={{ pc: PC, mobile: null }} defaultLocale="ko" />);
    expect(container.querySelector('[data-overlay-device]')).toBeNull();

    rerender(<PopupOverlayEditor images={{ pc: PC, mobile: MOBILE }} defaultLocale="ko" />);
    expect([...container.querySelectorAll('[data-overlay-device]')].map((el) => el.getAttribute('data-overlay-device'))).toEqual(['pc', 'mobile']);
  });

  // @scenario surface=editor_copy_pc_then_edit_separately
  it('모바일 탭은 처음엔 PC 배치를 흐리게 보여 고칠 수 없고, 복사하면 따로 고친다(PC 는 그대로)', () => {
    const onChange = vi.fn();
    const { container, getByText } = render(<Harness images={{ pc: PC, mobile: MOBILE }} initial={{ pc: [box()], mobile: null }} defaultLocale="ko" onChange={onChange} />);

    fireEvent.click(container.querySelector('[data-overlay-device="mobile"]')!);
    expect(container.querySelector('.g7pp-ov-editor__canvas img')?.getAttribute('src')).toBe('/img/m.jpg');
    expect(boxEl(container, 'a1b2c3d4').getAttribute('tabindex')).toBe('-1');
    expect((container.querySelector('[data-overlay-action="add"]') as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(getByText('PC 배치 복사해서 따로 놓기'));
    expect(last(onChange)!.mobile).toEqual([box()]);

    fireEvent.keyDown(boxEl(container, 'a1b2c3d4'), { key: 'ArrowDown' });
    expect(last(onChange)!.mobile![0].y).toBe(21);
    expect(last(onChange)!.pc[0].y).toBe(20);
  });

  // @scenario surface=editor_use_pc_layout_resets_mobile
  it('「PC 배치 그대로 쓰기」는 모바일 배치를 지운다(null)', () => {
    const onChange = vi.fn();
    const { container, getByText } = render(<Harness images={{ pc: PC, mobile: MOBILE }} initial={{ pc: [box()], mobile: [box({ id: 'mobile01' })] }} defaultLocale="ko" onChange={onChange} />);

    fireEvent.click(container.querySelector('[data-overlay-device="mobile"]')!);
    expect(boxEl(container, 'mobile01')).not.toBeNull();
    fireEvent.click(getByText('PC 배치 그대로 쓰기'));

    expect(last(onChange)).toEqual({ pc: [box()], mobile: null });
  });

  // @scenario surface=editor_marks_fallback_locale
  it('고른 언어 글이 없는 상자는 기본 언어 글을 흐리게 보이고 「기본 언어로 표시」를 단다', () => {
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box({ text: { ko: '한국어만' } })], mobile: null }} locales={['ko', 'en']} defaultLocale="ko" />);
    fireEvent.focus(boxEl(container, 'a1b2c3d4'));

    fireEvent.click(container.querySelector('[data-overlay-locale="en"]')!);

    const el = boxEl(container, 'a1b2c3d4');
    expect(el.className).toContain('g7pp-overlay--fallback');
    expect(el.textContent).toBe('한국어만');
    expect(container.querySelector('[data-overlay-fallback]')?.textContent).toBe('기본 언어로 표시');
    expect((container.querySelector('#g7pp-ov-text') as HTMLTextAreaElement).placeholder).toBe('한국어만');
  });

  // @scenario surface=editor_disabled_while_saving
  it('저장 중에는 추가·끌기·키보드가 막힌다', () => {
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box()], mobile: null }} defaultLocale="ko" disabled onChange={onChange} />);
    const el = boxEl(container, 'a1b2c3d4');

    expect((container.querySelector('[data-overlay-action="add"]') as HTMLButtonElement).disabled).toBe(true);
    fireEvent.pointerDown(el, { button: 0, clientX: 100, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 200, clientY: 200 });
    fireEvent.pointerUp(window, {});
    fireEvent.keyDown(el, { key: 'ArrowRight' });
    fireEvent.keyDown(el, { key: 'Delete' });

    expect(onChange).not.toHaveBeenCalled();
    expect(el.getAttribute('aria-disabled')).toBe('true');
  });

  // @scenario surface=editor_warns_overflow_any_locale
  it('어느 언어든 글이 이미지 아래로 넘치면 그 상자와 언어를 알리고 상자에 표시한다', () => {
    // 프레임 400×300 — 영어 복제본만 아래 끝이 320px(107%)
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      const frame = this.classList.contains('g7pp-media__frame');
      const bottom = frame ? 300 : (this.dataset.measureLocale === 'en' ? 320 : 200);
      return { width: frame ? 400 : 0, height: frame ? 300 : 40, top: frame ? 0 : bottom - 40, left: 0, right: 0, bottom, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box({ y: 80 }), box({ id: 'second01', text: { ko: '짧음' } })], mobile: null }} locales={['ko', 'en']} defaultLocale="ko" localeNames={{ ko: '한국어', en: 'English' }} />);

    const warning = container.querySelector('[data-overlay-warning="overflow"]') as HTMLElement;
    expect(warning.textContent).toContain('글 상자 1의 글(English)이 이미지 아래로 넘쳐');
    expect(warning.textContent).not.toContain('글 상자 2');
    expect(boxEl(container, 'a1b2c3d4').className).toContain('g7pp-overlay--overflow');
    expect(boxEl(container, 'second01').className).not.toContain('g7pp-overlay--overflow');
    // 언어마다 보이지 않는 복제본이 있다(빈 언어는 없음)
    expect([...container.querySelectorAll('[data-measure-box]')].map((el) => `${el.getAttribute('data-measure-box')}:${el.getAttribute('data-measure-locale')}`))
      .toEqual(['a1b2c3d4:ko', 'a1b2c3d4:en', 'second01:ko']);
  });

  // @scenario surface=editor_keyboard_keeps_box_inside
  it('화살표로 내릴 때도 상자 높이만큼 위에서 멈춘다', () => {
    // 상자 높이 60px = 프레임(300px)의 20% → y 는 80 까지
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      const frame = this.classList.contains('g7pp-media__frame');
      return { width: frame ? 400 : 200, height: frame ? 300 : 60, top: 0, left: 0, right: 0, bottom: frame ? 300 : 60, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
    const onChange = vi.fn();
    const { container } = render(<Harness images={{ pc: PC }} initial={{ pc: [box({ y: 78 })], mobile: null }} defaultLocale="ko" onChange={onChange} />);

    fireEvent.keyDown(boxEl(container, 'a1b2c3d4'), { key: 'ArrowDown', shiftKey: true });
    expect(last(onChange)!.pc[0].y).toBe(80);
  });

  // @scenario surface=editor_warns_image_ratio_change
  it('상자가 있는데 이미지를 다른 비율로 바꾸면 위치를 다시 확인하라고 알리고, 확인하면 닫힌다', () => {
    const initial = { pc: [box()], mobile: null };
    const { container, rerender, getByText } = render(<PopupOverlayEditor images={{ pc: PC }} value={initial} defaultLocale="ko" />);
    expect(container.querySelector('[data-overlay-warning="ratio"]')).toBeNull();

    // 같은 비율(2배 크기)은 알리지 않는다
    rerender(<PopupOverlayEditor images={{ pc: { url: '/img/pc2.jpg', width: 1600, height: 1200 } }} value={initial} defaultLocale="ko" />);
    expect(container.querySelector('[data-overlay-warning="ratio"]')).toBeNull();

    rerender(<PopupOverlayEditor images={{ pc: { url: '/img/tall.jpg', width: 600, height: 800 } }} value={initial} defaultLocale="ko" />);
    const warning = container.querySelector('[data-overlay-warning="ratio"]') as HTMLElement;
    expect(warning.textContent).toContain('1.33:1 → 1:1.33');
    fireEvent.click(getByText('확인했어요'));
    expect(container.querySelector('[data-overlay-warning="ratio"]')).toBeNull();
  });

  // @scenario surface=editor_marks_error_boxes
  it('저장 오류가 난 상자(그 기기의 순번)에 오류 표시를 한다', () => {
    const { container } = render(<PopupOverlayEditor images={{ pc: PC }} value={{ pc: [box(), box({ id: 'second01' })], mobile: null }} defaultLocale="ko"
      errors={{ 'overlays.pc.1.text': ['PC 글 상자 2 글의 한국어(ko) 값은 필수입니다.'], 'overlays.mobile.0': ['…'], title: ['…'] }} />);

    expect(boxEl(container, 'second01').className).toContain('g7pp-overlay--error');
    expect(boxEl(container, 'second01').getAttribute('aria-invalid')).toBe('true');
    expect(boxEl(container, 'a1b2c3d4').className).not.toContain('g7pp-overlay--error');
  });

  // @scenario surface=editor_shows_phone_text_size
  it('휴대폰에 보이는 배치면 작은 휴대폰 기준 예상 글자 크기를 보이고, 12px 미만이면 알린다', () => {
    const { container, rerender } = render(<Harness images={{ pc: PC }} initial={{ pc: [box({ size: 3 })], mobile: null }} defaultLocale="ko" />);
    fireEvent.focus(boxEl(container, 'a1b2c3d4'));
    const hint = () => container.querySelector('[data-overlay-phone-size]') as HTMLElement | null;
    expect(hint()?.getAttribute('data-overlay-phone-size')).toBe('9.6');
    expect(hint()?.getAttribute('data-small')).toBe('true');
    expect(hint()?.textContent).toContain('읽기 어려울 수 있습니다');

    fireEvent.change(container.querySelector('[data-overlay-field="size"]')!, { target: { value: '6' } });
    expect(hint()?.getAttribute('data-overlay-phone-size')).toBe('19.2');
    expect(hint()?.getAttribute('data-small')).toBeNull();

    // 모바일 배치가 따로 있으면 PC 배치는 휴대폰에 보이지 않아 표시하지 않는다
    cleanup();
    const separate = render(<Harness images={{ pc: PC, mobile: MOBILE }} initial={{ pc: [box()], mobile: [box({ id: 'mobile01' })] }} defaultLocale="ko" />);
    fireEvent.focus(boxEl(separate.container, 'a1b2c3d4'));
    expect(separate.container.querySelector('[data-overlay-phone-size]')).toBeNull();
  });
});
