/**
 * @file PopupLayer.test.tsx
 * @description 사용자 팝업 레이어 — 판정(경로·기기·보지 않기·닫음)·캐러셀·자동 넘김·접근성·닫기·보지 않기·배치·GDPR 공존·이미지·링크·본문·끌기 슬라이드.
 * (매니페스트 popup-layer.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { PopupLayer, GDPR_BANNER_ID, SLIDE_MS, MAX_DOTS } from '../../components/composite/PopupLayer';
import { resetClosedThisPage } from '../../lib/popupVisibility';
import { DISMISS_COOKIE } from '../../lib/dismissCookie';
import type { PopupDisplaySettings, PublicPopup } from '../../lib/types';
import { installCore, setViewportMobile } from './core';

/** 화면 폭·움직임 줄이기 흉내 상태 — 폭은 `window.innerWidth`(레이어가 엔진 훅 없이 읽는 값)로 흉내 낸다 */
const media = {
  reduced: false,
  set mobile(value: boolean) {
    setViewportMobile(value);
  },
};

/** matchMedia 대역 — 움직임 줄이기 질의에 흉내 상태를 돌려준다 */
function installMatchMedia(): void {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: query.includes('prefers-reduced-motion') ? media.reduced : false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}

/** 이미지형 팝업 */
const imagePopup = (id: number, extra: Partial<PublicPopup> = {}): PublicPopup => ({
  id,
  content_type: 'image',
  page_scope: 'all',
  device: 'all',
  image: { pc: { url: `/img/pc-${id}.jpg`, width: 480, height: 520 }, mobile: { url: `/img/m-${id}.jpg`, width: 750, height: 900 } },
  image_alt: `대체 ${id}`,
  link_url: null,
  link_new_window: false,
  ...extra,
});

/** 직접 작성형 팝업 */
const htmlPopup = (id: number, body = `<p>본문 ${id}</p>`, extra: Partial<PublicPopup> = {}): PublicPopup => ({
  id,
  content_type: 'html',
  page_scope: 'all',
  device: 'all',
  body_html: body,
  ...extra,
});

/** 레이어를 그린다 */
const show = (popups: PublicPopup[], settings: Partial<PopupDisplaySettings> = {}, path = '/') => render(
  <PopupLayer popups={popups} settings={{ autoplay: false, ...settings }} path={path} />,
);

/** 지금 보이는 장의 팝업 id */
const currentId = (container: HTMLElement) => Number(container.querySelector('.g7pp-slide:not([hidden]):not([aria-hidden="true"])')?.getAttribute('data-popup-id'));

/** 보이는 장(현재 장 + 넘기는 동안의 옆 장)의 팝업 id — DOM 순서 */
const shownIds = (container: HTMLElement) => [...container.querySelectorAll('.g7pp-slide:not([hidden])')].map((el) => Number(el.getAttribute('data-popup-id')));

/** 장의 가로 위치(인라인 transform, 없으면 '') */
const slideX = (container: HTMLElement, id: number) => (container.querySelector(`.g7pp-slide[data-popup-id="${id}"]`) as HTMLElement).style.transform;

/** jsdom 에 없는 PointerEvent 대역 — 좌표(MouseEvent)·포인터 id·종류를 싣는다 */
class TestPointerEvent extends MouseEvent {
  pointerId: number;
  pointerType: string;

  constructor(type: string, init: PointerEventInit = {}) {
    super(type, init);
    this.pointerId = init.pointerId ?? 1;
    this.pointerType = init.pointerType ?? 'mouse';
  }
}

/** 슬라이드 영역의 실제 표시 폭(getBoundingClientRect)을 정한다 — jsdom 은 0 을 준다 */
const mockBodyWidth = (width: number) => vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
  const w = this.classList.contains('g7pp-body') ? width : 0;

  return { width: w, height: 0, top: 0, left: 0, right: w, bottom: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
});

/**
 * 포인터로 가로·세로로 끈다.
 *
 * @param target 누르는 요소
 * @param dx 가로 이동(px, 왼쪽이 음수)
 * @param options dy 세로 이동, type 포인터 종류, release 놓기까지, wait 놓기 전 기다림(ms, 가짜 시계)
 */
const dragOn = (target: Element, dx: number, options: { dy?: number; type?: 'mouse' | 'touch'; release?: boolean; wait?: number } = {}) => {
  const { dy = 0, type = 'mouse', release = true, wait = 0 } = options;
  const base = { pointerId: 7, pointerType: type, button: 0, bubbles: true };
  fireEvent.pointerDown(target, { ...base, clientX: 300, clientY: 200 });
  if (wait > 0) {
    act(() => { vi.advanceTimersByTime(wait); });
  }
  fireEvent.pointerMove(target, { ...base, clientX: 300 + dx / 2, clientY: 200 + dy / 2 });
  fireEvent.pointerMove(target, { ...base, clientX: 300 + dx, clientY: 200 + dy });
  if (release) {
    fireEvent.pointerUp(target, { ...base, clientX: 300 + dx, clientY: 200 + dy });
  }
};

