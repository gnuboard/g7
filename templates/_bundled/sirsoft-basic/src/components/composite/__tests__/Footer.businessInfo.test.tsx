/**
 * Footer 사업자 정보 블록 테스트
 *
 * @description 푸터가 쇼핑몰 설정의 법정 사업자 항목을 값이 있는 것만 「라벨: 값」 줄로 그리고,
 *   값이 하나도 없거나 정보가 오지 않으면 블록을 그리지 않는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/footer-business-info.yaml
 */

import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Footer from '../Footer';

describe('Footer — 사업자 정보', () => {
  let originalG7Core: any;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      useResponsive: vi.fn(() => ({ width: 1440, isMobile: false, isTablet: false, isDesktop: true, matchedPreset: 'desktop' })),
      t: (k: string) => k,
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  // @scenario case=c01
  // @effects footer_shows_filled_business_fields_only, footer_business_phone_and_email_are_links
  it('값이 있는 항목만 라벨과 함께 그리고 전화·전자우편은 링크다', () => {
    const { container } = render(
      <Footer
        siteName="시험 상점"
        businessInfo={{
          company_name: '시험 상사',
          ceo_name: '홍길동',
          zipcode: '06236',
          base_address: '서울시 강남구 테헤란로 1',
          detail_address: '10층',
          phone: '02-123-4567',
          email: 'help@example.com',
          business_number: '123-45-67890',
          mail_order_number: '',
          hosting_provider: null as unknown as string,
        }}
      />,
    );

    const block = container.querySelector('[data-footer-business]')!;
    expect(block).toBeTruthy();
    const items = Array.from(block.querySelectorAll('[data-footer-business-item]')).map((el) => el.getAttribute('data-footer-business-item'));
    expect(items).toEqual(['company_name', 'ceo_name', 'address', 'phone', 'email', 'business_number']);

    expect(block.textContent).toContain('footer.business.company_name');
    expect(block.textContent).toContain('시험 상사');
    expect(block.textContent).toContain('(06236) 서울시 강남구 테헤란로 1 10층');
    expect(block.textContent).not.toContain('footer.business.mail_order_number');
    expect(block.textContent).not.toContain('footer.business.hosting_provider');

    expect(block.querySelector('a[href="tel:02-123-4567"]')?.textContent).toBe('02-123-4567');
    expect(block.querySelector('a[href="mailto:help@example.com"]')?.textContent).toBe('help@example.com');
  });

  // @scenario case=c02
  // @effects footer_hides_business_block_when_empty
  it('정보가 없거나 모든 값이 비면 블록을 그리지 않는다', () => {
    const none = render(<Footer siteName="시험 상점" />);
    expect(none.container.querySelector('[data-footer-business]')).toBeNull();
    none.unmount();

    const nulled = render(<Footer siteName="시험 상점" businessInfo={null} />);
    expect(nulled.container.querySelector('[data-footer-business]')).toBeNull();
    nulled.unmount();

    // 표시를 끄면 서버가 사업자 칸을 지우고 경로 설정만 남긴다
    const stripped = render(<Footer siteName="시험 상점" businessInfo={{ route_path: 'shop', show_business_footer: false } as any} />);
    expect(stripped.container.querySelector('[data-footer-business]')).toBeNull();
    stripped.unmount();

    const blank = render(<Footer siteName="시험 상점" businessInfo={{ company_name: '  ', phone: '' }} />);
    expect(blank.container.querySelector('[data-footer-business]')).toBeNull();
  });

  // @scenario case=c03
  // @effects footer_business_block_has_dark_pairs
  it('블록이 저작권 줄 위에 있고 글자색에 다크 모드 짝이 있다', () => {
    const { container } = render(<Footer siteName="시험 상점" businessInfo={{ company_name: '시험 상사' }} />);

    const block = container.querySelector('[data-footer-business]') as HTMLElement;
    expect(block.className).toMatch(/text-gray-\d+ dark:text-gray-\d+/);
    const copyright = Array.from(container.querySelectorAll('p')).find((p) => p.textContent?.includes('All rights reserved'))!;
    // DOCUMENT_POSITION_FOLLOWING(4): 저작권 줄이 블록 뒤에 온다
    expect(block.compareDocumentPosition(copyright) & 4).toBe(4);
  });
});
