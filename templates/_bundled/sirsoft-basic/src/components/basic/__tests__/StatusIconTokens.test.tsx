/**
 * @file StatusIconTokens.test.tsx
 * @description 상태 의미 토큰(`status-*`) → 글리프 매핑 계약
 *
 * 백엔드(클레임·주문 상태 Enum)는 아이콘 라이브러리를 알지 않고 라이브러리 중립 토큰만 내보낸다.
 * 그 토큰을 이 템플릿의 글리프로 바꾸는 표는 `iconNameMap` 한 곳이다 — 어휘에 없는 토큰은
 * `fa-status-*` 로 떨어져 오류 없이 빈 네모가 그려지므로, 어휘 21종 전건이 실제 글리프
 * 클래스로 해석되는지를 여기서 잠근다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';
import { iconNameMap } from '../IconTypes';

/** 서버 상태 Enum 이 내보내는 토큰 어휘 (docs/api/admin-claims.md "아이콘 의미 토큰 어휘") */
const STATUS_TOKENS: Record<string, string> = {
  'status-request': 'fa-file-lines',
  'status-payment-pending': 'fa-credit-card',
  'status-paid': 'fa-money-bill',
  'status-approved': 'fa-thumbs-up',
  'status-on-hold': 'fa-pause',
  'status-rejected': 'fa-ban',
  'status-pickup-ready': 'fa-box-open',
  'status-pickup': 'fa-truck',
  'status-received': 'fa-warehouse',
  'status-dispatch-ready': 'fa-box',
  'status-dispatch': 'fa-truck-fast',
  'status-delivered': 'fa-house',
  'status-completed': 'fa-circle-check',
  'status-withdrawn': 'fa-rotate-left',
  'status-processing': 'fa-hourglass-half',
  'status-preparing': 'fa-boxes-packing',
  'status-cancelled': 'fa-circle-xmark',
  'status-exchange': 'fa-right-left',
  'status-return': 'fa-reply',
  'status-exchanged': 'fa-arrows-rotate',
  'status-returned': 'fa-box-archive',
};

describe('상태 의미 토큰 → 글리프 매핑', () => {
  it('어휘 21종 전건이 iconNameMap 에 있다', () => {
    for (const token of Object.keys(STATUS_TOKENS)) {
      expect(iconNameMap[token], token).toBeDefined();
    }
  });

  it.each(Object.entries(STATUS_TOKENS))('%s 는 %s 글리프로 그려진다', (token, glyph) => {
    const { container } = render(<Icon name={token} />);
    const icon = container.querySelector('i');

    expect(icon?.className).toContain(glyph);
    expect(icon?.className).not.toContain('fa-status-');
  });
});
