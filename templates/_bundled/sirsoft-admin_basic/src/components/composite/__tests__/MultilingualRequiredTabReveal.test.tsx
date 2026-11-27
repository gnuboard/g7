// e2e:allow 컴포넌트 단위 회귀 — 9개 화면 실측은 M1 이 개발 사이트에서 재측정(P10, results/S5.md 7절)
/**
 * @file MultilingualRequiredTabReveal.test.tsx
 * @description 탭형 다국어 입력 — 비어 있는 필수 언어가 막히면 그 탭을 열어 칸을 보여 준다 (P10)
 *
 * `layout="tabs"` 는 모든 언어 칸을 그리고 지금 탭이 아닌 칸을 `hidden` 으로 숨긴다. 필수(사이트 기본 언어) 칸이 비어
 * 있는 채 다른 언어 탭에서 저장하면 브라우저 검증이 숨은 칸에서 멈춰 「포커스할 수 없음」으로 제출이 조용히 끝났다
 * (요청 0·안내 0 — M1 D5). 서버 검증(noValidate 폼)으로 오류가 와도 칸 아래 문구는 숨은 탭에 가려진다.
 * 컴포넌트가 직접 그 탭을 연다:
 *  - 브라우저가 숨은 필수 칸에 invalid 를 올리면 → 그 언어 탭으로 바꾸고 칸에 포커스·검증 말풍선
 *  - 오류(error)가 들어왔는데 필수 언어 칸이 비어 있으면 → 그 언어 탭으로 바꾼다(tabs·compact)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, fireEvent, act } from '@testing-library/react';
import { MultilingualInput } from '../MultilingualInput';

const LOCALES = [
  { code: 'ko', name: 'Korean', nativeName: '한국어' },
  { code: 'en', name: 'English', nativeName: 'English' },
];

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    locale: { supported: () => ['ko', 'en'], current: () => 'ko' },
    state: { get: () => ({ appConfig: { siteLocale: 'ko' } }) },
  };
});

afterEach(() => {
  delete (window as any).G7Core;
});

/** 탭 버튼(언어 이름이 든 버튼)을 누른다. */
function clickTab(container: HTMLElement, label: string): void {
  const button = Array.from(container.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes(label));
  if (!button) throw new Error(`tab ${label} not found`);
  fireEvent.click(button);
}

/** 그 언어 칸을 감싼 탭 패널이 보이는지(hidden 이 아닌지). */
function isShown(input: Element | null): boolean {
  let el: Element | null = input;
  while (el) {
    if (el.classList.contains('hidden')) return false;
    if (el.classList.contains('block')) return true;
    el = el.parentElement;
  }
  return true;
}

describe('MultilingualInput — 비어 있는 필수 언어 탭 열기', () => {
  /**
   * @scenario case=browser_invalid_on_hidden_tab
   * @effects required_locale_tab_opens_on_invalid
   */
  it('다른 언어 탭에서 저장해 숨은 필수 칸이 invalid 가 되면 그 탭이 열리고 칸에 포커스가 간다', async () => {
    const { container } = render(
      <MultilingualInput name="label" value={{ ko: '', en: '' }} onChange={() => {}} required layout="tabs" availableLocales={LOCALES} defaultLocale="ko" />,
    );
    const ko = container.querySelector('input[name="label[ko]"]') as HTMLInputElement;
    clickTab(container, 'English');
    expect(isShown(ko)).toBe(false);

    await act(async () => {
      fireEvent.invalid(ko);
    });

    expect(isShown(ko)).toBe(true);
    expect(isShown(container.querySelector('input[name="label[en]"]'))).toBe(false);
    expect(document.activeElement).toBe(ko);
  });

  /**
   * @scenario case=server_error_on_other_tab
   * @effects required_locale_tab_opens_on_error
   */
  it('다른 언어 탭을 보는 중 서버 오류가 오고 필수 언어 칸이 비어 있으면 그 탭이 열린다', () => {
    const props = { name: 'label', onChange: () => {}, required: true, layout: 'tabs' as const, availableLocales: LOCALES, defaultLocale: 'ko' };
    const { container, rerender } = render(<MultilingualInput {...props} value={{ ko: '', en: 'Name' }} />);
    clickTab(container, 'English');
    const ko = container.querySelector('input[name="label[ko]"]');
    expect(isShown(ko)).toBe(false);

    rerender(<MultilingualInput {...props} value={{ ko: '', en: 'Name' }} error="작업 명칭의 한국어(ko) 값은 필수입니다." />);

    expect(isShown(ko)).toBe(true);
  });

  /**
   * @scenario case=server_error_required_filled
   * @effects required_locale_tab_opens_on_error
   */
  it('필수 언어 칸이 채워져 있으면 오류가 와도 지금 탭을 그대로 둔다(다른 이유의 오류)', () => {
    const props = { name: 'label', onChange: () => {}, required: true, layout: 'tabs' as const, availableLocales: LOCALES, defaultLocale: 'ko' };
    const { container, rerender } = render(<MultilingualInput {...props} value={{ ko: '이름', en: '' }} />);
    clickTab(container, 'English');
    rerender(<MultilingualInput {...props} value={{ ko: '이름', en: '' }} error="최대 255자입니다." />);

    expect(isShown(container.querySelector('input[name="label[en]"]'))).toBe(true);
    expect(isShown(container.querySelector('input[name="label[ko]"]'))).toBe(false);
  });

  /**
   * @scenario case=compact_server_error
   * @effects required_locale_tab_opens_on_error
   */
  it('compact 배치도 오류가 오고 필수 언어 칸이 비어 있으면 그 언어 칸으로 바꾼다', () => {
    const props = { name: 'label', onChange: () => {}, required: true, layout: 'compact' as const, availableLocales: LOCALES, defaultLocale: 'ko' };
    const { container, rerender } = render(<MultilingualInput {...props} value={{ ko: '', en: 'Name' }} />);
    clickTab(container, 'EN');
    expect(container.querySelector('input[name="label[ko]"]')).toBeNull();

    rerender(<MultilingualInput {...props} value={{ ko: '', en: 'Name' }} error="필수입니다." />);

    expect(container.querySelector('input[name="label[ko]"]')).not.toBeNull();
  });
});
