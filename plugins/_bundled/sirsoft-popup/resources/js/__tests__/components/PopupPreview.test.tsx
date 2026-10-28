/**
 * @file PopupPreview.test.tsx
 * @description 관리자 미리보기 — 사용자 레이어와 같은 마크업을 기기 틀 안에 그리고, 링크·닫기는 동작하지 않는다.
 * (매니페스트 popup-layer.yaml — preview_renders_same_markup_with_links_inert)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
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
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/popup.css'), 'utf8');
    const body = css.match(/\.g7pp-root--preview \.g7pp-body\s*\{[^}]*\}/)?.[0] ?? '';
    expect(body).toContain('var(--g7pp-frame-h');
    expect(body).not.toMatch(/100%/);
  });

  /** @effects preview_sample_slides */
  it('폼이 없으면(환경설정 화면) 표본 3장을 그린다', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as any;
    const { container } = render(<PopupPreview device="pc" settings={{ pc_position: 'coords', pc_left: 40, pc_top: 60 }} />);

    expect(container.querySelectorAll('.g7pp-slide')).toHaveLength(3);
    expect(container.querySelector('.g7pp-dialog')?.className).toContain('g7pp-dialog--pc-coords');
  });
});
