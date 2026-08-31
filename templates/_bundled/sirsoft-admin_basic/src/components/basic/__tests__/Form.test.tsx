import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Form } from '../Form';

describe('Form 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('form 요소가 렌더링된다', () => {
      const { container } = render(<Form>Form Content</Form>);
      const form = container.querySelector('form');
      expect(form).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(<Form>Test Form Content</Form>);
      expect(screen.getByText('Test Form Content')).toBeTruthy();
    });
  });

  describe('이벤트 핸들링', () => {
    it('onSubmit 핸들러가 호출된다', () => {
      const handleSubmit = vi.fn((e) => e.preventDefault());
      const { container } = render(<Form onSubmit={handleSubmit}>Submit Form</Form>);
      const form = container.querySelector('form')!;

      fireEvent.submit(form);

      expect(handleSubmit).toHaveBeenCalledTimes(1);
    });

    it('onReset 핸들러가 호출된다', () => {
      const handleReset = vi.fn();
      const { container } = render(<Form onReset={handleReset}>Reset Form</Form>);
      const form = container.querySelector('form')!;

      fireEvent.reset(form);

      expect(handleReset).toHaveBeenCalledTimes(1);
    });

    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Form onChange={handleChange}>
          <input type="text" name="test" />
        </Form>
      );
      const input = container.querySelector('input')!;

      fireEvent.change(input, { target: { value: 'test value' } });

      expect(handleChange).toHaveBeenCalled();
    });
  });

  describe('HTML 속성', () => {
    it('action 속성이 적용된다', () => {
      const { container } = render(<Form action="/submit">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.action).toContain('/submit');
    });

    it('method 속성이 적용된다', () => {
      const { container } = render(<Form method="post">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.method).toBe('post');
    });

    it('encType 속성이 적용된다', () => {
      const { container } = render(<Form encType="multipart/form-data">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.enctype).toBe('multipart/form-data');
    });

    it('target 속성이 적용된다', () => {
      const { container } = render(<Form target="_blank">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.target).toBe('_blank');
    });

    it('autoComplete 속성이 적용된다', () => {
      const { container } = render(<Form autoComplete="off">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.getAttribute('autocomplete')).toBe('off');
    });

    it('noValidate 속성이 적용된다', () => {
      const { container } = render(<Form noValidate>Form</Form>);
      const form = container.querySelector('form');
      expect(form?.noValidate).toBe(true);
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Form className="custom-form">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.className).toContain('custom-form');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<Form id="login-form">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.id).toBe('login-form');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Form data-testid="test-form">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.getAttribute('data-testid')).toBe('test-form');
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(<Form name="registration">Form</Form>);
      const form = container.querySelector('form');
      expect(form?.name).toBe('registration');
    });
  });

  describe('복잡한 Form 구조', () => {
    it('여러 input 요소를 포함한다', () => {
      const { container } = render(
        <Form>
          <input type="text" name="username" placeholder="사용자명" />
          <input type="password" name="password" placeholder="비밀번호" />
          <button type="submit">제출</button>
        </Form>
      );

      expect(screen.getByPlaceholderText('사용자명')).toBeTruthy();
      expect(screen.getByPlaceholderText('비밀번호')).toBeTruthy();
      expect(screen.getByText('제출')).toBeTruthy();
    });

    it('중첩된 div와 label을 포함한다', () => {
      render(
        <Form>
          <div>
            <label htmlFor="email">이메일</label>
            <input type="email" id="email" name="email" />
          </div>
        </Form>
      );

      expect(screen.getByLabelText('이메일')).toBeTruthy();
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleSubmit = vi.fn((e) => e.preventDefault());
      const { container } = render(
        <Form
          method="post"
          action="/api/submit"
          onSubmit={handleSubmit}
          className="custom-form"
          autoComplete="off"
          noValidate
        >
          <input type="text" name="field" />
          <button type="submit">제출</button>
        </Form>
      );

      const form = container.querySelector('form')!;
      expect(form.method).toBe('post');
      expect(form.action).toContain('/api/submit');
      expect(form.className).toContain('custom-form');
      expect(form.getAttribute('autocomplete')).toBe('off');
      expect(form.noValidate).toBe(true);

      fireEvent.submit(form);
      expect(handleSubmit).toHaveBeenCalledTimes(1);
    });
  });
});

/**
 * 두 경로(코어 위임 / `G7Config.basePath` 폴백)를 같은 케이스로 돌리고, 위임 경로에서는
 * 스파이가 실제로 불렸음을 함께 단언한다 — 값만 재면 위임 줄 삭제가 드러나지 않는다
 * (16차 G3 라운드 A9).
 */
// @scenario base_path=subpath, navigation_direction=request
// @effects template_form_component_applies_base_prefix
describe.each([
  ['G7Core 없음 (G7Config.basePath 폴백)', false],
  ['G7Core 있음 (G7Core.url.api 위임)', true],
])('Form 컴포넌트 — 하위 폴더 마운트의 action [%s]', (_label, withCore) => {
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
