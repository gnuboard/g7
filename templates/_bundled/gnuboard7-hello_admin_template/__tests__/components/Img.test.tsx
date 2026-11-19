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

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Img } from '../../src/components/basic/Img';

/**
 * 두 경로를 **같은 케이스로** 돌린다 — `sitePath()` 는 코어가 있으면 `G7Core.url.api` 에
 * 위임하고 없으면 `G7Config.basePath` 폴백을 탄다. 종전 테스트는 폴백만 돌려서, 위임 줄을
 * 지워도(코어가 뜬 실제 화면에서는 그 줄이 곧 결과다) 초록이었다.
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

  // @scenario base_path=subpath, navigation_direction=request
  // @effects template_img_component_applies_base_prefix
  it('루트 상대 src 에 마운트 접두사를 붙인다', () => {
    // 종전에는 `src` 를 그대로 흘려 하위 폴더 사이트에서 `/images/…` 가 사이트 밖을
    // 가리켰다 — 오류 없이 깨진 이미지만 남는 형태다.
    render(<Img data-testid="img" src="/images/logo.png" alt="" />);
    expect(screen.getByTestId('img')).toHaveAttribute('src', '/g7/images/logo.png');
    expectDelegated('/images/logo.png');
  });

  // @scenario base_path=subpath, navigation_direction=request
  // @effects template_img_component_applies_base_prefix
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

  // @scenario base_path=root, navigation_direction=request
  // @effects template_img_component_applies_base_prefix
  it('루트 설치에서는 src 를 바꾸지 않는다', () => {
    delete (globalThis as any).G7Config.basePath;
    apiSpy.mockImplementation((path: string) => path);
    render(<Img data-testid="img" src="/images/logo.png" alt="" />);
    expect(screen.getByTestId('img')).toHaveAttribute('src', '/images/logo.png');
    expectDelegated('/images/logo.png');
  });

  // @scenario base_path=subpath, navigation_direction=request
  // @effects template_img_component_applies_base_prefix
  it('src 가 없으면 속성을 만들지 않는다', () => {
    render(<Img data-testid="img" alt="빈 이미지" />);
    expect(screen.getByTestId('img')).not.toHaveAttribute('src');
    expect(screen.getByTestId('img')).toHaveAttribute('alt', '빈 이미지');
    expect(apiSpy).not.toHaveBeenCalled();
  });
});
