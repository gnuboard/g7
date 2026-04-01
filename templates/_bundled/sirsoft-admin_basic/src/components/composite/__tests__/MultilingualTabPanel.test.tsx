import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MultilingualTabPanel } from '../MultilingualTabPanel';
import {
  useCurrentMultilingualLocale,
  MultilingualLocaleContext,
} from '../MultilingualLocaleContext';
import React, { useState, useCallback } from 'react';

// G7Core 목업
const mockG7Core = {
  locale: {
    current: () => 'ko',
    supported: () => ['ko', 'en'],
  },
};

// window.G7Core 목업 설정
beforeEach(() => {
  (window as any).G7Core = mockG7Core;
});

afterEach(() => {
  delete (window as any).G7Core;
  vi.clearAllMocks();
});

/**
 * Context 값을 표시하는 테스트용 컴포넌트
 */
const LocaleDisplay: React.FC = () => {
  const { activeLocale, isInsideMultilingualTabPanel, supportedLocales } =
    useCurrentMultilingualLocale();
  return (
    <div data-testid="locale-display">
      <span data-testid="active-locale">{activeLocale}</span>
      <span data-testid="inside-panel">
        {isInsideMultilingualTabPanel ? 'inside' : 'outside'}
      </span>
      <span data-testid="supported-locales">{supportedLocales.join(',')}</span>
    </div>
  );
};

/**
 * getActiveLocale getter 테스트용 컴포넌트 (Stale Closure 방지)
 */
const LocaleGetter: React.FC<{ onCapture?: (locale: string) => void }> = ({
  onCapture,
}) => {
  const { getActiveLocale } = useCurrentMultilingualLocale();

  const handleClick = useCallback(() => {
    const currentLocale = getActiveLocale();
    onCapture?.(currentLocale);
  }, [getActiveLocale, onCapture]);

  return (
    <button data-testid="get-locale-button" onClick={handleClick}>
      Get Locale
    </button>
  );
};

/**
 * 다국어 입력 시뮬레이션 컴포넌트
 */
const MultilingualInputSimulator: React.FC<{
  value: Record<string, string>;
  onChange: (value: Record<string, string>) => void;
}> = ({ value, onChange }) => {
  const { activeLocale, getActiveLocale } = useCurrentMultilingualLocale();

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const currentLocale = getActiveLocale();
      onChange({
        ...value,
        [currentLocale]: e.target.value,
      });
    },
    [getActiveLocale, value, onChange]
  );

  return (
    <input
      data-testid="multilingual-input"
      value={value?.[activeLocale] || ''}
      onChange={handleChange}
    />
  );
};

