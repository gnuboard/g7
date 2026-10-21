/**
 * MultilingualTabPanel — 언어팩으로 켠 언어의 탭 이름 (#148)
 *
 * 종전에는 내장 표에 없는 언어(fil-PH)가 코드 그대로 탭에 보였다. 서버가 주는 언어팩의 언어 이름
 * (`appConfig.localeNames`)을 먼저 쓴다.
 */
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { MultilingualTabPanel } from '../MultilingualTabPanel';
import { useCurrentMultilingualLocale } from '../MultilingualLocaleContext';

/**
 * 현재 활성 언어를 표시하는 테스트용 컴포넌트
 */
const LocaleDisplay: React.FC = () => {
  const { activeLocale } = useCurrentMultilingualLocale();
  return <span data-testid="active-locale">{activeLocale}</span>;
};

afterEach(() => {
  delete (window as any).G7Core;
});

describe('MultilingualTabPanel 언어팩 언어 탭 이름 (#148)', () => {
  // @scenario shape=region_tagged
  // @effects admin_locale_label_uses_language_pack_name
  it('언어팩으로 켠 언어 탭은 언어팩 이름으로 보인다 (종전 코드 fil-PH)', () => {
    (window as any).G7Core = {
      locale: { current: () => 'ko', supported: () => ['ko', 'fil-PH'] },
      state: { get: () => ({ appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)' } } }) },
      t: (key: string) => key,
    };

    render(
      <MultilingualTabPanel>
        <LocaleDisplay />
      </MultilingualTabPanel>
    );

    expect(screen.getByText('Filipino (Pilipinas)')).toBeTruthy();
    expect(screen.queryByText('fil-PH')).toBeNull();
  });
});
