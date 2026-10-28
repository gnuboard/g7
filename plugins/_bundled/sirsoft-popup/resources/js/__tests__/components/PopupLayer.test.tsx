/**
 * @file PopupLayer.test.tsx
 * @description 사용자 팝업 레이어 — 판정(경로·기기·보지 않기·닫음)·캐러셀·자동 넘김·접근성·닫기·보지 않기·배치·GDPR 공존·이미지·링크·본문.
 * (매니페스트 popup-layer.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { PopupLayer, GDPR_BANNER_ID } from '../../components/composite/PopupLayer';
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
const currentId = (container: HTMLElement) => Number(container.querySelector('.g7pp-slide:not([hidden])')?.getAttribute('data-popup-id'));

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
});
