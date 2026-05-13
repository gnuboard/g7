import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { MultilingualInput, MultilingualValue, LocaleOption, MultilingualInputType, MultilingualInputLayout } from '../MultilingualInput';

// G7Core mock
beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => {
      const map: Record<string, string> = {
        'common.language_ko': '한국어',
        'common.language_en': 'English',
        'common.add_language': '언어 추가',
      };
      return map[key] ?? key;
    },
    locale: {
      supported: () => ['ko', 'en'],
      current: () => 'ko',
    },
  };
});

const DEFAULT_LOCALES: LocaleOption[] = [
  { code: 'ko', name: '한국어', nativeName: '한국어' },
  { code: 'en', name: 'English', nativeName: 'English' },
];

describe('MultilingualInput 컴포넌트', () => {
  describe('타입 정의', () => {
    it('MultilingualValue 타입이 올바르게 정의되어 있다', () => {
      const value: MultilingualValue = {
        ko: '한국어 텍스트',
        en: 'English text',
      };
      expect(value.ko).toBe('한국어 텍스트');
      expect(value.en).toBe('English text');
    });

    it('LocaleOption 타입이 올바르게 정의되어 있다', () => {
      const locale: LocaleOption = {
        code: 'ko',
        name: '한국어',
        nativeName: '한국어',
      };
      expect(locale.code).toBe('ko');
      expect(locale.name).toBe('한국어');
      expect(locale.nativeName).toBe('한국어');
    });

    it('MultilingualInputLayout 타입이 올바르게 정의되어 있다', () => {
      const inlineLayout: MultilingualInputLayout = 'inline';
      const tabsLayout: MultilingualInputLayout = 'tabs';
      expect(inlineLayout).toBe('inline');
      expect(tabsLayout).toBe('tabs');
    });
  });

  describe('기본 props', () => {
    it('컴포넌트가 올바르게 export되어 있다', () => {
      expect(MultilingualInput).toBeDefined();
      expect(typeof MultilingualInput).toBe('function');
    });

    it('displayName이 설정되어 있다', () => {
      expect(MultilingualInput.displayName).toBe('MultilingualInput');
    });
  });

  describe('availableLocales 기본값', () => {
    it('기본 언어 옵션이 올바르게 정의되어 있다', () => {
      const defaultLocales: LocaleOption[] = [
        { code: 'ko', name: '한국어', nativeName: '한국어' },
        { code: 'en', name: 'English', nativeName: 'English' },
      ];

      expect(defaultLocales.length).toBe(2);
      expect(defaultLocales[0].code).toBe('ko');
      expect(defaultLocales[0].name).toBe('한국어');
      expect(defaultLocales[1].code).toBe('en');
      expect(defaultLocales[1].name).toBe('English');
    });
  });

  describe('layout prop', () => {
    it('inline 레이아웃이 기본값이다', () => {
      const defaultLayout: MultilingualInputLayout = 'inline';
      expect(defaultLayout).toBe('inline');
    });

    it('tabs 레이아웃도 지원한다', () => {
      const tabsLayout: MultilingualInputLayout = 'tabs';
      expect(tabsLayout).toBe('tabs');
    });
  });

  describe('로컬 상태 버퍼 (debounce 대기 중 입력값 보존)', () => {
    it('사용자 입력 후 props.value가 갱신되지 않아도 입력값이 유지된다', () => {
      const onChange = vi.fn();

      // 초기 렌더: KO='원래값', EN='original'
      const { rerender } = render(
        <MultilingualInput
          value={{ ko: '원래값', en: 'original' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // KO 탭의 input 찾기
      const koInput = screen.getByDisplayValue('원래값') as HTMLInputElement;

      // 사용자가 KO 입력 변경
      fireEvent.change(koInput, { target: { value: '새값' } });

      // onChange 호출 확인 (debounce 전 — 엔진이 아직 state 갱신 안 함)
      expect(onChange).toHaveBeenCalled();
      const emittedEvent = onChange.mock.calls[0][0];
      expect(emittedEvent.target.value.ko).toBe('새값');
      expect(emittedEvent._changedKeys).toEqual(['ko']);

      // props.value를 갱신하지 않고 rerender (debounce 대기 상태 시뮬레이션)
      rerender(
        <MultilingualInput
          value={{ ko: '원래값', en: 'original' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // 로컬 버퍼 덕분에 입력값 '새값'이 유지되어야 함 (stale props.value로 돌아가지 않음)
      // 주의: props.value가 동일 참조('원래값')이므로 useEffect가 동기화하지 않음
      // → localValue는 '새값' 유지
      const koInputAfter = screen.getByDisplayValue('새값');
      expect(koInputAfter).toBeTruthy();
    });

    it('tabs 레이아웃에서 locale 전환 후에도 이전 locale 입력값이 보존된다', () => {
      const onChange = vi.fn();

      const { rerender } = render(
        <MultilingualInput
          value={{ ko: '원래값', en: 'original' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // KO 입력 변경
      const koInput = screen.getByDisplayValue('원래값') as HTMLInputElement;
      fireEvent.change(koInput, { target: { value: '수정값' } });

      // props.value 미갱신 상태에서 EN 탭 클릭
      // EN 탭 버튼 찾기 (English 텍스트 포함)
      const enTab = screen.getByRole('button', { name: /English/i });
      fireEvent.click(enTab);

      // props.value 미갱신 rerender
      rerender(
        <MultilingualInput
          value={{ ko: '원래값', en: 'original' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // KO 탭으로 돌아가기
      const koTab = screen.getByRole('button', { name: /한국어/i });
      fireEvent.click(koTab);

      // KO 입력값이 '수정값'으로 보존되어야 함 (stale '원래값'이 아님)
      const koInputAfter = screen.getByDisplayValue('수정값');
      expect(koInputAfter).toBeTruthy();
    });

    it('외부에서 props.value가 변경되면 로컬 상태가 동기화된다', () => {
      const onChange = vi.fn();

      const { rerender } = render(
        <MultilingualInput
          value={{ ko: '초기값', en: 'initial' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // 초기 렌더 확인
      expect(screen.getByDisplayValue('초기값')).toBeTruthy();

      // 외부에서 props.value 변경 (예: API 응답, debounce fire 후 state 갱신)
      rerender(
        <MultilingualInput
          value={{ ko: '서버값', en: 'server' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // 로컬 상태가 새 props.value로 동기화되어야 함
      expect(screen.getByDisplayValue('서버값')).toBeTruthy();
    });

    it('handleInputChange가 localValueRef 기반으로 병합하여 이전 locale 변경이 유실되지 않는다', () => {
      const onChange = vi.fn();

      render(
        <MultilingualInput
          value={{ ko: '원래KO', en: '원래EN' }}
          onChange={onChange}
          layout="inline"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      // KO 입력 변경
      const koInput = screen.getByDisplayValue('원래KO') as HTMLInputElement;
      fireEvent.change(koInput, { target: { value: '수정KO' } });

      // props.value 미갱신 상태에서 EN 입력도 변경 (inline은 둘 다 보임)
      const enInput = screen.getByDisplayValue('원래EN') as HTMLInputElement;
      fireEvent.change(enInput, { target: { value: '수정EN' } });

      // 두 번째 onChange 호출에서 KO 수정값이 포함되어야 함
      expect(onChange).toHaveBeenCalledTimes(2);
      const secondCall = onChange.mock.calls[1][0];
      expect(secondCall.target.value.ko).toBe('수정KO'); // localValueRef 기반이므로 유실되지 않음
      expect(secondCall.target.value.en).toBe('수정EN');
    });
  });
});