/**
 * 아이콘 단추와 상태 영역의 접근 이름이 화면 언어를 따른다.
 *
 * 7.1.0 전수 실측(O-C3-1): 머리글 테마 전환 단추의 접근 이름이 한국어·일본어 화면에서도 영문
 * 「Toggle theme」였다. 같은 결함군(다국어 키 없이 박힌 영문 aria-label)인 알림 삭제 단추와 화면 불러오는 중
 * 영역(PageSkeleton)도 함께 다국어 키로 바꿨다. 번역이 없거나 엔진 밖(단위 테스트)이면 종전 대체 문구를 쓴다.
 *
 * 번역 함수는 실제 언어 파일(템플릿 ko/en, 일본어 언어팩 ja)로 만든다 — 키가 세 언어에 모두 있는지도 함께 본다.
 *
 * @scenario-manifest tests/scenarios/accessible-name-i18n.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { ThemeToggle } from '../ThemeToggle';
import { NotificationCenter, type NotificationItem } from '../NotificationCenter';
import { PageSkeleton } from '../PageSkeleton';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial');

const loadCommon = (locale: Locale): Record<string, unknown> => {
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  return JSON.parse(fs.readFileSync(path.join(dir, 'common.json'), 'utf8'));
};

/** 엔진 G7Core.t 와 같은 규칙 — 없는 키는 키 그대로, {{name}} 치환 */
const installT = (locale: Locale) => {
  const common = loadCommon(locale);
  (window as any).G7Core = {
    t: (key: string, params?: Record<string, string | number>) => {
      const [file, ...rest] = key.split('.');
      let node: unknown = file === 'common' ? common : undefined;
      for (const part of rest) node = (node as Record<string, unknown> | undefined)?.[part];
      if (typeof node !== 'string') return key;
      return params ? node.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : node;
    },
  };
};

beforeEach(() => {
  // jsdom 에 없는 matchMedia·IntersectionObserver — ThemeToggle 은 시스템 테마, 알림 목록은 무한 스크롤 감시를 쓴다
  (window as any).matchMedia = (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  });
  (window as any).IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
});

afterEach(() => {
  delete (window as any).G7Core;
});

const EXPECTED: Record<Locale, { theme: string; deleteNotification: string; loading: string }> = {
  ko: { theme: '화면 테마', deleteNotification: '알림 삭제', loading: '로딩 중...' },
  en: { theme: 'Theme', deleteNotification: 'Delete notification', loading: 'Loading...' },
  ja: { theme: '画面テーマ', deleteNotification: '通知を削除', loading: '読み込み中...' },
};

const notifications: NotificationItem[] = [
  { id: 1, title: 'N1', message: 'm', time: '2026-04-10 10:00:00', read: false },
];

describe.each(['ko', 'en', 'ja'] as Locale[])('접근 이름 다국어 (%s)', (locale) => {
  // @scenario component=theme_toggle
  // @effects label_translated, menu_expanded_state
  it('테마 전환 단추 이름이 화면 언어를 따르고 펼침 상태를 알린다', () => {
    installT(locale);
    render(<ThemeToggle />);
    const trigger = screen.getByRole('button', { name: EXPECTED[locale].theme });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(screen.queryByRole('button', { name: 'Toggle theme' })).toBeNull();
  });

  // @scenario component=notification_delete
  // @effects label_translated
  it('알림 삭제 단추 이름이 화면 언어를 따른다', () => {
    installT(locale);
    render(<NotificationCenter notifications={notifications} onDelete={() => {}} titleText="Bell" />);
    fireEvent.click(screen.getByLabelText('Bell'));
    expect(screen.getAllByRole('button', { name: EXPECTED[locale].deleteNotification })).toHaveLength(1);
  });

  // @scenario component=page_skeleton
  // @effects label_translated
  it('화면 불러오는 중 영역 이름이 화면 언어를 따른다', () => {
    installT(locale);
    render(<PageSkeleton components={[]} options={{ animation: 'pulse', iteration_count: 1 }} />);
    expect(screen.getByRole('status').getAttribute('aria-label')).toBe(EXPECTED[locale].loading);
  });
});

describe('번역이 없을 때', () => {
  // @scenario component=fallback
  // @effects fallback_without_engine
  it('엔진 밖에서는 대체 문구로 이름을 갖는다', () => {
    render(<PageSkeleton components={[]} options={{ animation: 'pulse', iteration_count: 1 }} />);
    expect(screen.getByRole('status').getAttribute('aria-label')).toBe('Loading...');
  });

  // @scenario component=fallback
  // @effects fallback_without_engine
  it('엔진 밖에서는 테마 전환 단추도 영어 대체 문구로 이름을 갖는다 (한국어 기본값 금지)', () => {
    render(<ThemeToggle />);
    expect(screen.getByRole('button', { name: 'Theme' })).toBeTruthy();
  });
});
