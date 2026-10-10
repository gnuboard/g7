/**
 * 아이콘 단추와 영역의 접근 이름이 화면 언어를 따른다.
 *
 * 7.1.0 전수 실측(O-C3-1): 머리글 테마 전환 단추의 접근 이름이 한국어·일본어 화면에서도 영문
 * 「Toggle theme」였다. 같은 결함군(다국어 키 없이 박힌 영문 aria-label)인 알림 삭제 단추, 경고 닫기 단추,
 * 현재 위치(breadcrumb) 영역, 태그 지우기 단추도 함께 다국어 키로 바꿨다. 번역이 없거나 엔진 밖(단위 테스트)이면
 * 종전 대체 문구를 쓴다.
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
import { Alert } from '../Alert';
import { Breadcrumb } from '../Breadcrumb';
import { TagSelect } from '../TagSelect';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial');

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
  // jsdom 에 없는 matchMedia — ThemeToggle 이 시스템 테마를 읽는다
  (window as any).matchMedia = (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  });
});

afterEach(() => {
  delete (window as any).G7Core;
});

const EXPECTED: Record<Locale, { theme: string; deleteNotification: string; closeAlert: string; breadcrumb: string; removeTag: string }> = {
  ko: { theme: '화면 테마', deleteNotification: '알림 삭제', closeAlert: '메시지 닫기', breadcrumb: '현재 위치', removeTag: 'M 지우기' },
  en: { theme: 'Theme', deleteNotification: 'Delete notification', closeAlert: 'Close alert', breadcrumb: 'Breadcrumb', removeTag: 'Remove M' },
  ja: { theme: '画面テーマ', deleteNotification: '通知を削除', closeAlert: 'メッセージを閉じる', breadcrumb: 'パンくずリスト', removeTag: 'Mを削除' },
};

const notifications: NotificationItem[] = [
  { id: 1, title: 'N1', message: 'm', time: 't', read: false },
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
    fireEvent.click(screen.getByRole('button', { name: 'Bell' }));
    expect(screen.getAllByRole('button', { name: EXPECTED[locale].deleteNotification })).toHaveLength(1);
  });

  // @scenario component=alert_close
  // @effects label_translated
  it('경고 닫기 단추 이름이 화면 언어를 따른다', () => {
    installT(locale);
    render(<Alert type="info" message="msg" dismissible onDismiss={() => {}} />);
    expect(screen.getByRole('button', { name: EXPECTED[locale].closeAlert })).toBeTruthy();
  });

  // @scenario component=breadcrumb
  // @effects label_translated
  it('현재 위치 영역 이름이 화면 언어를 따른다', () => {
    installT(locale);
    render(<Breadcrumb items={[{ label: 'A', href: '/a' }, { label: 'B' }]} />);
    expect(screen.getByRole('navigation', { name: EXPECTED[locale].breadcrumb })).toBeTruthy();
  });

  // @scenario component=tag_remove
  // @effects label_translated, label_includes_item
  it('태그 지우기 단추 이름이 화면 언어를 따르고 항목 이름을 담는다', () => {
    installT(locale);
    render(<TagSelect options={[{ value: 's', label: 'S' }, { value: 'm', label: 'M' }]} value={['s', 'm']} />);
    expect(screen.getByRole('button', { name: EXPECTED[locale].removeTag })).toBeTruthy();
  });
});

describe('번역이 없을 때', () => {
  // @scenario component=fallback
  // @effects fallback_without_engine
  it('엔진 밖에서는 대체 문구로 이름을 갖는다', () => {
    render(<Alert type="info" message="msg" dismissible onDismiss={() => {}} />);
    expect(screen.getByRole('button', { name: 'Close alert' })).toBeTruthy();
  });
});
