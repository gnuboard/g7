/**
 * 아이콘만 담은 단추의 접근 이름 — 아이콘을 꾸밈으로 숨긴 뒤에도 이름이 남는다.
 *
 * 7.1.0 전수 실측(O-C3b-3): Icon 은 이름(ariaLabel)을 받지 않으면 영문 글리프 이름(「xmark」·「chevron right」)을
 * role="img" 이름으로 달았다. 아이콘만 담은 단추는 그 영문 이름에 기대 이름을 얻고 있었다. Icon 을 꾸밈(aria-hidden)으로
 * 바꾸면서, 그런 단추(컴포넌트 안 단추 + 번들 레이아웃의 단추)에 화면 언어를 따르는 이름을 직접 단다.
 *
 * 번역 함수는 실제 언어 파일(템플릿 ko/en, 일본어 언어팩 ja)로 만든다 — 키가 세 언어에 모두 있는지도 함께 본다.
 *
 * @scenario-manifest tests/scenarios/icon-only-control-names.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, afterEach } from 'vitest';
import { DndContext } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { MultilingualInput } from '../MultilingualInput';
import { MultilingualTagInput } from '../MultilingualTagInput';
import { SortableMenuItem, type MenuItemData } from '../SortableMenuItem';
import { UserProfile } from '../UserProfile';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial');

const loadPartial = (locale: Locale, file: string): Record<string, unknown> => {
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  return JSON.parse(fs.readFileSync(path.join(dir, `${file}.json`), 'utf8'));
};

/** 엔진 G7Core.t 와 같은 규칙 — 없는 키는 키 그대로, {{name}} 치환 */
const installT = (locale: Locale) => {
  const files: Record<string, Record<string, unknown>> = {};
  for (const f of ['common', 'admin', 'auth']) files[f] = loadPartial(locale, f);
  (window as any).G7Core = {
    t: (key: string, params?: Record<string, string | number>) => {
      const [file, ...rest] = key.split('.');
      let node: unknown = files[file];
      for (const part of rest) node = (node as Record<string, unknown> | undefined)?.[part];
      if (typeof node !== 'string') return key;
      return params ? node.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : node;
    },
    locale: { supported: () => ['ko', 'en'], current: () => locale },
  };
};

afterEach(() => {
  delete (window as any).G7Core;
});

// 태그 이름은 화면에 보이는 태그 글자(언어별 값을 이은 것) 그대로 들어간다
const EXPECTED: Record<Locale, { removeLanguage: RegExp; removeTag: RegExp; expand: string; collapse: string }> = {
  // 다국어 입력·태그 입력의 지우기 단추는 공용 키 common.remove_language·common.remove_tag 를 쓴다. 태그 이름은 보는 언어의
  // 값이 있으면 그 값, 없으면 언어별 묶음 표기다.
  ko: { removeLanguage: /^English 입력 제거$/, removeTag: /^빨강 제거$/, expand: '펼치기', collapse: '접기' },
  en: { removeLanguage: /^Remove English$/, removeTag: /^Remove red$/, expand: 'Expand', collapse: 'Collapse' },
  ja: { removeLanguage: /^English の入力を削除$/, removeTag: /^KO: 빨강 \/ EN: red を外す$/, expand: '展開', collapse: '折りたたむ' },
};

const LOCALES = [
  { code: 'ko', name: '한국어', nativeName: '한국어' },
  { code: 'en', name: 'English', nativeName: 'English' },
];

const parentItem: MenuItemData = {
  id: 2, name: '사용자 관리', slug: 'users', url: '/admin/users', icon: 'users', isActive: true, isModuleMenu: false, hasChildren: true,
};

const renderMenuItem = (expanded: boolean) => render(
  <DndContext>
    <SortableContext items={[2]} strategy={verticalListSortingStrategy}>
      <SortableMenuItem item={parentItem} enableDrag isExpanded={expanded} />
    </SortableContext>
  </DndContext>,
);

