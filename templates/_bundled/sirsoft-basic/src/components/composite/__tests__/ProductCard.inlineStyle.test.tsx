// e2e:allow 검색 하이라이트 정화가 서식 지연 장치를 거치는 연결만 잠근다 — 정책을 강제한 브라우저에서의 위반 0건은 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 상품 검색)이 덮는다
/**
 * ProductCard — 검색 하이라이트 정화가 서식 지연 장치를 거친다 (공개 #163, 엄격한 스타일 정책 호환)
 *
 * 회귀 배경: 하이라이트 정화는 `mark` 태그만 남겨 결과에 style 속성이 없지만, 원문(상품 요약 설명)에 style 속성이 있으면
 * 정화기가 문자열을 파싱하는 것만으로 `style-src-attr 'none'` 위반이 보고된다. 코어 장치(`G7Core.inlineStyle`)가 있으면
 * 그 장치로 정화한다(설정이 style 을 금지하므로 서식은 버려진다). 장치가 없는 옛 코어에서는 종전 그대로다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/content-inline-style-deferral.yaml
 */
import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ProductCard from '../ProductCard';
import { DEFERRED_ATTR, installInlineStyleMock } from '../../../../__tests__/helpers/inlineStyleMock';

const product = {
  id: 7,
  name_localized: '면 손수건',
  name_highlighted: '면 <mark style="color: red">손수건</mark>',
  short_description: '<p style="color: red">부드러운 <mark>면</mark></p>',
  thumbnail_url: '/img/handkerchief.jpg',
  selling_price: 3000,
  selling_price_formatted: '3,000원',
};

afterEach(() => {
  delete (window as any).G7Core;
});

describe('ProductCard 검색 하이라이트 정화', () => {
  /**
   * @scenario surface=product_card_highlight, core=with_inline_style
   * @effects forbid_config_passed_through, style_attribute_deferred
   */
  it('장치가 있으면 그 장치로 정화하고 서식은 남기지 않는다', () => {
    const mock = installInlineStyleMock({ t: (key: string) => key });
    const { container } = render(<ProductCard product={product as any} />);

    expect(mock.sanitize).toHaveBeenCalled();
    for (const call of mock.sanitize.mock.calls) {
      expect(call[2]).toEqual({ ALLOWED_TAGS: ['mark'], ALLOWED_ATTR: [] });
    }
    expect(container.innerHTML).toContain('<mark>손수건</mark>');
    expect(container.querySelector('mark[style]')).toBeNull();
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
    expect(container.querySelector('p[style]')).toBeNull();
  });

  /**
   * @scenario surface=product_card_highlight, core=legacy
   * @effects legacy_core_unchanged
   */
  it('장치가 없으면(옛 코어) 종전 그대로 mark 만 남긴다', () => {
    (window as any).G7Core = { t: (key: string) => key };
    const { container } = render(<ProductCard product={product as any} />);

    expect(container.innerHTML).toContain('<mark>손수건</mark>');
    expect(container.querySelector('mark[style]')).toBeNull();
  });
});