/** 쿠키를 지운다 */
const clearCookie = () => {
  document.cookie = `${DISMISS_COOKIE}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
};

describe('PopupLayer', () => {
  beforeEach(() => {
    media.mobile = false;
    media.reduced = false;
    installMatchMedia();
    installCore();
    resetClosedThisPage();
    clearCookie();
    document.getElementById(GDPR_BANNER_ID)?.remove();
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    clearCookie();
    delete (window as any).G7Core;
  });

  // @scenario surface=renders_nothing_when_no_visible_popup
  it('보일 팝업이 없으면 아무것도 그리지 않는다', () => {
    const { container } = show([]);
    expect(container.innerHTML).toBe('');

    const { container: routed } = show([htmlPopup(1, '<p>a</p>', { page_scope: 'routes', routes: ['/shop'] })], {}, '/board');
    expect(routed.innerHTML).toBe('');
  });

  // @scenario surface=filters_by_route_on_path_change
  it('경로가 바뀌면 다시 판정한다(경로 경계 하위 포함)', () => {
    const popups = [htmlPopup(1, '<p>a</p>', { page_scope: 'routes', routes: ['/shop'] })];
    const { container, rerender } = show(popups, {}, '/');
    expect(container.querySelector('[role="dialog"]')).toBeNull();

    rerender(<PopupLayer popups={popups} settings={{ autoplay: false }} path="/shop/products/1" />);
    expect(container.querySelector('[role="dialog"]')).not.toBeNull();

    rerender(<PopupLayer popups={popups} settings={{ autoplay: false }} path="/shopping" />);
    expect(container.querySelector('[role="dialog"]')).toBeNull();
  });

  // @scenario surface=filters_by_device_on_viewport_change
  it('폭 767px 이하는 모바일 전용만, 그 위는 PC 전용만', () => {
    const popups = [htmlPopup(1, '<p>pc</p>', { device: 'pc' }), htmlPopup(2, '<p>m</p>', { device: 'mobile' }), htmlPopup(3)];
    const { container } = show(popups);
    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([1, 3]);
    cleanup();

    media.mobile = true;
    const { container: mobile } = show(popups);
    expect([...mobile.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([2, 3]);
  });

  // @scenario surface=filters_dismissed_ids_until_expiry
  it('보지 않기 기록이 만료 전인 팝업은 빼고 나머지는 보인다', () => {
    const now = Math.floor(Date.now() / 1000);
    document.cookie = `${DISMISS_COOKIE}=${encodeURIComponent(JSON.stringify({ '1': now + 3600 }))}; path=/`;

    const { container } = show([htmlPopup(1), htmlPopup(2)]);

    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([2]);
  });

  // @scenario surface=shows_expired_dismissed_ids_again
  it('만료가 지난 기록은 무시하고 다시 보인다', () => {
    const now = Math.floor(Date.now() / 1000);
    document.cookie = `${DISMISS_COOKIE}=${encodeURIComponent(JSON.stringify({ '1': now - 1 }))}; path=/`;

    const { container } = show([htmlPopup(1)]);

    expect(currentId(container)).toBe(1);
  });

  // @scenario surface=keeps_server_order
  it('서버 순서(관리자 순서) 그대로 넘긴다', () => {
    const { container } = show([htmlPopup(9), htmlPopup(3), htmlPopup(5)]);

    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([9, 3, 5]);
    expect(currentId(container)).toBe(9);
  });

  // @scenario surface=single_popup_hides_carousel_controls
  it('한 건이면 이전·다음·점·번호·일시정지를 그리지 않는다', () => {
    const { container } = show([htmlPopup(1)], { autoplay: true });

    expect(container.querySelector('.g7pp-arrow')).toBeNull();
    expect(container.querySelector('.g7pp-nav')).toBeNull();
  });

  // @scenario surface=prev_next_wrap_around
  it('이전·다음은 끝에서 처음으로 돈다', () => {
    const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);

    fireEvent.click(getByLabelText('이전 팝업'));
    expect(currentId(container)).toBe(3);
    fireEvent.click(getByLabelText('다음 팝업'));
    expect(currentId(container)).toBe(1);
  });

  // @scenario surface=dot_buttons_select_and_mark_current
  it('점 단추로 고르고 현재 장에 aria-current', () => {
    const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);

    fireEvent.click(getByLabelText('3번째 팝업 보기'));

    expect(currentId(container)).toBe(3);
    expect(getByLabelText('3번째 팝업 보기').getAttribute('aria-current')).toBe('true');
    expect(getByLabelText('1번째 팝업 보기').getAttribute('aria-current')).toBeNull();
  });

  // @scenario surface=counter_shows_position
  it('「n / N」으로 위치를 보인다', () => {
    const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2)]);

    expect(container.querySelector('.g7pp-counter')?.textContent).toBe('1 / 2');
    fireEvent.click(getByLabelText('다음 팝업'));
    expect(container.querySelector('.g7pp-counter')?.textContent).toBe('2 / 2');
  });

  // @scenario surface=many_popups_show_counter_without_dots
  it(`${MAX_DOTS}장을 넘으면 점 단추 없이 「n / N」만 둔다 — 점이 여러 줄로 늘어 띠가 길어지지 않게`, () => {
    const ids = Array.from({ length: MAX_DOTS + 1 }, (_, i) => i + 1);
    const many = show(ids.map((id) => htmlPopup(id)));
    expect(many.container.querySelectorAll('.g7pp-dot')).toHaveLength(0);
    expect(many.container.querySelector('.g7pp-counter')?.textContent).toBe(`1 / ${MAX_DOTS + 1}`);
    fireEvent.click(many.getByLabelText('다음 팝업'));
    expect(many.container.querySelector('.g7pp-counter')?.textContent).toBe(`2 / ${MAX_DOTS + 1}`);
    cleanup();

    const edge = show(ids.slice(0, MAX_DOTS).map((id) => htmlPopup(id)));
    expect(edge.container.querySelectorAll('.g7pp-dot')).toHaveLength(MAX_DOTS);
  });

  // @scenario surface=autoplay_advances_at_interval
  it('자동 넘김은 설정 간격마다 다음 장으로', () => {
    vi.useFakeTimers();
    const { container } = show([htmlPopup(1), htmlPopup(2)], { autoplay: true, autoplay_interval: 4 });
    // 열릴 때 포커스는 레이어로 가지만(접근성) 그것만으로 멈추지 않는다
    expect(document.activeElement).toBe(container.querySelector('[role="dialog"]'));

    act(() => { vi.advanceTimersByTime(3999); });
    expect(currentId(container)).toBe(1);
    act(() => { vi.advanceTimersByTime(1); });
    expect(currentId(container)).toBe(2);
  });

  // @scenario surface=autoplay_pauses_on_hover_and_focus
  it('마우스를 올리거나 레이어 안에 포커스가 있으면 멈춘다', () => {
    vi.useFakeTimers();
    const { container } = show([htmlPopup(1), htmlPopup(2)], { autoplay: true, autoplay_interval: 4 });
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    // 레이어 안 단추로 포커스가 가면 멈춘다
    const closeButton = container.querySelector('.g7pp-footer__close') as HTMLElement;
    act(() => { closeButton.focus(); });
    act(() => { vi.advanceTimersByTime(8000); });
    expect(currentId(container)).toBe(1);

    act(() => { fireEvent.blur(closeButton, { relatedTarget: document.body }); });
    fireEvent.mouseEnter(dialog);
    act(() => { vi.advanceTimersByTime(8000); });
    expect(currentId(container)).toBe(1);

    fireEvent.mouseLeave(dialog);
    act(() => { vi.advanceTimersByTime(4000); });
    expect(currentId(container)).toBe(2);
  });

  // @scenario surface=pause_button_toggles_autoplay
  it('일시정지 단추로 멈추고 다시 재생한다', () => {
    vi.useFakeTimers();
    const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2)], { autoplay: true, autoplay_interval: 4 });
    act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });

    fireEvent.click(getByLabelText('자동 넘김 일시정지'));
    act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });
    act(() => { vi.advanceTimersByTime(8000); });
    expect(currentId(container)).toBe(1);
    expect(getByLabelText('자동 넘김 재생').getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(getByLabelText('자동 넘김 재생'));
    act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });
    act(() => { vi.advanceTimersByTime(4000); });
    expect(currentId(container)).toBe(2);
  });

  // @scenario surface=reduced_motion_disables_autoplay
  it('움직임 줄이기면 자동 넘김을 끄고 일시정지 단추도 없다', () => {
    vi.useFakeTimers();
    media.reduced = true;
    installMatchMedia();
    const { container } = show([htmlPopup(1), htmlPopup(2)], { autoplay: true, autoplay_interval: 4 });
    act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });

    act(() => { vi.advanceTimersByTime(12000); });

    expect(currentId(container)).toBe(1);
    expect(container.querySelector('.g7pp-nav__pause')).toBeNull();
  });

  // @scenario surface=aria_live_off_while_autoplaying
  // @effects accessible_modal_carousel
  it('자동으로 넘기는 동안 aria-live=off, 멈추면 polite', () => {
    const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2)], { autoplay: true });
    const slides = container.querySelector('.g7pp-slides') as HTMLElement;
    act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });
    expect(slides.getAttribute('aria-live')).toBe('off');

    fireEvent.click(getByLabelText('자동 넘김 일시정지'));
    expect(slides.getAttribute('aria-live')).toBe('polite');
  });

  // @scenario surface=dialog_role_and_label
  // @effects accessible_modal_carousel
  it('dialog·aria-modal·이름, 장마다 group 과 「n / N」 이름', () => {
    const { container } = show([htmlPopup(1), htmlPopup(2)]);
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.getAttribute('aria-label')).toBe('공지 팝업');
    const slide = container.querySelector('.g7pp-slide') as HTMLElement;
    expect(slide.getAttribute('role')).toBe('group');
    expect(slide.getAttribute('aria-label')).toBe('1 / 2');
  });

  // @scenario surface=focus_moves_into_layer_on_open
  // @effects accessible_modal_carousel
  it('열리면 레이어로 포커스가 간다', () => {
    const { container } = show([htmlPopup(1)]);

    expect(document.activeElement).toBe(container.querySelector('[role="dialog"]'));
  });

  // @scenario surface=tab_focus_trapped
  // @effects accessible_modal_carousel
  it('Tab·Shift+Tab 이 레이어 안에서 돈다', () => {
    const { container } = show([htmlPopup(1), htmlPopup(2)]);
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;
    const buttons = [...dialog.querySelectorAll<HTMLElement>('button')];
    const first = buttons[0];
    const last = buttons[buttons.length - 1];

    // 안의 단추로 간 포커스는 자동 넘김 멈춤 상태를 바꾼다 — act 로 감싼다
    act(() => { last.focus(); });
    act(() => { fireEvent.keyDown(last, { key: 'Tab' }); });
    expect(document.activeElement).toBe(first);

    act(() => { first.focus(); });
    act(() => { fireEvent.keyDown(first, { key: 'Tab', shiftKey: true }); });
    expect(document.activeElement).toBe(last);
  });

  // @scenario surface=escape_closes
  it('ESC 로 닫는다', () => {
    const { container } = show([htmlPopup(1)]);

    fireEvent.keyDown(container.querySelector('[role="dialog"]') as HTMLElement, { key: 'Escape' });

    expect(container.querySelector('[role="dialog"]')).toBeNull();
  });

  // @scenario surface=arrow_keys_navigate
  it('←→ 로 넘긴다', () => {
    const { container } = show([htmlPopup(1), htmlPopup(2)]);
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    fireEvent.keyDown(dialog, { key: 'ArrowRight' });
    expect(currentId(container)).toBe(2);
    fireEvent.keyDown(dialog, { key: 'ArrowLeft' });
    expect(currentId(container)).toBe(1);
  });

  // @scenario surface=focus_returns_on_close
  // @effects accessible_modal_carousel
  it('닫으면 열기 전 포커스로 돌아간다', () => {
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();

    const { getByText } = show([htmlPopup(1)]);
    fireEvent.click(getByText('닫기'));

    expect(document.activeElement).toBe(trigger);
    trigger.remove();
  });

  // @scenario surface=close_hides_until_reload_across_paths
  it('「닫기」한 팝업은 다른 경로로 가도(다시 마운트돼도) 새로고침 전까지 뜨지 않는다', () => {
    const popups = [htmlPopup(1), htmlPopup(2, '<p>b</p>', { page_scope: 'routes', routes: ['/shop'] })];
    const { getByText, container, rerender } = show(popups, {}, '/');
    fireEvent.click(getByText('닫기'));
    expect(container.querySelector('[role="dialog"]')).toBeNull();

    rerender(<PopupLayer popups={popups} settings={{ autoplay: false }} path="/shop" />);
    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([2]);
    cleanup();

    const { container: remounted } = show(popups, {}, '/');
    expect(remounted.querySelector('[role="dialog"]')).toBeNull();
  });

  // @scenario surface=close_on_backdrop_when_enabled
  it('배경 닫기를 켜면 배경을 눌러 닫는다', () => {
    const { container } = show([htmlPopup(1)], { close_on_backdrop: true });

    fireEvent.click(container.querySelector('[data-popup-backdrop]') as HTMLElement);

    expect(container.querySelector('[role="dialog"]')).toBeNull();
  });

  // @scenario surface=backdrop_click_ignored_when_disabled
  it('배경 닫기가 꺼져 있으면 배경을 눌러도 닫히지 않는다', () => {
    const { container } = show([htmlPopup(1)], { close_on_backdrop: false, dim_background: true });

    fireEvent.click(container.querySelector('[data-popup-backdrop]') as HTMLElement);

    expect(container.querySelector('[role="dialog"]')).not.toBeNull();
  });

  // @scenario surface=dismiss_records_all_visible_ids
  // @effects dismiss_cookie_written
  it('「보지 않기」는 그때 보이던 팝업 전부를 기록하고 레이어를 닫는다(지금 장만이 아니다)', () => {
    const { container, getByText } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)], { dismiss_mode: 'today' });
    fireEvent.click(container.querySelector('[aria-label="2번째 팝업 보기"]') as HTMLElement);

    fireEvent.click(getByText('오늘 하루 보지 않기'));

    expect(container.querySelector('[role="dialog"]')).toBeNull();
    const value = JSON.parse(decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith(`${DISMISS_COOKIE}=`))!.slice(DISMISS_COOKIE.length + 1)));
    expect(Object.keys(value).sort()).toEqual(['1', '2', '3']);
  });

  // @scenario surface=new_popup_shows_after_dismiss
  it('보지 않기 뒤에 새로 올린 팝업은 바로 보인다', () => {
    const { getByText } = show([htmlPopup(1)], { dismiss_mode: 'days', dismiss_days: 7 });
    fireEvent.click(getByText('7일간 보지 않기'));
    cleanup();
    resetClosedThisPage();

    const { container } = show([htmlPopup(1), htmlPopup(4)]);

    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([4]);
  });

  // @scenario surface=close_only_mode_hides_dismiss_button
  it('닫기만이면 보지 않기 단추가 없다', () => {
    const { container, queryByText } = show([htmlPopup(1)], { dismiss_mode: 'close' });

    expect(queryByText('오늘 하루 보지 않기')).toBeNull();
    expect(container.querySelector('.g7pp-footer--single')).not.toBeNull();
  });

  // @scenario surface=pc_center_layout_uses_size
  it('PC 중앙은 설정 너비·높이를 쓴다', () => {
    const { container } = show([htmlPopup(1)], { pc_position: 'center', pc_width: 600, pc_height: 400 });
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    expect(dialog.className).toContain('g7pp-dialog--pc-center');
    expect(dialog.style.width).toBe('600px');
    expect(dialog.style.getPropertyValue('--g7pp-body-h')).toBe('400px');
  });

  // @scenario surface=pc_coords_layout_clamped_to_viewport
  it('PC 좌표는 left·top 을 쓰고, 창보다 크면 화면 안으로 끌어들이는 식이 붙는다', () => {
    const { container } = show([htmlPopup(1)], { pc_position: 'coords', pc_left: 80, pc_top: 120, pc_width: 480, pc_height: 520 });
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    expect(dialog.className).toContain('g7pp-dialog--pc-coords');
    expect(dialog.getAttribute('style')).toContain('min(80px');
    expect(dialog.getAttribute('style')).toContain('calc(100vw - 480px - 16px)');
    expect(dialog.getAttribute('style')).toContain('min(120px');
  });

  // @scenario surface=mobile_center_layout
  it('모바일 중앙형', () => {
    media.mobile = true;
    const { container } = show([htmlPopup(1)], { mobile_style: 'center' });
    const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

    expect(dialog.className).toContain('g7pp-dialog--m-center');
    expect(container.querySelector('.g7pp-handle')).toBeNull();
  });

  // @scenario surface=mobile_sheet_layout
  it('모바일 하단 시트형 — 손잡이 막대, 이전·다음은 아래 줄', () => {
    media.mobile = true;
    const { container } = show([htmlPopup(1), htmlPopup(2)], { mobile_style: 'sheet' });

    expect(container.querySelector('[role="dialog"]')?.className).toContain('g7pp-dialog--m-sheet');
    expect(container.querySelector('.g7pp-handle')?.getAttribute('aria-hidden')).toBe('true');
    expect(container.querySelector('.g7pp-arrow')).toBeNull();
    expect(container.querySelectorAll('.g7pp-nav__arrow')).toHaveLength(2);
  });

  // @scenario surface=sheet_lifts_above_gdpr_banner
  // @effects gdpr_banner_not_covered
  it('GDPR 배너가 바닥에 보이면 시트를 그 높이만큼 올린다', async () => {
    media.mobile = true;
    const banner = document.createElement('div');
    banner.id = GDPR_BANNER_ID;
    banner.getBoundingClientRect = () => ({ top: window.innerHeight - 104, bottom: window.innerHeight, height: 104, left: 0, right: 390, width: 390, x: 0, y: 0, toJSON: () => ({}) });
    document.body.appendChild(banner);

    const { container } = show([htmlPopup(1)], { mobile_style: 'sheet' });
    // 배너 관찰기(MutationObserver) 콜백은 마이크로태스크로 온다 — act 안에서 비운다
    await act(async () => {});

    expect((container.querySelector('[role="dialog"]') as HTMLElement).style.bottom).toBe('104px');
    await act(async () => { banner.remove(); });
    expect((container.querySelector('[role="dialog"]') as HTMLElement).style.bottom).toBe('0px');
  });

  // @scenario surface=sheet_rests_at_bottom_without_gdpr_banner
  it('GDPR 배너가 없으면 시트는 바닥에 붙는다', () => {
    media.mobile = true;
    const { container } = show([htmlPopup(1)], { mobile_style: 'sheet' });

    expect((container.querySelector('[role="dialog"]') as HTMLElement).style.bottom).toBe('0px');
  });

  // @scenario surface=controls_at_least_44px
  it('위치 점 단추는 가로·세로 44px 이상이고, 점이 많으면 줄을 바꾼다', async () => {
    const fs = await import('node:fs');
    const path = await import('node:path');
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
    const rule = (selector: string) => css.match(new RegExp(`\\${selector}\\s*\\{[^}]*\\}`))?.[0] ?? '';

    expect(rule('.g7pp-dot')).toMatch(/min-width:\s*44px;/);
    expect(rule('.g7pp-dot')).toMatch(/height:\s*44px;/);
    expect(rule('.g7pp-nav')).toMatch(/flex-wrap:\s*wrap;/);
  });

  // @scenario surface=z_index_below_gdpr_banner
  // @effects gdpr_banner_not_covered
  it('레이어는 GDPR 배너(z-50) 아래 층 — 스타일 파일이 z-index 40 을 준다', async () => {
    const fs = await import('node:fs');
    const path = await import('node:path');
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
    const root = css.match(/\.g7pp-root\s*\{[^}]*\}/)?.[0] ?? '';

    expect(root).toMatch(/z-index:\s*40;/);
  });

  // @scenario surface=image_uses_mobile_source_on_mobile
  it('이미지는 레이어가 판정한 기기로 고른다 — PC 는 PC 이미지(크기 포함), 모바일은 모바일 이미지', () => {
    const { container } = show([imagePopup(1)]);
    const img = container.querySelector('.g7pp-media img') as HTMLImageElement;

    expect(container.querySelector('picture')).toBeNull();
    expect(img.getAttribute('src')).toBe('/img/pc-1.jpg');
    expect(img.getAttribute('width')).toBe('480');
    expect(img.getAttribute('alt')).toBe('대체 1');
    expect(img.getAttribute('loading')).toBe('eager');
    cleanup();

    media.mobile = true;
    const { container: mobile } = show([imagePopup(1)]);
    const mobileImg = mobile.querySelector('.g7pp-media img') as HTMLImageElement;
    expect(mobileImg.getAttribute('src')).toBe('/img/m-1.jpg');
    expect(mobileImg.getAttribute('width')).toBe('750');
  });

  // @scenario surface=image_falls_back_to_pc_on_mobile
  it('모바일 이미지가 없으면 모바일에서도 PC 이미지를 쓴다', () => {
    media.mobile = true;
    const { container } = show([imagePopup(1, { image: { pc: { url: '/img/pc-1.jpg', width: 480, height: 520 }, mobile: null } })]);

    expect(container.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=device_follows_engine_width
  it('엔진 반응형 폭(G7Core.useResponsive)이 있으면 창 폭 대신 그 폭으로 기기를 판정한다', () => {
    installCore({ useResponsive: () => ({ width: 390 }) });
    const popups = [htmlPopup(1, '<p>pc</p>', { device: 'pc' }), htmlPopup(2, '<p>m</p>', { device: 'mobile' })];
    const { container } = show(popups);

    expect([...container.querySelectorAll('.g7pp-slide')].map((el) => Number(el.getAttribute('data-popup-id')))).toEqual([2]);
  });

  // @scenario surface=link_popup_uses_covering_link_without_nested_focusables
  // @effects accessible_modal_carousel
  it('링크 팝업은 덮는 빈 링크(이름 = alt), 링크 안에 포커스 요소가 없고 이미지 alt 는 비운다', () => {
    const dispatch = vi.fn();
    installCore({ dispatch });
    const { container } = show([imagePopup(1, { link_url: '/event/autumn' }), imagePopup(2)]);
    const link = container.querySelector('.g7pp-cover-link') as HTMLAnchorElement;

    expect(link.getAttribute('aria-label')).toBe('대체 1');
    expect(link.children).toHaveLength(0);
    expect(link.parentElement?.querySelector('img')?.getAttribute('alt')).toBe('');
    expect(link.closest('a')?.querySelector('button')).toBeNull();

    // 사이트 안 링크는 SPA 이동하고 레이어를 닫는다
    fireEvent.click(link);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/event/autumn' } });
    expect(container.querySelector('[role="dialog"]')).toBeNull();
  });

  // @scenario surface=link_new_window_rel_noopener
  it('새 창 링크는 target=_blank + rel=noopener noreferrer, SPA 이동을 가로채지 않는다', () => {
    const dispatch = vi.fn();
    installCore({ dispatch });
    const { container } = show([imagePopup(1, { link_url: 'https://example.com/promo', link_new_window: true })]);
    const link = container.querySelector('.g7pp-cover-link') as HTMLAnchorElement;

    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    fireEvent.click(link);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario surface=html_body_purified_on_render
  it('직접 작성형 본문은 출력 때 DOMPurify 로 한 번 더 거른다', () => {
    const { container } = show([htmlPopup(1, '<p onclick="x()">안내</p><script>alert(1)</script><iframe src="https://evil"></iframe><img src="x" onerror="y()">')]);
    const body = container.querySelector('.g7pp-html') as HTMLElement;

    expect(body.innerHTML).toContain('안내');
    expect(body.innerHTML).not.toContain('onclick');
    expect(body.innerHTML).not.toContain('<script');
    expect(body.innerHTML).not.toContain('<iframe');
    expect(body.innerHTML).not.toContain('onerror');
    expect(body.className).toContain('ck-content');
  });

  // @scenario surface=html_body_scroll_region_only_when_overflowing
  // @effects accessible_modal_carousel
  it('본문이 넘칠 때만 이름 붙은 스크롤 영역이 되어 탭으로 들어온다', () => {
    const heights = { scroll: 100, client: 200 };
    const scrollSpy = vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(() => heights.scroll);
    const clientSpy = vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(() => heights.client);

    const { container } = show([htmlPopup(1)]);
    let body = container.querySelector('.g7pp-html') as HTMLElement;
    expect(body.getAttribute('tabindex')).toBeNull();
    expect(body.getAttribute('role')).toBeNull();
    cleanup();

    heights.scroll = 900;
    const { container: long } = show([htmlPopup(2, '<p>긴 본문</p>')]);
    body = long.querySelector('.g7pp-html') as HTMLElement;
    expect(body.getAttribute('tabindex')).toBe('0');
    expect(body.getAttribute('role')).toBe('region');
    expect(body.getAttribute('aria-label')).toBe('팝업 본문');

    scrollSpy.mockRestore();
    clientSpy.mockRestore();
  });
  describe('끌기 슬라이드', () => {
    let rectSpy: ReturnType<typeof mockBodyWidth>;

    beforeEach(() => {
      (window as any).PointerEvent = TestPointerEvent;
      rectSpy = mockBodyWidth(400);
    });

    afterEach(() => {
      rectSpy.mockRestore();
      delete (window as any).PointerEvent;
    });

    // @scenario surface=drag_mouse_moves_to_next_and_previous
    // @effects accessible_modal_carousel
    it('마우스로 왼쪽으로 끌면 손을 따라 현재 장과 다음 장이 함께 밀리고, 놓으면 다음 장 — 오른쪽으로 끌면 이전 장', () => {
      const { container } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);
      const body = container.querySelector('.g7pp-body') as HTMLElement;

      dragOn(body, -120, { release: false });
      // 폭 400px 의 30% — 현재 장 -30%, 다음 장은 그 오른쪽(+70%)
      expect(slideX(container, 1)).toBe('translateX(-30%)');
      expect(slideX(container, 2)).toBe('translateX(70%)');
      expect(body.className).toContain('g7pp-body--dragging');
      fireEvent.pointerUp(body, { pointerId: 7, pointerType: 'mouse', clientX: 180, clientY: 200 });
      expect(currentId(container)).toBe(2);
      expect(body.className).not.toContain('g7pp-body--dragging');

      dragOn(body, 120);
      expect(currentId(container)).toBe(1);
    });

    // @scenario surface=drag_touch_moves_to_next
    it('손가락(터치 포인터)으로 끌어도 넘긴다 — 누른 자식이 놓친 포인터 잡기(lostpointercapture)로 끌기가 끊기지 않는다', () => {
      const { container } = show([htmlPopup(1), htmlPopup(2)]);
      const html = container.querySelector('.g7pp-html') as HTMLElement;
      const base = { pointerId: 5, pointerType: 'touch' };

      fireEvent.pointerDown(html, { ...base, clientX: 300, clientY: 200 });
      fireEvent.pointerMove(html, { ...base, clientX: 280, clientY: 200 });
      // 터치는 브라우저가 누른 요소에 포인터를 잡아 두었다가, 레이어가 잡으면 그 요소에서 lostpointercapture 가 올라온다
      fireEvent.lostPointerCapture(html, base);
      fireEvent.pointerMove(html, { ...base, clientX: 150, clientY: 200 });
      fireEvent.pointerUp(html, { ...base, clientX: 150, clientY: 200 });

      expect(currentId(container)).toBe(2);
    });

    // @scenario surface=drag_below_threshold_springs_back
    it('천천히 폭의 20% 미만만 끌고 놓으면 제자리로 돌아오고, 옆 장은 다시 숨는다', () => {
      vi.useFakeTimers();
      const { container } = show([htmlPopup(1), htmlPopup(2)]);
      const body = container.querySelector('.g7pp-body') as HTMLElement;

      dragOn(body, -60, { wait: 600 });

      expect(currentId(container)).toBe(1);
      expect(slideX(container, 1)).toBe('translateX(0%)');
      expect(slideX(container, 2)).toBe('translateX(100%)');
      act(() => { vi.advanceTimersByTime(SLIDE_MS + 80); });
      expect(shownIds(container)).toEqual([1]);
    });

    // @scenario surface=drag_flick_moves_even_if_short
    it('짧아도 빠르게 휙 넘기면 넘긴다(폭의 4% 이상)', () => {
      vi.useFakeTimers();
      const { container } = show([htmlPopup(1), htmlPopup(2)]);

      dragOn(container.querySelector('.g7pp-body') as HTMLElement, -40, { wait: 0 });

      expect(currentId(container)).toBe(2);
    });

    // @scenario surface=drag_vertical_intent_ignored
    it('세로 성분이 더 크면 끌기를 시작하지 않는다(본문 스크롤에 맡김) — 슬라이드 영역은 touch-action: pan-y', async () => {
      const { container } = show([htmlPopup(1), htmlPopup(2)]);
      const body = container.querySelector('.g7pp-body') as HTMLElement;

      dragOn(body, -100, { dy: 160, type: 'touch' });

      expect(currentId(container)).toBe(1);
      expect(shownIds(container)).toEqual([1]);
      expect(slideX(container, 1)).toBe('');
      const fs = await import('node:fs');
      const path = await import('node:path');
      const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
      expect(css.match(/\.g7pp-body\s*\{[^}]*\}/)?.[0] ?? '').toMatch(/touch-action:\s*pan-y/);
    });

    // @scenario surface=drag_wraps_as_single_step
    it('끝 → 처음, 처음 → 끝도 옆 장 하나만 들어오는 한 칸 넘김이다(가운데 장을 거쳐 되감지 않음)', () => {
      const { container } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);
      const body = container.querySelector('.g7pp-body') as HTMLElement;

      // 처음에서 오른쪽으로 끌면 마지막 장이 왼쪽에서 따라 들어온다
      dragOn(body, 100, { release: false });
      expect(shownIds(container)).toEqual([1, 3]);
      expect(slideX(container, 3)).toBe('translateX(-75%)');
      fireEvent.pointerUp(body, { pointerId: 7, pointerType: 'mouse', clientX: 400, clientY: 200 });
      expect(currentId(container)).toBe(3);
      // 마지막 장은 0 으로, 처음 장은 오른쪽 밖으로 — 2번 장은 끝까지 숨어 있다
      expect(shownIds(container)).toEqual([1, 3]);
      expect(slideX(container, 3)).toBe('translateX(0%)');
      expect(slideX(container, 1)).toBe('translateX(100%)');

      // 마지막에서 왼쪽으로 끌면 처음 장이 오른쪽에서 들어온다
      dragOn(body, -100);
      expect(currentId(container)).toBe(1);
      expect(shownIds(container)).toEqual([1, 3]);
      expect(slideX(container, 1)).toBe('translateX(0%)');
      expect(slideX(container, 3)).toBe('translateX(-100%)');
    });

    // @scenario surface=buttons_keys_autoplay_use_slide_transition
    it('이전·다음·점·←→ 도 들어올 장이 옆(±100%)에서 미끄러져 들어오고, 끝나면 옆 장을 숨긴다', () => {
      vi.useFakeTimers();
      const starts: string[] = [];
      // 들어올 장을 시작 자리에 둔 뒤 배치를 확정하는 순간의 위치를 기록한다
      const layoutSpy = vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function (this: HTMLElement) {
        const incoming = this.querySelector?.('.g7pp-slide:not([hidden]):not([aria-hidden="true"])') as HTMLElement | null;
        if (incoming) {
          starts.push(`${incoming.getAttribute('data-popup-id')}@${incoming.style.transform}${incoming.className.includes('--moving') ? ' moving' : ''}`);
        }

        return 400;
      });
      const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);
      const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

      fireEvent.click(getByLabelText('다음 팝업'));
      expect(starts.pop()).toBe('2@translateX(100%)');
      expect(container.querySelector('.g7pp-slide[data-popup-id="2"]')?.className).toContain('g7pp-slide--moving');
      expect(slideX(container, 2)).toBe('translateX(0%)');
      expect(slideX(container, 1)).toBe('translateX(-100%)');
      act(() => { vi.advanceTimersByTime(SLIDE_MS + 80); });
      expect(shownIds(container)).toEqual([2]);

      fireEvent.keyDown(dialog, { key: 'ArrowLeft' });
      expect(starts.pop()).toBe('1@translateX(-100%)');
      act(() => { vi.advanceTimersByTime(SLIDE_MS + 80); });

      // 점으로 멀리 건너뛰어도 옆 장 하나만 들어온다
      fireEvent.click(getByLabelText('3번째 팝업 보기'));
      expect(starts.pop()).toBe('3@translateX(100%)');
      expect(shownIds(container)).toEqual([1, 3]);
      layoutSpy.mockRestore();
    });

    // @scenario surface=drag_release_suppresses_link_click
    // @effects accessible_modal_carousel
    it('링크 장을 끌다 놓으면 뒤따르는 click 으로 이동하지 않는다(새 창 링크도) — 끌지 않은 클릭은 그대로 이동', () => {
      const dispatch = vi.fn();
      installCore({ dispatch });
      const { container } = show([imagePopup(1, { link_url: '/event/autumn' }), imagePopup(2, { link_url: 'https://example.com/promo', link_new_window: true })]);
      const inner = container.querySelector('.g7pp-slide[data-popup-id="1"] .g7pp-cover-link') as HTMLAnchorElement;
      const external = container.querySelector('.g7pp-slide[data-popup-id="2"] .g7pp-cover-link') as HTMLAnchorElement;

      dragOn(inner, -160);
      expect(fireEvent.click(inner)).toBe(false);
      expect(dispatch).not.toHaveBeenCalled();
      expect(currentId(container)).toBe(2);

      dragOn(external, 160);
      // 새 창 링크의 기본 동작(새 창 열기)도 막힌다
      expect(fireEvent.click(external)).toBe(false);
      expect(currentId(container)).toBe(1);

      // 누르고 그대로 놓은 클릭은 링크로 간다
      fireEvent.pointerDown(inner, { pointerId: 8, pointerType: 'mouse', button: 0, clientX: 300, clientY: 200 });
      fireEvent.pointerUp(inner, { pointerId: 8, pointerType: 'mouse', button: 0, clientX: 302, clientY: 201 });
      fireEvent.click(inner);
      expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/event/autumn' } });
    });

    // @scenario surface=single_popup_drag_disabled
    it('한 건이면 끌어도 움직이지 않고 클릭을 막지 않는다', () => {
      const dispatch = vi.fn();
      installCore({ dispatch });
      const { container } = show([imagePopup(1, { link_url: '/event/autumn' })]);
      const link = container.querySelector('.g7pp-cover-link') as HTMLAnchorElement;

      dragOn(link, -200);

      expect(slideX(container, 1)).toBe('');
      expect(container.querySelector('.g7pp-body--dragging')).toBeNull();
      fireEvent.click(link);
      expect(dispatch).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=reduced_motion_switches_without_sliding
    it('움직임 줄이기면 단추·끌기 모두 미끄러짐 없이 바로 바뀐다 — 스타일도 이동 전환을 그 설정에서만 준다', async () => {
      media.reduced = true;
      installMatchMedia();
      const { container, getByLabelText } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)]);

      fireEvent.click(getByLabelText('다음 팝업'));
      expect(shownIds(container)).toEqual([2]);
      expect(container.querySelector('.g7pp-slide--moving')).toBeNull();

      dragOn(container.querySelector('.g7pp-body') as HTMLElement, -200);
      expect(shownIds(container)).toEqual([3]);
      expect(container.querySelector('.g7pp-slide--moving')).toBeNull();

      const fs = await import('node:fs');
      const path = await import('node:path');
      const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
      expect(css).toMatch(/@media \(prefers-reduced-motion: no-preference\)\s*\{\s*\.g7pp-slide--moving\s*\{\s*transition:\s*transform/);
      expect(css).not.toMatch(/g7pp-fade/);
    });

    // @scenario surface=autoplay_paused_while_pressing
    it('누르고 있는 동안(누름~놓음) 자동 넘김이 멈춘다', () => {
      vi.useFakeTimers();
      const { container } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)], { autoplay: true, autoplay_interval: 4 });
      const body = container.querySelector('.g7pp-body') as HTMLElement;
      act(() => { fireEvent.blur(container.querySelector('[role="dialog"]') as HTMLElement, { relatedTarget: document.body }); });

      fireEvent.pointerDown(body, { pointerId: 3, pointerType: 'touch', clientX: 100, clientY: 100 });
      // 간격 두 번(2 → 3 으로 갈 시간)이 지나도 그대로
      act(() => { vi.advanceTimersByTime(9000); });
      expect(currentId(container)).toBe(1);

      fireEvent.pointerUp(body, { pointerId: 3, pointerType: 'touch', clientX: 100, clientY: 100 });
      act(() => { vi.advanceTimersByTime(4000); });
      expect(currentId(container)).toBe(2);
    });

    // @scenario surface=offscreen_slides_not_focusable
    // @effects accessible_modal_carousel
    it('화면 밖 장은 hidden, 넘기는 동안 보이는 옆 장은 aria-hidden + inert — 포커스 가둠에서도 빠지고, 떠나는 장의 포커스는 레이어로 옮긴다', () => {
      vi.useFakeTimers();
      const { container } = show([imagePopup(1, { link_url: '/a' }), imagePopup(2, { link_url: '/b' }), imagePopup(3, { link_url: '/c' })]);
      const dialog = container.querySelector('[role="dialog"]') as HTMLElement;
      const links = [...container.querySelectorAll<HTMLElement>('.g7pp-cover-link')];

      act(() => { links[0].focus(); });
      act(() => { fireEvent.keyDown(links[0], { key: 'ArrowRight' }); });

      expect(document.activeElement).toBe(dialog);
      const leaving = container.querySelector('.g7pp-slide[data-popup-id="1"]') as HTMLElement;
      expect(leaving.getAttribute('aria-hidden')).toBe('true');
      expect(leaving.hasAttribute('inert')).toBe(true);
      expect(leaving.hasAttribute('hidden')).toBe(false);
      expect((container.querySelector('.g7pp-slide[data-popup-id="3"]') as HTMLElement).hasAttribute('hidden')).toBe(true);

      // Tab 순환은 창 옮기기 손잡이 → 지금 장의 링크 → … — 옆 장 링크로 가지 않는다
      const buttons = [...dialog.querySelectorAll<HTMLElement>('button')];
      const last = buttons[buttons.length - 1];
      act(() => { last.focus(); });
      act(() => { fireEvent.keyDown(last, { key: 'Tab' }); });
      expect(document.activeElement).toBe(dialog.querySelector('.g7pp-grip'));
      const cycle = [...dialog.querySelectorAll<HTMLElement>('a[href], button:not([disabled])')].filter((el) => !el.closest('[hidden], [inert], [aria-hidden="true"]'));
      expect(cycle[1]).toBe(links[1]);
      expect(cycle).not.toContain(links[0]);

      act(() => { vi.advanceTimersByTime(SLIDE_MS + 80); });
      expect(leaving.hasAttribute('hidden')).toBe(true);
      expect(leaving.hasAttribute('inert')).toBe(false);
    });

    // @scenario surface=preview_drag_uses_rendered_width
    it('축소 미리보기 틀에서도 끈 거리는 실제 표시 폭 대비 비율로 잰다', () => {
      vi.useFakeTimers();
      rectSpy.mockRestore();
      // 480px 레이어가 0.25 배로 줄어 120px 로 보인다 — 30px 은 25%
      rectSpy = mockBodyWidth(120);
      const { container } = render(<PopupLayer popups={[htmlPopup(1), htmlPopup(2)]} settings={{ autoplay: false }} preview="pc" />);

      dragOn(container.querySelector('.g7pp-body') as HTMLElement, -30, { wait: 600 });
      expect(currentId(container)).toBe(2);
      cleanup();

      // 같은 30px 도 줄이지 않은 480px 폭이면 6% — 천천히 끌면 제자리
      rectSpy.mockRestore();
      rectSpy = mockBodyWidth(480);
      const { container: full } = render(<PopupLayer popups={[htmlPopup(1), htmlPopup(2)]} settings={{ autoplay: false }} preview="pc" />);
      dragOn(full.querySelector('.g7pp-body') as HTMLElement, -30, { wait: 600 });
      expect(currentId(full)).toBe(1);
    });
  });

  describe('창 옮기기', () => {
    /** 옮기기 전 창 자리 — PC 중앙 480×560 이 1280×768 화면 가운데 */
    const BASE = { left: 400, top: 104, width: 480, height: 560 };
    let rectSpy: { mockRestore: () => void };

    /**
     * 창·슬라이드 영역의 표시 위치를 흉내 낸다 — 창은 옮긴 거리(인라인 `--g7pp-move-*`)만큼 비켜 선다.
     *
     * @param base 옮기기 전 창 자리
     */
    const mockDialogRect = (base: typeof BASE) => vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      if (this.classList.contains('g7pp-dialog')) {
        const left = base.left + (parseFloat(this.style.getPropertyValue('--g7pp-move-x')) || 0);
        const top = base.top + (parseFloat(this.style.getPropertyValue('--g7pp-move-y')) || 0);

        return { width: base.width, height: base.height, left, top, right: left + base.width, bottom: top + base.height, x: left, y: top, toJSON: () => ({}) } as DOMRect;
      }
      // 본문 영역 — 창 높이에서 손잡이·띠(160px)를 뺀 높이
      const isBody = this.classList.contains('g7pp-body');
      const w = isBody ? 400 : 0;
      const h = isBody ? base.height - 160 : 0;

      return { width: w, height: h, top: 0, left: 0, right: w, bottom: h, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });

    /** 창의 옮긴 거리 (없으면 '') */
    const moved = (container: HTMLElement) => {
      const dialog = container.querySelector('.g7pp-dialog') as HTMLElement;

      return [dialog.style.getPropertyValue('--g7pp-move-x'), dialog.style.getPropertyValue('--g7pp-move-y')];
    };

    /**
     * 요소를 눌러 끌고 놓는다.
     *
     * @param target 누르는 요소
     * @param dx 가로 이동(px)
     * @param dy 세로 이동(px)
     * @param options type 포인터 종류, release 놓기까지, wait 놓기 전 기다림(ms, 가짜 시계)
     */
    const holdAndMove = (target: Element, dx: number, dy: number, options: { type?: 'mouse' | 'touch'; release?: boolean; wait?: number } = {}) => {
      const { type = 'mouse', release = true, wait = 0 } = options;
      const base = { pointerId: 9, pointerType: type, button: 0, bubbles: true };
      fireEvent.pointerDown(target, { ...base, clientX: 300, clientY: 200 });
      if (wait > 0) {
        act(() => { vi.advanceTimersByTime(wait); });
      }
      fireEvent.pointerMove(target, { ...base, clientX: 300 + dx / 2, clientY: 200 + dy / 2 });
      fireEvent.pointerMove(target, { ...base, clientX: 300 + dx, clientY: 200 + dy });
      if (release) {
        fireEvent.pointerUp(target, { ...base, clientX: 300 + dx, clientY: 200 + dy });
      }
    };

    beforeEach(() => {
      (window as any).PointerEvent = TestPointerEvent;
      Object.defineProperty(window, 'innerHeight', { value: 768, configurable: true, writable: true });
      rectSpy = mockDialogRect(BASE);
    });

    afterEach(() => {
      rectSpy.mockRestore();
      delete (window as any).PointerEvent;
    });

    // @scenario surface=window_move_grip_drags_dialog
    // @effects accessible_modal_carousel
    it('위쪽 손잡이 띠를 잡고 끌면 창이 손을 따라 옮겨지고, 장은 넘어가지 않으며 누르는 동안 자동 넘김이 멈춘다', async () => {
      vi.useFakeTimers();
      const { container } = show([htmlPopup(1), htmlPopup(2), htmlPopup(3)], { autoplay: true, autoplay_interval: 1 });
      const dialog = container.querySelector('[role="dialog"]') as HTMLElement;
      const grip = container.querySelector('.g7pp-grip') as HTMLElement;

      expect(grip.tagName).toBe('BUTTON');
      expect(grip.getAttribute('aria-label')).toBe('팝업 창 옮기기 — 화살표 키로 옮기고 Home 키로 제자리');
      // 손잡이는 창 맨 위, 슬라이드 영역 밖이다
      expect(dialog.firstElementChild).toBe(grip);

      holdAndMove(grip, -50, 60, { release: false });
      expect(moved(container)).toEqual(['-50px', '60px']);
      expect(dialog.className).toContain('g7pp-dialog--held');
      act(() => { vi.advanceTimersByTime(3000); });
      expect(currentId(container)).toBe(1);
      expect(slideX(container, 1)).toBe('');

      fireEvent.pointerUp(grip, { pointerId: 9, pointerType: 'mouse', clientX: 250, clientY: 260 });
      expect(dialog.className).not.toContain('g7pp-dialog--held');
      expect(moved(container)).toEqual(['-50px', '60px']);

      const fs = await import('node:fs');
      const path = await import('node:path');
      const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
      // 옮긴 거리는 기존 배치(transform)와 따로 더해지는 translate 로 준다 — 중앙 정렬 transform 을 덮지 않는다
      expect(css.match(/\.g7pp-dialog\s*\{[^}]*\}/)?.[0] ?? '').toMatch(/translate:\s*var\(--g7pp-move-x, 0px\) var\(--g7pp-move-y, 0px\)/);
      // 손가락으로 잡아도 화면이 스크롤·새로고침되지 않게
      expect(css.match(/\.g7pp-grip\s*\{[^}]*\}/)?.[0] ?? '').toMatch(/touch-action:\s*none/);
      expect(css.match(/\.g7pp-handle\s*\{[^}]*\}/)?.[0] ?? '').toMatch(/touch-action:\s*none/);
    });

    // @scenario surface=window_move_clamped_to_viewport
    it('창은 화면 밖으로 빠지지 않는다 — 가장자리 8px 안쪽에서 멈춘다', () => {
      const { container } = show([htmlPopup(1)]);
      const grip = container.querySelector('.g7pp-grip') as HTMLElement;

      holdAndMove(grip, -2000, 2000);
      // 왼쪽 끝 8px(400 → 8), 아래 끝 768 - 560 - 8 = 200(104 → 200)
      expect(moved(container)).toEqual(['-392px', '96px']);

      holdAndMove(grip, 5000, -5000);
      // 오른쪽 끝 1280 - 480 - 8 = 792, 위 끝 8
      expect(moved(container)).toEqual(['392px', '-96px']);
    });

    // @scenario surface=window_clears_top_fixed_header
    it('화면 위에 고정된 헤더(팝업 층 이상)가 손잡이를 덮으면 그 아래로 비켜 뜨고, 옮겨도 그 위로는 올라가지 않는다 — 팝업 층 아래 고정 요소는 무시', () => {
      const header = document.createElement('header');
      header.style.position = 'sticky';
      header.style.zIndex = '50';
      document.body.appendChild(header);
      const headerRect = vi.spyOn(header, 'getBoundingClientRect').mockReturnValue({ top: 0, bottom: 114, left: 0, right: 1280, width: 1280, height: 114, x: 0, y: 0, toJSON: () => ({}) } as DOMRect);
      (document as any).elementsFromPoint = vi.fn(() => [header, document.body, document.documentElement]);

      try {
        const { container, unmount } = show([htmlPopup(1)]);
        const grip = container.querySelector('.g7pp-grip') as HTMLElement;
        // 104 → 헤더 아래 114 + 8 = 122
        expect(moved(container)).toEqual(['0px', '18px']);

        holdAndMove(grip, 0, -5000);
        expect(moved(container)).toEqual(['0px', '18px']);
        holdAndMove(grip, 0, 40);
        expect(moved(container)).toEqual(['0px', '58px']);
        act(() => { grip.focus(); });
        fireEvent.keyDown(grip, { key: 'Home' });
        expect(moved(container)).toEqual(['0px', '18px']);
        unmount();

        // 팝업 층(40)보다 아래인 고정 요소는 팝업을 덮지 않는다
        header.style.zIndex = '10';
        const low = show([htmlPopup(1)]);
        expect(moved(low.container)).toEqual(['', '']);
      } finally {
        headerRect.mockRestore();
        delete (document as any).elementsFromPoint;
        header.remove();
      }
    });

    // @scenario surface=window_fits_above_gdpr_banner
    // @effects gdpr_banner_not_covered
    it('GDPR 배너가 바닥에 보이면 PC 창도 그 위로 올려 띠(닫기)가 덮이지 않게 하고, 자리가 모자라면 본문 높이를 줄인다', async () => {
      const banner = document.createElement('div');
      banner.id = GDPR_BANNER_ID;
      banner.getBoundingClientRect = () => ({ top: 620, bottom: 752, height: 132, left: 16, right: 1264, width: 1248, x: 16, y: 620, toJSON: () => ({}) });
      document.body.appendChild(banner);

      try {
        const { container } = show([htmlPopup(1)]);
        await act(async () => {});
        const dialog = container.querySelector('[role="dialog"]') as HTMLElement;
        // 배너 윗변 620 - 8 = 612 까지 — 560 높이 창의 위 끝은 52(104 → 52)
        expect(moved(container)).toEqual(['0px', '-52px']);
        // 본문은 768 - 148(배너) - 16(위아래 여백) - 160(손잡이·띠) = 444 까지
        expect(dialog.style.getPropertyValue('--g7pp-fit-h')).toBe('444px');
        // 아래로 끌어도 배너 밑으로 들어가지 않는다
        holdAndMove(container.querySelector('.g7pp-grip') as HTMLElement, 0, 3000);
        expect(moved(container)).toEqual(['0px', '-52px']);

        // 동의로 배너가 빠지면 본문 상한이 풀린다(옮긴 자리는 그대로)
        await act(async () => { banner.remove(); });
        expect(dialog.style.getPropertyValue('--g7pp-fit-h')).toBe('592px');
        expect(moved(container)).toEqual(['0px', '-52px']);

        const fs = await import('node:fs');
        const path = await import('node:path');
        const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
        expect(css.match(/\.g7pp-body\s*\{[^}]*\}/)?.[0] ?? '').toMatch(/var\(--g7pp-fit-h, 100vh\)/);
      } finally {
        banner.remove();
      }
    });

    // @scenario surface=window_move_keyboard_arrows
    it('손잡이에 포커스를 두고 화살표 키로 16px 씩 옮기고 Home 으로 제자리 — 이때 ←→ 는 장을 넘기지 않는다', () => {
      const { container } = show([htmlPopup(1), htmlPopup(2)]);
      const grip = container.querySelector('.g7pp-grip') as HTMLElement;

      act(() => { grip.focus(); });
      fireEvent.keyDown(grip, { key: 'ArrowRight' });
      fireEvent.keyDown(grip, { key: 'ArrowDown' });
      fireEvent.keyDown(grip, { key: 'ArrowDown' });
      expect(moved(container)).toEqual(['16px', '32px']);
      expect(currentId(container)).toBe(1);

      fireEvent.keyDown(grip, { key: 'ArrowLeft' });
      fireEvent.keyDown(grip, { key: 'ArrowUp' });
      expect(moved(container)).toEqual(['0px', '16px']);

      fireEvent.keyDown(grip, { key: 'Home' });
      expect(moved(container)).toEqual(['', '']);
      expect(currentId(container)).toBe(1);

      // 손잡이 밖에서는 ←→ 가 그대로 장을 넘긴다
      fireEvent.keyDown(container.querySelector('[role="dialog"]') as HTMLElement, { key: 'ArrowRight' });
      expect(currentId(container)).toBe(2);
    });

    // @scenario surface=window_move_resets_on_path_change
    it('옮긴 자리는 기억하지 않는다 — 다른 페이지로 가면 원래 자리, 새로 열려도 원래 자리', () => {
      const popups = [htmlPopup(1)];
      const { container, rerender, unmount } = show(popups);

      holdAndMove(container.querySelector('.g7pp-grip') as HTMLElement, 40, 40);
      expect(moved(container)).toEqual(['40px', '40px']);
      rerender(<PopupLayer popups={popups} settings={{ autoplay: false }} path="/board" />);
      expect(moved(container)).toEqual(['', '']);

      holdAndMove(container.querySelector('.g7pp-grip') as HTMLElement, 40, 40);
      unmount();
      const again = show(popups, {}, '/board');
      expect(moved(again.container)).toEqual(['', '']);
      expect(document.cookie).not.toContain('move');
      expect(window.sessionStorage.length).toBe(0);
      expect(window.localStorage.length).toBe(0);
    });

    // @scenario surface=window_move_reclamped_on_resize
    it('브라우저 창이 좁아지면 옮긴 창을 다시 화면 안으로 들인다', () => {
      const { container } = show([htmlPopup(1)]);

      holdAndMove(container.querySelector('.g7pp-grip') as HTMLElement, 5000, 0);
      expect(moved(container)).toEqual(['392px', '0px']);

      act(() => {
        Object.defineProperty(window, 'innerWidth', { value: 1000, configurable: true, writable: true });
        window.dispatchEvent(new Event('resize'));
      });
      // 1000 - 480 - 8 = 512 → 400 에서 112
      expect(moved(container)).toEqual(['112px', '0px']);
    });

    // @scenario surface=window_move_mobile_center_by_touch
    it('휴대폰 가운데 창도 손잡이를 손가락으로 끌어 옮긴다', () => {
      media.mobile = true;
      rectSpy.mockRestore();
      // 375px 화면의 좌우 24px 여백 창
      rectSpy = mockDialogRect({ left: 24, top: 184, width: 327, height: 400 });
      const { container } = show([htmlPopup(1), htmlPopup(2)], { mobile_style: 'center' });
      const grip = container.querySelector('.g7pp-grip') as HTMLElement;

      expect(grip).not.toBeNull();
      expect(container.querySelector('.g7pp-handle')).toBeNull();
      holdAndMove(grip, 0, -80, { type: 'touch' });

      expect(moved(container)).toEqual(['0px', '-80px']);
      expect(currentId(container)).toBe(1);
    });

    // @scenario surface=sheet_drag_down_closes
    it('휴대폰 하단 시트는 손잡이 막대를 아래로 끌어 내리면 닫힌다(「닫기」와 같음) — 끄는 동안 손을 따라 내려간다', () => {
      media.mobile = true;
      rectSpy.mockRestore();
      rectSpy = mockDialogRect({ left: 0, top: 368, width: 375, height: 400 });
      const { container, unmount } = show([htmlPopup(1), htmlPopup(2)], { mobile_style: 'sheet' });
      const handle = container.querySelector('.g7pp-handle') as HTMLElement;

      // 시트는 창 옮기기 손잡이 띠가 없다
      expect(container.querySelector('.g7pp-grip')).toBeNull();
      holdAndMove(handle, 30, 150, { type: 'touch', release: false });
      // 아래로만 따라간다(가로는 무시)
      expect(moved(container)).toEqual(['0px', '150px']);
      fireEvent.pointerUp(handle, { pointerId: 9, pointerType: 'touch', clientX: 330, clientY: 350 });

      expect(container.querySelector('[role="dialog"]')).toBeNull();
      // 「닫기」처럼 이 페이지 사용 중에는 다시 뜨지 않는다
      unmount();
      expect(show([htmlPopup(1), htmlPopup(2)], { mobile_style: 'sheet' }).container.querySelector('[role="dialog"]')).toBeNull();
      expect(document.cookie).not.toContain(DISMISS_COOKIE);
    });

    // @scenario surface=sheet_drag_short_springs_back
    it('시트를 조금만 천천히 내리거나 위로 끌면 제자리로 돌아온다 — 놓은 뒤 미끄러져 돌아오는 전환은 움직임 줄이기가 아닐 때만', async () => {
      vi.useFakeTimers();
      media.mobile = true;
      rectSpy.mockRestore();
      rectSpy = mockDialogRect({ left: 0, top: 368, width: 375, height: 400 });
      const { container } = show([htmlPopup(1)], { mobile_style: 'sheet' });
      const handle = container.querySelector('.g7pp-handle') as HTMLElement;
      const dialog = container.querySelector('[role="dialog"]') as HTMLElement;

      holdAndMove(handle, 0, 60, { type: 'touch', wait: 600 });
      expect(container.querySelector('[role="dialog"]')).not.toBeNull();
      expect(moved(container)).toEqual(['', '']);
      expect(dialog.className).not.toContain('g7pp-dialog--held');

      // 위로는 올라가지 않는다
      holdAndMove(handle, 0, -200, { type: 'touch', release: false });
      expect(moved(container)).toEqual(['', '']);
      fireEvent.pointerUp(handle, { pointerId: 9, pointerType: 'touch', clientX: 300, clientY: 0 });
      expect(container.querySelector('[role="dialog"]')).not.toBeNull();

      const fs = await import('node:fs');
      const path = await import('node:path');
      const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
      const motionBlock = css.match(/@media \(prefers-reduced-motion: no-preference\)\s*\{[\s\S]*?\n\}/g)?.join('\n') ?? '';
      expect(motionBlock).toMatch(/\.g7pp-dialog--m-sheet:not\(\.g7pp-dialog--held\)\s*\{\s*transition:\s*translate/);
    });

    // @scenario surface=preview_window_move_disabled
    it('관리자 미리보기에서는 손잡이를 같은 모양으로 그리되 옮기거나 닫지 않는다', () => {
      const { container } = render(<PopupLayer popups={[htmlPopup(1)]} settings={{ autoplay: false }} preview="pc" />);
      const grip = container.querySelector('.g7pp-grip') as HTMLElement;

      expect(grip.tagName).not.toBe('BUTTON');
      expect(grip.getAttribute('aria-hidden')).toBe('true');
      holdAndMove(grip, 50, 50);
      expect(moved(container)).toEqual(['', '']);
      cleanup();

      const sheet = render(<PopupLayer popups={[htmlPopup(1)]} settings={{ autoplay: false, mobile_style: 'sheet' }} preview="mobile" />);
      holdAndMove(sheet.container.querySelector('.g7pp-handle') as HTMLElement, 0, 300, { type: 'touch' });
      expect(sheet.container.querySelector('[data-popup-layer="mobile"] .g7pp-dialog')).not.toBeNull();
      expect(moved(sheet.container)).toEqual(['', '']);
    });
  });
});