describe.each(['ko', 'en', 'ja'] as Locale[])('아이콘만 담은 컴포넌트 단추 이름 (%s)', (locale) => {
  /**
   * @scenario control=composite_icon_only_button
   * @effects composite_icon_button_named_by_locale, decorative_icon_hidden_from_a11y_tree
   */
  it('다국어 입력의 언어 지우기 단추(묶음·탭 배치)가 화면 언어 이름을 갖는다', () => {
    installT(locale);
    for (const layout of ['inline', 'tabs'] as const) {
      const { unmount, container } = render(
        <MultilingualInput value={{ ko: '가', en: 'a' }} onChange={() => {}} layout={layout} name={`t_${layout}`} availableLocales={LOCALES} defaultLocale="ko" />,
      );
      const buttons = screen.getAllByRole('button', { name: EXPECTED[locale].removeLanguage });
      expect(buttons.length).toBeGreaterThan(0);
      // 꾸밈 아이콘은 이름을 내지 않는다
      expect(container.querySelectorAll('i[role="img"]').length).toBe(0);
      unmount();
    }
  });

  /**
   * @scenario control=composite_icon_only_button
   * @effects composite_icon_button_named_by_locale
   */
  it('다국어 태그 입력의 태그 지우기 단추가 태그 이름을 담은 화면 언어 이름을 갖는다', () => {
    installT(locale);
    render(<MultilingualTagInput value={[{ ko: '빨강', en: 'red' }]} onChange={() => {}} name="tags" />);
    expect(screen.getByRole('button', { name: EXPECTED[locale].removeTag })).toBeTruthy();
  });

  /**
   * @scenario control=composite_icon_only_button
   * @effects composite_icon_button_named_by_locale, expand_state_announced
   */
  it('메뉴 항목 펼침 단추가 화면 언어 이름과 펼침 상태를 갖는다', () => {
    installT(locale);
    const { unmount } = renderMenuItem(false);
    const collapsed = screen.getByRole('button', { name: EXPECTED[locale].expand });
    expect(collapsed.getAttribute('aria-expanded')).toBe('false');
    unmount();
    renderMenuItem(true);
    const expanded = screen.getByRole('button', { name: EXPECTED[locale].collapse });
    expect(expanded.getAttribute('aria-expanded')).toBe('true');
  });
});

describe.each(['ko', 'en', 'ja'] as Locale[])('글자 없는 머리글 단추 이름 (%s)', (locale) => {
  /**
   * @scenario control=composite_icon_only_button
   * @effects composite_icon_button_named_by_locale, expand_state_announced
   */
  it('머리글 사용자 메뉴(덮개 단추 모드)가 화면 언어 이름과 펼침 상태를 갖는다', () => {
    installT(locale);
    const { container } = render(<UserProfile user={{ name: 'U', email: 'u@example.com' } as any} dropdownOnly />);
    const t = (window as any).G7Core.t as (k: string) => string;
    const trigger = screen.getByRole('button', { name: t('common.user_menu') });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(container.querySelectorAll('button:not([aria-label])').length).toBe(0);
  });
});

describe('엔진 밖(번역 함수 없음) 대체 이름', () => {
  /**
   * @scenario control=composite_icon_only_button
   * @effects fallback_without_engine
   */
  it('번역 함수가 없으면 영문 대체 이름을 쓴다', () => {
    render(<MultilingualTagInput value={[{ ko: '빨강', en: 'red' }]} onChange={() => {}} name="tags" />);
    expect(screen.getByRole('button', { name: /^Remove .*빨강/ })).toBeTruthy();
  });
});

// ---------------------------------------------------------------------------
// 번들 레이아웃 정적 대조 — 아이콘만 담은 단추·링크가 이름 출처(aria-label·title·글자)를 갖는다
// ---------------------------------------------------------------------------

type LayoutNode = { type?: string; name?: string; props?: Record<string, unknown>; text?: unknown; children?: unknown[] };

const ICON = new Set(['Icon', 'UnifiedIcon']);
const INTERACTIVE = new Set(['Button', 'A', 'Link']);
const NAME_PROPS = ['aria-label', 'ariaLabel', 'title', 'aria-labelledby'];

const isNode = (n: unknown): n is LayoutNode => !!n && typeof n === 'object' && typeof (n as LayoutNode).name === 'string' && typeof (n as LayoutNode).type === 'string';

