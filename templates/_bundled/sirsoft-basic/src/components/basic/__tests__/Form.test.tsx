/**
 * @file Form.test.tsx
 * @description Basic Form 컴포넌트 — `action` 은 브라우저가 라우터 없이 직접 제출하는 주소라
 *              하위 폴더 마운트의 접두사를 컴포넌트가 붙여야 한다 (`A` 의 `href` 와 같은 축).
 *
 * @scenario base_path=subpath, navigation_direction=request
 * @effects template_form_component_applies_base_prefix
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { Form } from '../Form';

/**
 * 두 경로(코어 위임 / `G7Config.basePath` 폴백)를 같은 케이스로 돌리고, 위임 경로에서는
 * 스파이가 실제로 불렸음을 함께 단언한다 — 값만 재면 위임 줄 삭제가 드러나지 않는다
 * (16차 G3 라운드 A9).
 */
describe.each([
  ['G7Core 없음 (G7Config.basePath 폴백)', false],
  ['G7Core 있음 (G7Core.url.api 위임)', true],
])('Form (Basic) — 하위 폴더 마운트의 action [%s]', (_label, withCore) => {
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

  const expectDelegated = (action: string) => {
    if (withCore) {
      expect(apiSpy).toHaveBeenCalledWith(action);
    } else {
      expect(apiSpy).not.toHaveBeenCalled();
    }
  };

  it('루트 상대 action 에 마운트 접두사를 붙인다', () => {
    // 접두사가 없으면 하위 폴더 사이트에서 제출이 사이트 밖으로 나간다 — 오류 없이
    // 다른 사이트의 응답(또는 404)만 남는 형태다.
    const { container } = render(<Form action="/api/x">F</Form>);
    expect(container.querySelector('form')).toHaveAttribute('action', '/g7/api/x');
    expectDelegated('/api/x');
  });

  it('이미 접두사를 가진 action · 절대 URL 은 그대로 둔다 (멱등)', () => {
    for (const value of ['/g7/api/x', 'https://pg.example.com/pay', '//pg.example.com/pay']) {
      const { container, unmount } = render(<Form action={value}>F</Form>);
      expect(container.querySelector('form')).toHaveAttribute('action', value);
      expectDelegated(value);
      unmount();
    }
  });

  it('action 이 없으면 속성을 만들지 않는다', () => {
    const { container } = render(<Form>F</Form>);
    expect(container.querySelector('form')).not.toHaveAttribute('action');
    expect(apiSpy).not.toHaveBeenCalled();
  });

  it('루트 설치에서는 action 을 바꾸지 않는다', () => {
    delete (globalThis as any).G7Config.basePath;
    apiSpy.mockImplementation((path: string) => path);
    const { container } = render(<Form action="/api/x">F</Form>);
    expect(container.querySelector('form')).toHaveAttribute('action', '/api/x');
    expectDelegated('/api/x');
  });
});
