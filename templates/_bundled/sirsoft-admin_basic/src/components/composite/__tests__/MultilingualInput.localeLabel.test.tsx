/**
 * MultilingualInput — 언어팩으로 켠 언어의 칸 라벨 (#148)
 *
 * 종전에는 내장 표에 없는 언어(fil-PH)의 라벨이 대문자 코드(FIL-PH)였다. 서버가 주는 언어팩의 언어 이름
 * (`appConfig.localeNames`)을 먼저 쓴다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';
import { MultilingualInput } from '../MultilingualInput';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => {
      const map: Record<string, string> = {
        'common.language_ko': '한국어',
        'common.add_language': '언어 추가',
      };
      return map[key] ?? key;
    },
    locale: {
      supported: () => ['ko', 'fil-PH'],
      current: () => 'ko',
    },
  };
  (window as any).G7Config = { appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)' } } };
});

afterEach(() => {
  delete (window as any).G7Config;
  delete (window as any).G7Core;
});

describe('MultilingualInput 언어팩 언어 라벨 (#148)', () => {
  // @scenario shape=region_tagged
  // @effects admin_locale_label_uses_language_pack_name
  it('언어팩으로 켠 언어(fil-PH)의 칸 라벨은 언어팩 이름이다 (종전 FIL-PH)', () => {
    render(
      <MultilingualInput
        value={{ ko: '이름', 'fil-PH': 'Pangalan' }}
        onChange={vi.fn()}
        name="title"
        defaultLocale="ko"
      />
    );

    expect(screen.getAllByText('Filipino (Pilipinas)').length).toBeGreaterThan(0);
    expect(screen.queryByText('FIL-PH')).toBeNull();
  });
});
