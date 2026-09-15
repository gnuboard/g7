// @scenario apply_type=propValue, consumer=img_src, storage_scope=template_layouts, stored_shape=string, widget_output=scalar_string
// @scenario apply_type=propValue, consumer=mobilenav_logo, storage_scope=template_layouts, stored_shape=absent, widget_output=image_object
/**
 * Img.test.tsx — `src` 값 형태 방어 (공개 #135 런타임 방어선)
 *
 * 레이아웃 편집기의 `image` 위젯은 `{url,size,repeat,position}` 객체를 내보내는데,
 * 그 값이 `propValue` 로 컴포넌트 prop 에 그대로 기록되던 시기가 있었다. 그렇게 저장된
 * 레이아웃은 `<Img src={객체}>` 가 되어 브라우저가 `[object Object]` 를 URL 로 해석했고,
 * SPA catch-all 때문에 404 조차 아니라 200(HTML)을 받아 **엑박만 남고 아무 오류도
 * 남지 않았다.**
 *
 * 편집기·백필이 고쳐졌어도 업그레이드 전 화면은 그대로이므로 이 방어가 필요하다.
 * 판정 강도는 백필 마이그레이션과 **완전히 같다**(키 집합 ⊆ 4키 AND url 문자열) —
 * 두 방어선의 기준이 어긋나면 한쪽만 통과하는 값이 생긴다.
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import React from 'react';
import { Img } from '../Img';

/** 렌더된 <img> 의 src 속성 원문(미부착이면 null) */
function srcAttr(container: HTMLElement): string | null {
  const img = container.querySelector('img');
  expect(img).toBeTruthy();
  return img!.getAttribute('src');
}

describe('Img — src 값 형태 방어', () => {
  /** @effects img_string_src_passes_through_unchanged */
  it('1 정상 문자열은 그대로 통과한다 (회귀 0)', () => {
    const { container } = render(<Img src="/img/logo.png" />);
    expect(srcAttr(container)).toBe('/img/logo.png');
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
  it('2 빈 문자열은 src 를 붙이지 않는다 (현재 문서 재요청 차단)', () => {
    const { container } = render(<Img src="" />);
    expect(srcAttr(container)).toBeNull();
  });

  it('3 src 미전달도 예외 없이 미부착', () => {
    const { container } = render(<Img alt="x" />);
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_image_value_object_resolves_to_its_url */
  it('4 이미지 값 객체(4키 전부) → url 만 채택', () => {
    const value = {
      url: '/api/attachment/X',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    };
    const { container } = render(<Img src={value as never} />);
    expect(srcAttr(container)).toBe('/api/attachment/X');
  });

  it('5 부분 키(url 만) 객체도 채택', () => {
    const { container } = render(<Img src={{ url: '/a.png' } as never} />);
    expect(srcAttr(container)).toBe('/a.png');
  });

  it('6 url 이 빈 문자열이면 미부착 — [object Object] 도 빈 문자열도 아니다', () => {
    const { container } = render(
      <Img src={{ url: '', size: 'cover', repeat: 'no-repeat', position: 'center' } as never} />,
    );
    expect(srcAttr(container)).toBeNull();
  });

  it('7 url 키가 없는 객체는 미부착 (정상 props 와 정적 구분 불가 → 건드리지 않는다)', () => {
    const { container } = render(
      <Img src={{ size: 'cover', repeat: 'no-repeat', position: 'center' } as never} />,
    );
    expect(srcAttr(container)).toBeNull();
  });

  it('8 url 이 비문자열이면 미부착', () => {
    const { container } = render(<Img src={{ url: { deep: 1 }, size: 'cover' } as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_object_with_key_outside_the_four_is_rejected_same_strictness_as_backfill */
  it('9 4키 밖 키가 섞이면 미부착 — 백필과 동일 엄격도', () => {
    const { container } = render(<Img src={{ url: '/a.png', label: 'x' } as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  it('10 배열은 미부착', () => {
    const { container } = render(<Img src={['/a.png'] as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  it('11 숫자·불리언은 예외 없이 미부착', () => {
    expect(srcAttr(render(<Img src={42 as never} />).container)).toBeNull();
    expect(srcAttr(render(<Img src={true as never} />).container)).toBeNull();
  });

  /** @effects img_other_attributes_pass_through_spread_regression_zero */
  it('12 다른 속성은 그대로 통과한다 (스프레드 회귀 0)', () => {
    const { container } = render(
      <Img src="/a.png" alt="로고" className="h-8" loading="lazy" width={32} />,
    );
    const img = container.querySelector('img')!;
    expect(img.getAttribute('src')).toBe('/a.png');
    expect(img.getAttribute('alt')).toBe('로고');
    expect(img.getAttribute('class')).toBe('h-8');
    expect(img.getAttribute('loading')).toBe('lazy');
    expect(img.getAttribute('width')).toBe('32');
  });

  it('13 alt 기본값은 빈 문자열로 유지된다', () => {
    const { container } = render(<Img src="/a.png" />);
    expect(container.querySelector('img')!.getAttribute('alt')).toBe('');
  });
});
