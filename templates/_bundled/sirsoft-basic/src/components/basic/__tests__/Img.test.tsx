// @scenario apply_type=propValue, consumer=img_src, storage_scope=template_layouts, stored_shape=string, widget_output=scalar_string
// @scenario apply_type=propValue, consumer=mobilenav_logo, storage_scope=template_layouts, stored_shape=absent, widget_output=image_object
/**
 * @file Img.test.tsx
 * @description Basic Img 컴포넌트 — `src` 는 브라우저가 라우터 없이 직접 여는 주소라
 *              하위 폴더 마운트의 접두사를 컴포넌트가 붙여야 한다 (`A` 의 `href` 와 같은 축).
 *
 * @scenario base_path=subpath, navigation_direction=request
 * @effects template_img_component_applies_base_prefix
 *
 * @vitest-environment jsdom
 */

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

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
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

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
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

  /** @effects img_image_value_object_resolves_to_its_url */
  it('5 부분 키(url 만) 객체도 채택', () => {
    const { container } = render(<Img src={{ url: '/a.png' } as never} />);
    expect(srcAttr(container)).toBe('/a.png');
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
  it('6 url 이 빈 문자열이면 미부착 — [object Object] 도 빈 문자열도 아니다', () => {
    const { container } = render(
      <Img src={{ url: '', size: 'cover', repeat: 'no-repeat', position: 'center' } as never} />,
    );
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
  it('7 url 키가 없는 객체는 미부착 (정상 props 와 정적 구분 불가 → 건드리지 않는다)', () => {
    const { container } = render(
      <Img src={{ size: 'cover', repeat: 'no-repeat', position: 'center' } as never} />,
    );
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
  it('8 url 이 비문자열이면 미부착', () => {
    const { container } = render(<Img src={{ url: { deep: 1 }, size: 'cover' } as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_object_with_key_outside_the_four_is_rejected_same_strictness_as_backfill */
  it('9 4키 밖 키가 섞이면 미부착 — 백필과 동일 엄격도', () => {
    const { container } = render(<Img src={{ url: '/a.png', label: 'x' } as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
  it('10 배열은 미부착', () => {
    const { container } = render(<Img src={['/a.png'] as never} />);
    expect(srcAttr(container)).toBeNull();
  });

  /** @effects img_corrupted_value_attaches_no_src_neither_object_object_nor_empty_string */
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

  /** @effects img_other_attributes_pass_through_spread_regression_zero */
  it('13 alt 기본값은 빈 문자열로 유지된다', () => {
    const { container } = render(<Img src="/a.png" />);
    expect(container.querySelector('img')!.getAttribute('alt')).toBe('');
  });
});

/**
 * 두 경로를 **같은 케이스로** 돌린다 — `sitePath()` 는 코어가 있으면 `G7Core.url.api` 에
 * 위임하고 없으면 `G7Config.basePath` 폴백을 탄다. 종전 테스트는 폴백만 돌려서, 위임 줄을
 * 지워도(코어가 뜬 실제 화면에서는 그 줄이 곧 결과다) 초록이었다 (16차 G3 라운드 A9).
 * 위임 경로에서는 스파이가 실제로 불렸음을 함께 단언한다 — 두 경로가 같은 값을 내므로
 * 값만 재면 위임 줄 삭제가 드러나지 않는다.
 */
describe.each([
  ['G7Core 없음 (G7Config.basePath 폴백)', false],
  ['G7Core 있음 (G7Core.url.api 위임)', true],
])('Img (Basic) — 하위 폴더 마운트의 src [%s]', (_label, withCore) => {
  const apiSpy = vi.fn((path: string) =>
    path.startsWith('/') && !path.startsWith('//') && !path.startsWith('/g7') ? `/g7${path}` : path,
  );

  beforeEach(() => {
    apiSpy.mockClear();
    (globalThis as any).G7Config = { ...(globalThis as any).G7Config, basePath: '/g7' };
    if (withCore) {
      (globalThis as any).G7Core = { url: { api: apiSpy } };
    } else {
      delete (globalThis as any).G7Core;
    }
  });

  afterEach(() => {
    delete (globalThis as any).G7Config.basePath;
    delete (globalThis as any).G7Core;
  });

  const expectDelegated = (src: string) => {
    if (withCore) {
      expect(apiSpy).toHaveBeenCalledWith(src);
    } else {
      expect(apiSpy).not.toHaveBeenCalled();
    }
  };

  it('루트 상대 src 에 마운트 접두사를 붙인다', () => {
    // 종전에는 `src` 를 그대로 흘려 하위 폴더 사이트에서 `/images/…` 가 사이트 밖을
    // 가리켰다 — 오류 없이 깨진 이미지만 남는 형태다.
    render(<Img data-testid="img" src="/images/logo.png" alt="" />);
    expect(screen.getByTestId('img')).toHaveAttribute('src', '/g7/images/logo.png');
    expectDelegated('/images/logo.png');
  });

  it('이미 접두사를 가진 src · 절대 URL · data: · 상대 경로는 그대로 둔다 (멱등)', () => {
    const cases: Array<[string, string]> = [
      ['/g7/images/logo.png', '/g7/images/logo.png'],
      ['https://cdn.example.com/a.png', 'https://cdn.example.com/a.png'],
      ['//cdn.example.com/a.png', '//cdn.example.com/a.png'],
      ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
      ['img.png', 'img.png'],
    ];
    for (const [input, expected] of cases) {
      const { unmount } = render(<Img data-testid="img" src={input} alt="" />);
      expect(screen.getByTestId('img')).toHaveAttribute('src', expected);
      expectDelegated(input);
      unmount();
    }
  });

  it('루트 설치에서는 src 를 바꾸지 않는다', () => {
    delete (globalThis as any).G7Config.basePath;
    apiSpy.mockImplementation((path: string) => path);
    render(<Img data-testid="img" src="/images/logo.png" alt="" />);
    expect(screen.getByTestId('img')).toHaveAttribute('src', '/images/logo.png');
    expectDelegated('/images/logo.png');
  });

  it('src 가 없으면 속성을 만들지 않는다', () => {
    render(<Img data-testid="img" alt="빈 이미지" />);
    expect(screen.getByTestId('img')).not.toHaveAttribute('src');
    expect(screen.getByTestId('img')).toHaveAttribute('alt', '빈 이미지');
    expect(apiSpy).not.toHaveBeenCalled();
  });
});