/** 하위 트리에서 이름 출처(글자·이름 있는 아이콘·대체 글 있는 이미지)와 이름 없는 아이콘 수를 센다 */
const content = (n: LayoutNode, acc: { named: number; icons: number }) => {
  for (const c of n.children ?? []) {
    if (!isNode(c)) { if (c && typeof c === 'object') acc.named++; continue; } // partial·slot 은 이름 출처로 친다
    const p = c.props ?? {};
    if (p['aria-hidden'] === true || p['aria-hidden'] === 'true') continue;
    if (typeof c.text === 'string' && c.text.trim()) acc.named++;
    if (ICON.has(c.name!)) { if (p.ariaLabel || p['aria-label']) acc.named++; else acc.icons++; continue; }
    if (c.name === 'Img' && p.alt) acc.named++;
    if (p['aria-label']) acc.named++;
    content(c, acc);
  }
};

/** 레이아웃 JSON 에서 이름 출처 없이 아이콘만 담은 단추·링크를 찾는다 */
const findUnnamedIconOnlyControls = (layoutDir: string): string[] => {
  const out: string[] = [];
  const walkDir = (d: string) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walkDir(p);
      else if (e.name.endsWith('.json') && fs.existsSync(p.replace(/\.json$/, '.g7.html'))) {
        const visit = (n: unknown) => {
          if (Array.isArray(n)) { n.forEach(visit); return; }
          if (!n || typeof n !== 'object') return;
          if (isNode(n)) {
            const props = n.props ?? {};
            const role = typeof props.role === 'string' ? props.role : '';
            if (INTERACTIVE.has(n.name!) || ['button', 'link', 'menuitem', 'tab'].includes(role)) {
              const own = NAME_PROPS.some((k) => props[k] !== undefined && props[k] !== '');
              const acc = { named: 0, icons: 0 };
              if (typeof n.text === 'string' && n.text.trim()) acc.named++;
              content(n, acc);
              if (!own && !acc.named && acc.icons > 0) out.push(`${path.relative(layoutDir, p).replace(/\\/g, '/')} ${n.name}`);
            }
          }
          for (const [k, v] of Object.entries(n)) if (k !== 'props' && typeof v === 'object') visit(v);
          if ((n as LayoutNode).props) for (const v of Object.values((n as LayoutNode).props!)) if (typeof v === 'object') visit(v);
        };
        visit(JSON.parse(fs.readFileSync(p, 'utf8')));
      }
    }
  };
  walkDir(layoutDir);
  return out;
};

describe('번들 레이아웃 아이콘만 담은 단추·링크', () => {
  /**
   * @scenario control=layout_icon_only_control
   * @effects layout_icon_only_control_named
   */
  it('관리자 템플릿 레이아웃의 아이콘만 담은 단추·링크는 모두 이름(aria-label·title)을 갖는다', () => {
    expect(findUnnamedIconOnlyControls(path.join(ROOT, 'layouts'))).toEqual([]);
  });

  /**
   * @scenario control=layout_icon_only_control
   * @effects layout_icon_only_control_named
   */
  it('레이아웃이 단추 이름으로 쓰는 다국어 키가 세 언어에 모두 있다', () => {
    const keys = new Set<string>();
    const walkDir = (d: string) => {
      for (const e of fs.readdirSync(d, { withFileTypes: true })) {
        const p = path.join(d, e.name);
        if (e.isDirectory()) walkDir(p);
        else if (e.name.endsWith('.g7.html')) {
          const src = fs.readFileSync(p, 'utf8');
          // 단추·링크 여는 태그의 이름 속성만 본다(메타 태그 title 등은 제외)
          const tags = [...src.matchAll(/<(?:button|a)\s(?:[^>"']|"[^"]*"|'[^']*')*>/g)].map((m) => m[0]).join(' ');
          for (const a of tags.matchAll(/\s(?:aria-label|title)="([^"]*)"/g)) {
            for (const m of a[1].matchAll(/\$t:((?:common|admin)\.[\w.]+)/g)) keys.add(m[1]);
          }
        }
      }
    };
    walkDir(path.join(ROOT, 'layouts'));
    const missing: string[] = [];
    for (const locale of ['ko', 'en', 'ja'] as Locale[]) {
      installT(locale);
      for (const k of keys) if ((window as any).G7Core.t(k) === k) missing.push(`${locale}:${k}`);
    }
    expect(keys.size).toBeGreaterThan(0);
    expect(missing).toEqual([]);
  });
});
