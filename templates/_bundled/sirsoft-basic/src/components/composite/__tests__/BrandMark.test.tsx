/**
 * BrandMark 컴포넌트 테스트
 *
 * @description 결제 브랜드 마크(색 배지 / 인라인 SVG) 데이터 기반 렌더 + SVG 화이트리스트 sanitize 검증.
 * 과거 체크아웃 DOM 인젝터가 후처리로 주입하던 브랜드 마크를 카탈로그 데이터 기반 렌더로 통일한 것을 방어한다.
 *
 * @effects badge_renders_text_and_color, svg_renders_inline_logo, svg_dangerous_content_sanitized, none_form_falls_back_to_icon
 */
// @scenario mark_form=badge, requires_ios=false, device=non_ios
// @scenario mark_form=svg, requires_ios=false, device=non_ios
// @scenario mark_form=none, requires_ios=false, device=non_ios

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { BrandMark } from '../BrandMark';

describe('BrandMark', () => {
  describe('배지 렌더 (nhnkcp/nicepay 형태)', () => {
    it('text + class 로 색 배지를 렌더한다', () => {
      const { container } = render(
        <BrandMark brandMark={{ text: 'N', class: 'bg-green-500 text-white' }} />
      );

      const badge = container.querySelector('span');
      expect(badge).not.toBeNull();
      expect(badge!.textContent).toBe('N');
      expect(badge!.className).toContain('bg-green-500');
      expect(badge!.className).toContain('text-white');
      // 배지 골격 클래스(safelist 포함)
      expect(badge!.className).toContain('rounded-lg');
      expect(badge!.className).toContain('font-bold');
    });

    it('class 미지정 시 기본 색상으로 폴백한다', () => {
      const { container } = render(<BrandMark brandMark={{ text: 'X' }} />);
      const badge = container.querySelector('span');
      expect(badge!.className).toContain('bg-gray-500');
    });
  });

  describe('SVG 렌더 (kginicis 형태)', () => {
    it('brand_mark.svg 를 인라인 SVG 로 렌더한다', () => {
      const svg = '<svg viewBox="0 0 40 40"><rect fill="#03C75A"/><text>N</text></svg>';
      const { container } = render(<BrandMark brandMark={{ svg }} />);

      const svgEl = container.querySelector('svg');
      expect(svgEl).not.toBeNull();
      expect(container.querySelector('rect')?.getAttribute('fill')).toBe('#03C75A');
      expect(container.querySelector('text')?.textContent).toBe('N');
    });

    it('SVG 내부의 script / onload 등 위험 요소를 sanitize 로 제거한다', () => {
      const malicious =
        '<svg viewBox="0 0 40 40"><rect fill="#fff" onload="alert(1)"/><script>alert(2)</script><foreignObject><body/></foreignObject></svg>';
      const { container } = render(<BrandMark brandMark={{ svg: malicious }} />);

      // svg 자체는 렌더되지만 위험 요소는 제거되어야 한다.
      expect(container.querySelector('svg')).not.toBeNull();
      expect(container.querySelector('script')).toBeNull();
      expect(container.querySelector('foreignObject')).toBeNull();
      expect(container.querySelector('rect')?.getAttribute('onload')).toBeNull();
      // innerHTML 에도 script 문자열이 남지 않아야 한다.
      expect(container.innerHTML).not.toContain('<script');
      expect(container.innerHTML).not.toContain('onload');
    });

    it('svg 가 badge(text)보다 우선한다', () => {
      const { container } = render(
        <BrandMark brandMark={{ svg: '<svg><rect/></svg>', text: 'N', class: 'bg-green-500' }} />
      );
      expect(container.querySelector('svg')).not.toBeNull();
      // 배지 span 은 렌더되지 않음
      expect(container.querySelector('span')).toBeNull();
    });
  });

  describe('빈 값 처리', () => {
    it('brandMark 가 null 이면 아무것도 렌더하지 않는다 (아이콘 폴백 위임)', () => {
      const { container } = render(<BrandMark brandMark={null} />);
      expect(container.firstChild).toBeNull();
    });

    it('brandMark 가 빈 객체이면 아무것도 렌더하지 않는다', () => {
      const { container } = render(<BrandMark brandMark={{}} />);
      expect(container.firstChild).toBeNull();
    });
  });
});
