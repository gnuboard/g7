/**
 * @file PopupPreview.test.tsx
 * @description 관리자 미리보기 — 사용자 레이어와 같은 마크업을 기기 틀 안에 그리고, 링크·닫기는 동작하지 않는다.
 * (매니페스트 popup-layer.yaml — preview_renders_same_markup_with_links_inert)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { PopupPreview } from '../../components/composite/PopupPreview';
import { installCore, setViewportMobile } from './core';

describe('PopupPreview', () => {
  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  // @scenario surface=preview_renders_same_markup_with_links_inert
  // @effects accessible_modal_carousel
  it('폼 값(현재 언어 탭)을 레이어 마크업으로 그리되 링크·닫기·포커스 가둠은 없다', () => {
    const dispatch = vi.fn();
    installCore({ dispatch });
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;

    const { container, getByText } = render(
      <PopupPreview
        device="mobile"
        settings={{ mobile_style: 'sheet', dismiss_mode: 'today' }}
        locale="en"
        defaultLocale="ko"
        form={{
          content_type: 'image',
          image_alt: { ko: '가을', en: 'Autumn' },
          link_url: '/event',
          images: { pc: { url: '/img/pc.jpg', width: 480, height: 520 }, mobile: null },
        }}
      />,
    );

    expect(container.querySelector('[data-preview-device="mobile"]')).not.toBeNull();
    const dialog = container.querySelector('.g7pp-dialog') as HTMLElement;
    // 관리자 화면 안이라 모달이 아니다
    expect(dialog.getAttribute('role')).toBe('group');
    expect(dialog.getAttribute('aria-modal')).toBeNull();
    expect(dialog.className).toContain('g7pp-dialog--m-sheet');
    // 언어 탭(en) 값
    const link = container.querySelector('.g7pp-cover-link') as HTMLAnchorElement;
    expect(link.getAttribute('aria-label')).toBe('Autumn');
    expect(link.getAttribute('tabindex')).toBe('-1');
    // 모바일 이미지가 없으면 PC 이미지
    expect(container.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/pc.jpg');

    fireEvent.click(link);
    fireEvent.click(getByText('닫기'));
    expect(dispatch).not.toHaveBeenCalled();
    expect(container.querySelector('.g7pp-dialog')).not.toBeNull();
  });

  // @scenario surface=preview_mobile_shows_mobile_image
  it('모바일 미리보기는 관리자 창이 넓어도 모바일 이미지를 보인다', () => {
    setViewportMobile(false);
    installCore();
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const form = {
      content_type: 'image',
      image_alt: { ko: '가을' },
      images: { pc: { url: '/img/pc.jpg', width: 480, height: 520 }, mobile: { url: '/img/m.jpg', width: 750, height: 900 } },
    };

    const { container } = render(<PopupPreview device="mobile" settings={{}} locale="ko" defaultLocale="ko" form={form as any} />);
    expect(container.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/m.jpg');
    cleanup();

    const { container: pc } = render(<PopupPreview device="pc" settings={{}} locale="ko" defaultLocale="ko" form={form as any} />);
    expect(pc.querySelector('.g7pp-media img')?.getAttribute('src')).toBe('/img/pc.jpg');
  });

  // @scenario surface=preview_body_height_from_frame
  it('미리보기 본문 높이는 틀 높이(--g7pp-frame-h)로 잰다 — 100% 는 높이 없는 대화상자에서 풀리지 않아 0px 로 접힌다', async () => {
    installCore();
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const { container } = render(<PopupPreview device="mobile" settings={{}} />);
    const screen = container.querySelector('.g7pp-frame__screen') as HTMLElement;
    expect(screen.style.getPropertyValue('--g7pp-frame-h')).toBe('780px');

    const fs = await import('node:fs');
    const path = await import('node:path');
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/main.css'), 'utf8');
    const body = css.match(/\.g7pp-root--preview \.g7pp-body\s*\{[^}]*\}/)?.[0] ?? '';
    expect(body).toContain('var(--g7pp-frame-h');
    expect(body).not.toMatch(/100%/);
  });

  // @scenario surface=preview_pager_overlays_body
  it('미리보기도 넘김 컨트롤을 본문 안 아래 가운데 캡슐로 그린다 — 별도 띠가 없고, 본문 높이 몫에도 띠가 빠져 있다', async () => {
    installCore();
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;

    for (const device of ['pc', 'mobile'] as const) {
      const { container } = render(<PopupPreview device={device} settings={{ autoplay: true, mobile_style: 'sheet' }} />);
      const body = container.querySelector('.g7pp-body') as HTMLElement;
      const pager = body.querySelector(':scope > .g7pp-pager') as HTMLElement;

      expect(container.querySelector('.g7pp-nav')).toBeNull();
      expect(pager.querySelectorAll('.g7pp-dot')).toHaveLength(3);
      // 미리보기는 넘기지 않으므로 일시정지 단추가 없다
      expect(pager.querySelector('.g7pp-pager__pause')).toBeNull();
      // 휴대폰 틀도 이전·다음은 본문 양옆
      expect(body.querySelectorAll(':scope > .g7pp-arrow')).toHaveLength(2);
      expect(body.className).toContain('g7pp-body--paged');
      cleanup();
    }

    const fs = await import('node:fs');
    const path = await import('node:path');
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/main.css'), 'utf8');
    expect(css.match(/\.g7pp-root--preview \.g7pp-body\s*\{[^}]*\}/)?.[0] ?? '').toContain('calc(var(--g7pp-frame-h, 800px) - 116px)');
  });

  // @scenario surface=preview_follows_editor_body_written_without_render
  it('편집기가 화면을 다시 그리지 않고 쓴 본문도 다른 칸을 건드리지 않고 바로 미리보기에 보인다', () => {
    // CKEditor 5 는 타이핑마다 setLocal({ render: false }) 로 `_local.form.body_html` 만 바꾼다 — props 는 그대로이고 상태 구독만 알림을 받는다
    let global: Record<string, any> = { _local: { form: { content_type: 'html', body_html: { ko: '<p>Notice three</p>' } } } };
    const listeners = new Set<(state: Record<string, any>) => void>();
    const unsubscribe = vi.fn();
    installCore({
      state: {
        getLocal: () => global._local,
        subscribe: (listener: (state: Record<string, any>) => void) => {
          listeners.add(listener);

          return () => { unsubscribe(); listeners.delete(listener); };
        },
      },
    });
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const form = { content_type: 'html' as const, body_html: { ko: '<p>Notice three</p>' } };
    const write = (body: Record<string, string>) => act(() => {
      global = { ...global, _local: { ...global._local, form: { ...global._local.form, body_html: body } } };
      listeners.forEach((listener) => listener(global));
    });

    const { container, rerender, unmount } = render(<PopupPreview device="pc" locale="ko" defaultLocale="ko" form={form} />);
    const body = () => container.querySelector('.g7pp-html')?.textContent;
    expect(body()).toBe('Notice three');

    write({ ko: '<p>Notice four</p>' });
    expect(body()).toBe('Notice four');
    write({ ko: '<p>Notice five</p>' });
    expect(body()).toBe('Notice five');

    // 본문과 무관한 상태 변경은 본문을 되돌리지 않는다
    act(() => listeners.forEach((listener) => listener(global)));
    expect(body()).toBe('Notice five');

    // 다른 칸이 화면을 다시 그리면 그때의 props 가 정본이다
    rerender(<PopupPreview device="pc" locale="ko" defaultLocale="ko" form={{ ...form, body_html: { ko: '<p>Notice six</p>' } }} />);
    expect(body()).toBe('Notice six');

    unmount();
    expect(unsubscribe).toHaveBeenCalled();
  });

  // @scenario surface=preview_mounts_with_latest_editor_body
  it('새로 그려지는 미리보기(실제 크기 창)는 props 스냅숏이 옛 본문이어도 마운트할 때 상태의 최신 본문을 그린다 — 알림을 기다리지 않는다', () => {
    // 편집기는 이미 상태에만 최신 본문을 써 두었고(알림은 지나갔다), 창은 부모 `_local` 스냅숏(옛 본문)을 props 로 받는다
    const global = { _local: { form: { content_type: 'html', body_html: { ko: '<p>Typed now</p>', en: '<p>Typed en</p>' } } } };
    installCore({ state: { get: () => global, subscribe: () => () => {} } });
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const stale = { content_type: 'html' as const, body_html: { ko: '<p>Saved before</p>' } };

    const { container, unmount } = render(<PopupPreview device="pc" locale="ko" defaultLocale="ko" form={stale} actualSize />);
    expect(container.querySelector('.g7pp-html')?.textContent).toBe('Typed now');
    unmount();

    // 미리보기 언어를 바꿔 열어도 같은 최신 본문의 그 언어
    const en = render(<PopupPreview device="pc" locale="en" defaultLocale="ko" form={stale} actualSize />);
    expect(en.container.querySelector('.g7pp-html')?.textContent).toBe('Typed en');
    en.unmount();

    // 새 팝업: 스냅숏에 본문이 아예 없어도
    const fresh = render(<PopupPreview device="pc" locale="ko" defaultLocale="ko" form={{ content_type: 'html' }} actualSize />);
    expect(fresh.container.querySelector('.g7pp-html')?.textContent).toBe('Typed now');
  });

  // @scenario surface=preview_actual_size_scrolls_to_dialog
  it('실제 크기 틀이 칸보다 크면 열릴 때 팝업이 칸 가운데 오도록 스크롤한다(좁은 휴대폰에서 PC 실제 크기)', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => { cb(0); return 0; });
    const rect = (left: number, top: number, width: number, height: number) => ({ left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) }) as DOMRect;
    const rects = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      if (this.classList.contains('g7pp-frame-scroll')) return rect(40, 100, 310, 600);
      if (this.classList.contains('g7pp-dialog')) return rect(441, 140, 480, 520);
      return rect(0, 0, 0, 0);
    });
    const widths = vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(function (this: HTMLElement) { return this.classList.contains('g7pp-frame-scroll') ? 310 : 0; });
    const heights = vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function (this: HTMLElement) { return this.classList.contains('g7pp-frame-scroll') ? 600 : 0; });

    try {
      const { container } = render(<PopupPreview device="pc" actualSize />);
      const scroller = container.querySelector('.g7pp-frame-scroll') as HTMLElement;
      // 팝업 가운데(441 + 240 = 681)가 칸 가운데(40 + 155 = 195)로 오게 → 486. 세로는 이미 가운데라 0
      expect(scroller.scrollLeft).toBe(486);
      expect(scroller.scrollTop).toBe(0);
    } finally {
      rects.mockRestore();
      widths.mockRestore();
      heights.mockRestore();
      vi.unstubAllGlobals();
    }
  });

  // @scenario surface=preview_actual_size_frame
  it('실제 크기로 보면 틀을 줄이지 않고(배율 1) 기기 크기 그대로 두며, 넘치는 부분은 감싼 칸이 스크롤한다', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    for (const [device, width, height] of [['pc', 1280, 800], ['mobile', 390, 780]] as const) {
      const { container, unmount } = render(<PopupPreview device={device} actualSize />);
      const frame = container.querySelector(`[data-preview-device="${device}"]`) as HTMLElement;
      expect(frame.parentElement?.className).toBe('g7pp-frame-scroll');
      expect(frame.className).toContain('g7pp-frame--actual');
      expect(frame.style.width).toBe(`${width}px`);
      expect(frame.style.height).toBe(`${height}px`);
      expect((frame.querySelector('.g7pp-frame__screen') as HTMLElement).style.transform).toBe('scale(1)');
      unmount();
    }

    // 기본(줄인 틀)은 감싼 칸이 없다
    const { container } = render(<PopupPreview device="pc" />);
    expect(container.querySelector('.g7pp-frame-scroll')).toBeNull();
    expect(container.querySelector('.g7pp-frame--actual')).toBeNull();
  });

  // @scenario surface=preview_coerces_numeric_settings_from_form
  it('표시 방식 입력칸의 문자열 숫자를 수로 바꾸고, 비었거나 숫자가 아니면 기본값을 쓴다(환경설정 입력 값을 그대로 넘겨도 된다)', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const { container } = render(<PopupPreview device="pc" settings={{ pc_position: 'coords', pc_left: '40' as any, pc_top: '' as any, pc_width: '600' as any, pc_height: 'x' as any }} />);
    const dialog = container.querySelector('.g7pp-dialog') as HTMLElement;

    expect(dialog.style.left).toContain('min(40px');
    expect(dialog.style.left).toContain('600px');
    // 빈 칸은 기본값(120) — 0 으로 읽지 않는다. 숫자가 아니면 기본 높이(520)
    expect(dialog.style.top).toContain('min(120px');
    expect(dialog.style.top).toContain('- 520px');
    expect(dialog.style.width).toBe('600px');
  });

  /** @effects preview_sample_slides */
  it('폼이 없으면(환경설정 화면) 표본 3장을 그린다', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const { container } = render(<PopupPreview device="pc" settings={{ pc_position: 'coords', pc_left: 40, pc_top: 60 }} />);

    expect(container.querySelectorAll('.g7pp-slide')).toHaveLength(3);
    expect(container.querySelector('.g7pp-dialog')?.className).toContain('g7pp-dialog--pc-coords');
  });
});
