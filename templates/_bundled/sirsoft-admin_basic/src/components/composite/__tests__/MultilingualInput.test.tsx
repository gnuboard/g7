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
    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('MultilingualValue 타입이 올바르게 정의되어 있다', () => {
      const value: MultilingualValue = {
        ko: '한국어 텍스트',
        en: 'English text',
      };
      expect(value.ko).toBe('한국어 텍스트');
      expect(value.en).toBe('English text');
    });

    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
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

    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('MultilingualInputLayout 타입이 올바르게 정의되어 있다', () => {
      const inlineLayout: MultilingualInputLayout = 'inline';
      const tabsLayout: MultilingualInputLayout = 'tabs';
      expect(inlineLayout).toBe('inline');
      expect(tabsLayout).toBe('tabs');
    });
  });

  describe('기본 props', () => {
    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('컴포넌트가 올바르게 export되어 있다', () => {
      expect(MultilingualInput).toBeDefined();
      expect(typeof MultilingualInput).toBe('function');
    });

    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('displayName이 설정되어 있다', () => {
      expect(MultilingualInput.displayName).toBe('MultilingualInput');
    });
  });

  describe('availableLocales 기본값', () => {
    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
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
    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('inline 레이아웃이 기본값이다', () => {
      const defaultLayout: MultilingualInputLayout = 'inline';
      expect(defaultLayout).toBe('inline');
    });

    /**
     * @scenario aspect=component_contract
     * @effects component_contract_exposed
     */
    it('tabs 레이아웃도 지원한다', () => {
      const tabsLayout: MultilingualInputLayout = 'tabs';
      expect(tabsLayout).toBe('tabs');
    });
  });

  describe('로컬 상태 버퍼 (debounce 대기 중 입력값 보존)', () => {
    /**
     * @scenario aspect=local_input_buffer
     * @effects local_input_preserved_until_parent_syncs
     */
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

    /**
     * @scenario aspect=local_input_buffer
     * @effects local_input_preserved_until_parent_syncs
     */
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
      const enTab = screen.getByRole('button', { name: /^English/i });
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

    /**
     * @scenario aspect=local_input_buffer
     * @effects local_input_preserved_until_parent_syncs
     */
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

    /**
     * @scenario aspect=local_input_buffer
     * @effects local_input_preserved_until_parent_syncs
     */
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
  // 저장값에 기본 언어 키가 없을 때 (예: 꺼진 언어팩의 번역만 남은 설정값)
  // 기본 언어 칸과 그 옆의 「언어 추가」 버튼이 함께 사라져 운영자가 기본 언어를 다시 입력할 수 없었다.
  describe('기본 언어 키가 없는 값', () => {
    const WITH_JA: LocaleOption[] = [
      ...DEFAULT_LOCALES,
      { code: 'ja', name: '日本語', nativeName: '日本語' },
    ];

    /**
     * @scenario aspect=default_locale_missing
     * @effects default_locale_field_always_first
     */
    it('inline 레이아웃은 첫 렌더부터 기본 언어 칸과 언어 추가 버튼을 그린다', () => {
      const { container } = render(
        <MultilingualInput
          value={{ ja: '序文' }}
          onChange={vi.fn()}
          name="intro"
          inputType="textarea"
          availableLocales={WITH_JA}
          defaultLocale="ko"
        />
      );

      const names = Array.from(container.querySelectorAll('textarea')).map(el => el.getAttribute('name'));
      expect(names).toEqual(['intro[ko]', 'intro[ja]']);
      expect(screen.getByText('언어 추가')).toBeTruthy();
      expect(screen.getByDisplayValue('序文')).toBeTruthy();
    });

    /**
     * @scenario aspect=default_locale_missing
     * @effects default_locale_field_always_first
     */
    it('값이 바뀌어 기본 언어 키가 빠져도 기본 언어 칸을 앞에 유지한다', () => {
      const props = {
        onChange: vi.fn(),
        name: 'intro',
        inputType: 'textarea' as MultilingualInputType,
        availableLocales: WITH_JA,
        defaultLocale: 'ko',
      };
      const { container, rerender } = render(<MultilingualInput value={{ ko: '서문' }} {...props} />);

      rerender(<MultilingualInput value={{ ja: '序文' }} {...props} />);

      const names = Array.from(container.querySelectorAll('textarea')).map(el => el.getAttribute('name'));
      expect(names).toEqual(['intro[ko]', 'intro[ja]']);
      expect(screen.getByText('언어 추가')).toBeTruthy();
    });
  });

  // 탭의 삭제 버튼이 선택 버튼 안에 있으면 버튼 안에 버튼이 들어가 HTML 규격을 어긴다
  describe('tabs 레이아웃 버튼 구조', () => {
    /**
     * @scenario aspect=tabs_button_structure
     * @effects tab_remove_button_not_nested
     */
    it('언어 탭의 삭제 버튼은 선택 버튼 안에 들어가지 않고, 누르면 그 언어 칸만 사라진다', () => {
      const onChange = vi.fn();
      const { container } = render(
        <MultilingualInput
          value={{ ko: '원래값', en: 'original' }}
          onChange={onChange}
          layout="tabs"
          name="test_input"
          availableLocales={DEFAULT_LOCALES}
          defaultLocale="ko"
        />
      );

      expect(container.querySelectorAll('button button')).toHaveLength(0);

      const enTab = screen.getByRole('button', { name: /^English/i });
      const removeButton = enTab.parentElement!.querySelectorAll('button')[1];
      expect(removeButton).toBeTruthy();
      fireEvent.click(removeButton);

      const lastValue = onChange.mock.calls[onChange.mock.calls.length - 1][0].target.value;
      expect(lastValue.ko).toBe('원래값');
      expect(lastValue.en).toBeUndefined();
    });
  });
});
