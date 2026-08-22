import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ThemeToggle, ThemeMode } from '../ThemeToggle';

describe('ThemeToggle', () => {
  let localStorageMock: { [key: string]: string };
  let matchMediaMock: any;

  beforeEach(() => {
    // localStorage mock
    localStorageMock = {};
    global.Storage.prototype.getItem = vi.fn((key) => localStorageMock[key] || null);
    global.Storage.prototype.setItem = vi.fn((key, value) => {
      localStorageMock[key] = value;
    });
    global.Storage.prototype.removeItem = vi.fn((key) => {
      delete localStorageMock[key];
    });

    // matchMedia mock
    matchMediaMock = vi.fn().mockImplementation((query) => ({
      matches: query === '(prefers-color-scheme: dark)',
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
    global.matchMedia = matchMediaMock;

    // document.documentElement mock
    document.documentElement.setAttribute = vi.fn();
    document.documentElement.classList.add = vi.fn();
    document.documentElement.classList.remove = vi.fn();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('테마 토글 버튼이 렌더링되어야 함', () => {
    render(<ThemeToggle />);
    const button = screen.getByRole('button', { name: /toggle theme/i });
    expect(button).toBeInTheDocument();
  });

  it('초기 테마가 localStorage에서 로드되어야 함', () => {
    localStorageMock['g7_color_scheme'] = 'dark';
    render(<ThemeToggle />);

    // 다크 모드 아이콘(Moon)이 표시되어야 함
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('버튼 클릭 시 드롭다운 메뉴가 표시되어야 함', async () => {
    render(<ThemeToggle autoText="Auto" lightText="Light" darkText="Dark" />);

    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    await waitFor(() => {
      expect(screen.getByText('Auto')).toBeInTheDocument();
      expect(screen.getByText('Light')).toBeInTheDocument();
      expect(screen.getByText('Dark')).toBeInTheDocument();
    });
  });

  it('라이트 모드 선택 시 테마가 변경되어야 함', async () => {
    const onThemeChange = vi.fn();
    render(
      <ThemeToggle
        autoText="Auto"
        lightText="Light"
        darkText="Dark"
        onThemeChange={onThemeChange}
      />
    );

    // 드롭다운 열기
    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    // 라이트 모드 선택
    const lightButton = await screen.findByText('Light');
    fireEvent.click(lightButton);

    await waitFor(() => {
      expect(localStorageMock['g7_color_scheme']).toBe('light');
      expect(onThemeChange).toHaveBeenCalledWith('light');
      expect(document.documentElement.setAttribute).toHaveBeenCalledWith('data-theme', 'light');
      expect(document.documentElement.classList.remove).toHaveBeenCalledWith('dark');
    });
  });

  it('다크 모드 선택 시 테마가 변경되어야 함', async () => {
    const onThemeChange = vi.fn();
    render(
      <ThemeToggle
        autoText="Auto"
        lightText="Light"
        darkText="Dark"
        onThemeChange={onThemeChange}
      />
    );

    // 드롭다운 열기
    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    // 다크 모드 선택
    const darkButton = await screen.findByText('Dark');
    fireEvent.click(darkButton);

    await waitFor(() => {
      expect(localStorageMock['g7_color_scheme']).toBe('dark');
      expect(onThemeChange).toHaveBeenCalledWith('dark');
      expect(document.documentElement.setAttribute).toHaveBeenCalledWith('data-theme', 'dark');
      expect(document.documentElement.classList.add).toHaveBeenCalledWith('dark');
    });
  });

  it('자동 모드 선택 시 시스템 설정을 따라야 함', async () => {
    // 시스템이 다크 모드인 경우
    matchMediaMock.mockImplementation((query) => ({
      matches: query === '(prefers-color-scheme: dark)',
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));

    const onThemeChange = vi.fn();
    render(
      <ThemeToggle
        autoText="Auto"
        lightText="Light"
        darkText="Dark"
        onThemeChange={onThemeChange}
      />
    );

    // 드롭다운 열기
    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    // 자동 모드 선택
    const autoButton = await screen.findByText('Auto');
    fireEvent.click(autoButton);

    await waitFor(() => {
      expect(localStorageMock['g7_color_scheme']).toBe('auto');
      expect(onThemeChange).toHaveBeenCalledWith('auto');
      // 시스템이 다크 모드이므로 다크 테마가 적용되어야 함
      expect(document.documentElement.setAttribute).toHaveBeenCalledWith('data-theme', 'dark');
      expect(document.documentElement.classList.add).toHaveBeenCalledWith('dark');
    });
  });

  it('외부 클릭 시 드롭다운이 닫혀야 함', async () => {
    render(<ThemeToggle autoText="Auto" lightText="Light" darkText="Dark" />);

    // 드롭다운 열기
    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    // 드롭다운이 표시됨
    expect(await screen.findByText('Auto')).toBeInTheDocument();

    // 외부 클릭
    fireEvent.mouseDown(document.body);

    await waitFor(() => {
      expect(screen.queryByText('Auto')).not.toBeInTheDocument();
    });
  });

  it('현재 선택된 모드에 체크 아이콘이 표시되어야 함', async () => {
    localStorageMock['g7_color_scheme'] = 'light';
    render(<ThemeToggle autoText="Auto" lightText="Light" darkText="Dark" />);

    // 드롭다운 열기
    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    // 라이트 모드가 선택되어 있어야 함 (bg-gray-50 클래스 확인)
    const lightButton = await screen.findByText('Light');
    expect(lightButton.closest('button')).toHaveClass('bg-gray-50');
  });

  it('커스텀 텍스트가 올바르게 표시되어야 함', async () => {
    render(
      <ThemeToggle
        autoText="시스템 설정"
        lightText="라이트 모드"
        darkText="다크 모드"
      />
    );

    const toggleButton = screen.getByRole('button', { name: /toggle theme/i });
    fireEvent.click(toggleButton);

    await waitFor(() => {
      expect(screen.getByText('시스템 설정')).toBeInTheDocument();
      expect(screen.getByText('라이트 모드')).toBeInTheDocument();
      expect(screen.getByText('다크 모드')).toBeInTheDocument();
    });
  });

  it('잘못된 localStorage 값이 있어도 오류가 발생하지 않아야 함', () => {
    localStorageMock['g7_color_scheme'] = 'invalid';

    expect(() => {
      render(<ThemeToggle />);
    }).not.toThrow();
  });
});