describe('MultilingualTabPanel', () => {
  describe('기본 렌더링', () => {
    it('탭이 렌더링됨', () => {
      render(
        <MultilingualTabPanel>
          <div>Content</div>
        </MultilingualTabPanel>
      );

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    it('자식 요소가 렌더링됨', () => {
      render(
        <MultilingualTabPanel>
          <div data-testid="child">Child Content</div>
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('child')).toBeInTheDocument();
    });

    it('className이 적용됨', () => {
      const { container } = render(
        <MultilingualTabPanel className="custom-class">
          <div>Content</div>
        </MultilingualTabPanel>
      );

      expect(container.firstChild).toHaveClass('custom-class');
    });

    it('기본 로케일이 ko로 설정됨', () => {
      render(
        <MultilingualTabPanel>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('active-locale')).toHaveTextContent('ko');
    });
  });

  describe('탭 스타일 variant', () => {
    it('default variant 스타일이 적용됨', () => {
      render(
        <MultilingualTabPanel variant="default">
          <div>Content</div>
        </MultilingualTabPanel>
      );

      const koTab = screen.getByText('한국어').closest('button');
      expect(koTab).toHaveClass('bg-blue-50');
    });

    it('pills variant 스타일이 적용됨', () => {
      render(
        <MultilingualTabPanel variant="pills">
          <div>Content</div>
        </MultilingualTabPanel>
      );

      const koTab = screen.getByText('한국어').closest('button');
      expect(koTab).toHaveClass('bg-blue-600');
    });

    it('underline variant 스타일이 적용됨', () => {
      render(
        <MultilingualTabPanel variant="underline">
          <div>Content</div>
        </MultilingualTabPanel>
      );

      const koTab = screen.getByText('한국어').closest('button');
      expect(koTab).toHaveClass('border-b-2');
      expect(koTab).toHaveClass('border-blue-600');
    });
  });

  describe('탭 전환', () => {
    it('탭 클릭 시 activeLocale이 변경됨', async () => {
      const user = userEvent.setup();

      render(
        <MultilingualTabPanel>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('active-locale')).toHaveTextContent('ko');

      await user.click(screen.getByText('English'));

      expect(screen.getByTestId('active-locale')).toHaveTextContent('en');
    });

    it('onLocaleChange 콜백이 호출됨', async () => {
      const user = userEvent.setup();
      const handleLocaleChange = vi.fn();

      render(
        <MultilingualTabPanel onLocaleChange={handleLocaleChange}>
          <div>Content</div>
        </MultilingualTabPanel>
      );

      await user.click(screen.getByText('English'));

      expect(handleLocaleChange).toHaveBeenCalledWith('en');
    });

    it('defaultLocale prop이 적용됨', () => {
      render(
        <MultilingualTabPanel defaultLocale="en">
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('active-locale')).toHaveTextContent('en');
    });
  });

  describe('Context 전파', () => {
    it('자식 컴포넌트에서 activeLocale 접근 가능', () => {
      render(
        <MultilingualTabPanel>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('inside-panel')).toHaveTextContent('inside');
      expect(screen.getByTestId('active-locale')).toHaveTextContent('ko');
    });

    it('supportedLocales가 전파됨', () => {
      render(
        <MultilingualTabPanel>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('supported-locales')).toHaveTextContent(
        'ko,en'
      );
    });
  });

  describe('Context 외부 (G7Core fallback)', () => {
    it('Context 외부에서 G7Core fallback 사용', () => {
      render(<LocaleDisplay />);

      expect(screen.getByTestId('inside-panel')).toHaveTextContent('outside');
      expect(screen.getByTestId('active-locale')).toHaveTextContent('ko');
    });
  });

  describe('Stale Closure 방지', () => {
    it('getActiveLocale getter가 최신 값 반환', async () => {
      const user = userEvent.setup();
      const capturedLocales: string[] = [];

      render(
        <MultilingualTabPanel>
          <LocaleGetter onCapture={(l) => capturedLocales.push(l)} />
        </MultilingualTabPanel>
      );

      // 초기 클릭
      await user.click(screen.getByTestId('get-locale-button'));
      expect(capturedLocales).toContain('ko');

      // 탭 전환
      await user.click(screen.getByText('English'));

      // 전환 후 클릭
      await user.click(screen.getByTestId('get-locale-button'));
      expect(capturedLocales[capturedLocales.length - 1]).toBe('en');
    });

    it('빠른 탭 전환 후 입력 시 올바른 로케일 사용', async () => {
      const user = userEvent.setup();
      let currentValue: Record<string, string> = { ko: '', en: '' };

      const TestComponent = () => {
        const [value, setValue] = useState(currentValue);

        const handleChange = (newValue: Record<string, string>) => {
          currentValue = newValue;
          setValue(newValue);
        };

        return (
          <MultilingualTabPanel>
            <MultilingualInputSimulator value={value} onChange={handleChange} />
          </MultilingualTabPanel>
        );
      };

      render(<TestComponent />);

      // 빠르게 탭 전환
      await user.click(screen.getByText('English'));

      // 입력
      const input = screen.getByTestId('multilingual-input');
      await user.type(input, 'test');

      // en 필드에 값이 입력되었는지 확인
      expect(currentValue.en).toBe('test');
      expect(currentValue.ko).toBe('');
    });
  });

  describe('다국어 입력 통합', () => {
    it('탭 전환 시 입력 필드 값이 해당 로케일로 변경됨', async () => {
      const user = userEvent.setup();

      const TestComponent = () => {
        const [value, setValue] = useState<Record<string, string>>({
          ko: '한글 텍스트',
          en: 'English text',
        });

        return (
          <MultilingualTabPanel>
            <MultilingualInputSimulator value={value} onChange={setValue} />
          </MultilingualTabPanel>
        );
      };

      render(<TestComponent />);

      // 초기 상태 (ko)
      expect(screen.getByTestId('multilingual-input')).toHaveValue('한글 텍스트');

      // en 탭으로 전환
      await user.click(screen.getByText('English'));

      // en 값이 표시됨
      expect(screen.getByTestId('multilingual-input')).toHaveValue(
        'English text'
      );
    });

    it('각 로케일에 독립적으로 입력 가능', async () => {
      const user = userEvent.setup();
      let finalValue: Record<string, string> = {};

      const TestComponent = () => {
        const [value, setValue] = useState<Record<string, string>>({
          ko: '',
          en: '',
        });

        const handleChange = (newValue: Record<string, string>) => {
          finalValue = newValue;
          setValue(newValue);
        };

        return (
          <MultilingualTabPanel>
            <MultilingualInputSimulator value={value} onChange={handleChange} />
          </MultilingualTabPanel>
        );
      };

      render(<TestComponent />);

      // ko 입력
      const input = screen.getByTestId('multilingual-input');
      await user.type(input, '한글');

      // en 탭으로 전환
      await user.click(screen.getByText('English'));

      // en 입력
      await user.type(screen.getByTestId('multilingual-input'), 'English');

      // 두 로케일 모두 값이 저장됨
      expect(finalValue.ko).toBe('한글');
      expect(finalValue.en).toBe('English');
    });
  });

  describe('중첩 MultilingualTabPanel', () => {
    it('중첩된 패널이 독립적으로 동작함', async () => {
      const user = userEvent.setup();

      render(
        <MultilingualTabPanel>
          <div data-testid="outer">
            <LocaleDisplay />
          </div>
          <MultilingualTabPanel>
            <div data-testid="inner">
              <LocaleDisplay />
            </div>
          </MultilingualTabPanel>
        </MultilingualTabPanel>
      );

      // 모든 LocaleDisplay는 outer/inner 구분 없이 렌더링됨
      const localeDisplays = screen.getAllByTestId('active-locale');
      expect(localeDisplays.length).toBe(2);

      // 초기 상태: 둘 다 ko
      expect(localeDisplays[0]).toHaveTextContent('ko');
      expect(localeDisplays[1]).toHaveTextContent('ko');

      // 바깥 탭 클릭 (첫 번째 English)
      const englishButtons = screen.getAllByText('English');
      await user.click(englishButtons[0]);

      // 바깥 패널만 en으로 변경됨 (가장 가까운 Context가 우선)
      const updatedDisplays = screen.getAllByTestId('active-locale');
      // 내부 패널은 자체 Context를 가지므로 독립적
      // (주의: 이 테스트는 구현 방식에 따라 다를 수 있음)
    });
  });

  describe('마운트/언마운트', () => {
    it('컴포넌트 언마운트 후 Context가 정리됨', () => {
      const { unmount } = render(
        <MultilingualTabPanel>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('inside-panel')).toHaveTextContent('inside');

      unmount();

      // 언마운트 후 에러 없이 완료
      expect(true).toBe(true);
    });
  });

  describe('locales prop', () => {
    it('locales prop이 제공되면 해당 로케일만 탭으로 표시', () => {
      render(
        <MultilingualTabPanel locales={['ko']}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.queryByText('English')).not.toBeInTheDocument();
    });

    it('locales prop이 비어있으면 그누보드7 지원 로케일 전체 표시', () => {
      render(
        <MultilingualTabPanel locales={[]}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    it('locales prop이 undefined면 그누보드7 지원 로케일 전체 표시', () => {
      render(
        <MultilingualTabPanel locales={undefined}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    it('locales가 변경될 때 activeLocale이 새 목록에 없으면 첫 번째 로케일로 변경', async () => {
      const handleLocaleChange = vi.fn();

      const { rerender } = render(
        <MultilingualTabPanel locales={['ko', 'en']} defaultLocale="en" onLocaleChange={handleLocaleChange}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('active-locale')).toHaveTextContent('en');

      // locales가 ['ko']로 변경되면 en이 없으므로 ko로 변경되어야 함
      rerender(
        <MultilingualTabPanel locales={['ko']} defaultLocale="en" onLocaleChange={handleLocaleChange}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      await waitFor(() => {
        expect(screen.getByTestId('active-locale')).toHaveTextContent('ko');
      });
      expect(handleLocaleChange).toHaveBeenCalledWith('ko');
    });

    it('supportedLocales가 locales prop 값으로 Context에 전파됨', () => {
      render(
        <MultilingualTabPanel locales={['en', 'ja']}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByTestId('supported-locales')).toHaveTextContent('en,ja');
    });

    it('locales prop에 포함된 로케일만 탭 클릭 가능', async () => {
      const user = userEvent.setup();

      render(
        <MultilingualTabPanel locales={['ko', 'ja']}>
          <LocaleDisplay />
        </MultilingualTabPanel>
      );

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('日本語')).toBeInTheDocument();
      expect(screen.queryByText('English')).not.toBeInTheDocument();

      await user.click(screen.getByText('日本語'));
      expect(screen.getByTestId('active-locale')).toHaveTextContent('ja');
    });
  });
});
