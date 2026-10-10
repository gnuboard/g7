/**
 * 아이콘만 담은 단추의 접근 이름과 컴포넌트 기본 문구 — 아이콘을 꾸밈으로 숨긴 뒤에도 이름이 남고, 기본 문구는 화면 언어를 따른다.
 *
 * 7.1.0 전수 실측(O-C3b-3): Icon 은 이름(ariaLabel)을 받지 않으면 영문 글리프 이름(「trash can」·「chevron left」·「heart」)을
 * role="img" 이름으로 달았다. 아이콘만 담은 단추(장바구니 삭제·찜·상품 목록 넘김·리뷰 별점 등)는 그 영문 이름에 기대 이름을
 * 얻고 있었다. Icon 을 꾸밈(aria-hidden)으로 바꾸면서 그런 단추에 화면 언어를 따르는 이름을 직접 단다.
 * 같은 결함군(O-C3b-4)인 FileInput·ThemeToggle 의 영문 기본 문구, Select 검색칸 이름, 프로필 사진 미리보기 대체 글도 다국어 키로.
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
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, afterEach, beforeEach, beforeAll } from 'vitest';
import { ThemeToggle } from '../ThemeToggle';
import { FileInput } from '../../basic/FileInput';
import { Select } from '../../basic/Select';
import type { AvatarUploader as AvatarUploaderType } from '../AvatarUploader';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial');

const loadAll = (locale: Locale): Record<string, Record<string, unknown>> => {
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  const out: Record<string, Record<string, unknown>> = {};
  for (const f of fs.readdirSync(dir)) if (f.endsWith('.json')) out[f.replace(/\.json$/, '')] = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  return out;
};

/** 엔진 G7Core.t 와 같은 규칙 — 없는 키는 키 그대로, {{name}} 치환 */
const makeT = (locale: Locale) => {
  const files = loadAll(locale);
  return (key: string, params?: Record<string, string | number>) => {
    const [file, ...rest] = key.split('.');
    let node: unknown = files[file];
    for (const part of rest) node = (node as Record<string, unknown> | undefined)?.[part];
    if (typeof node !== 'string') return key;
    return params ? node.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : node;
  };
};

// AvatarUploader 는 모듈을 불러올 때 G7Core 를 붙잡는다 — t 를 바꿔 끼울 수 있는 객체를 먼저 세운다
let currentT: ((key: string, params?: Record<string, string | number>) => string) | null = null;
const g7 = { t: (key: string, params?: Record<string, string | number>) => (currentT ? currentT(key, params) : key) };
let AvatarUploader: typeof AvatarUploaderType;

beforeAll(async () => {
  (window as any).G7Core = g7;
  ({ AvatarUploader } = await import('../AvatarUploader'));
  delete (window as any).G7Core;
});

const installT = (locale: Locale) => {
  currentT = makeT(locale);
  (window as any).G7Core = g7;
  return currentT;
};

beforeEach(() => {
  (window as any).matchMedia = (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  });
});

afterEach(() => {
  currentT = null;
  delete (window as any).G7Core;
});

describe.each(['ko', 'en', 'ja'] as Locale[])('기본 문구 다국어 (%s)', (locale) => {
  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('ThemeToggle 기본 메뉴 항목이 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<ThemeToggle />);
    fireEvent.click(screen.getByRole('button', { name: t('common.theme_select') }));
    expect(screen.getByText(t('common.theme.auto'))).toBeTruthy();
    expect(screen.getByText(t('common.theme.light'))).toBeTruthy();
    expect(screen.getByText(t('common.theme.dark'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('FileInput 기본 단추·안내·지우기 이름과 Select 검색칸이 화면 언어를 따른다', () => {
    const t = installT(locale);
    const { container } = render(<FileInput />);
    expect(screen.getByText(t('common.file_input.browse'))).toBeTruthy();
    expect(screen.getByText(t('common.file_input.no_file'))).toBeTruthy();
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'a.txt', { type: 'text/plain' })] } });
    expect(screen.getByRole('button', { name: t('common.file_input.clear') })).toBeTruthy();
    render(<Select searchable options={[{ value: 'a', label: 'A' }]} value="a" onChange={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /A/ }));
    expect(screen.getByRole('searchbox', { name: t('common.search') })).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('프로필 사진 미리보기 대체 글이 화면 언어를 따른다', () => {
    const t = installT(locale);
    const orig = URL.createObjectURL;
    (URL as any).createObjectURL = () => 'blob:preview';
    (URL as any).revokeObjectURL = () => {};
    try {
      const { container } = render(<AvatarUploader />);
      const input = container.querySelector('input[type="file"]') as HTMLInputElement;
      fireEvent.change(input, { target: { files: [new File(['x'], 'a.png', { type: 'image/png' })] } });
      expect(screen.getByAltText(t('mypage.profile.avatar_preview'))).toBeTruthy();
      expect(screen.queryByAltText('Preview')).toBeNull();
    } finally {
      (URL as any).createObjectURL = orig;
    }
  });
});

describe('엔진 밖(번역 함수 없음) 대체 문구', () => {
  /**
   * @scenario control=default_text
   * @effects fallback_without_engine
   */
  it('번역 함수가 없으면 종전 영문 기본값을 쓴다', () => {
    render(<FileInput />);
    expect(screen.getByText('Browse')).toBeTruthy();
    expect(screen.getByText('No file selected')).toBeTruthy();
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

const content = (n: LayoutNode, acc: { named: number; icons: number }) => {
  for (const c of n.children ?? []) {
    if (!isNode(c)) { if (c && typeof c === 'object') acc.named++; continue; }
    const p = c.props ?? {};
    if (p['aria-hidden'] === true || p['aria-hidden'] === 'true') continue;
    if (typeof c.text === 'string' && c.text.trim()) acc.named++;
    if (ICON.has(c.name!)) { if (p.ariaLabel || p['aria-label']) acc.named++; else acc.icons++; continue; }
    if (c.name === 'Img' && p.alt) acc.named++;
    if (p['aria-label']) acc.named++;
    content(c, acc);
  }
};

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
  it('사용자 템플릿 레이아웃의 아이콘만 담은 단추·링크는 모두 이름(aria-label·title)을 갖는다', () => {
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
            for (const m of a[1].matchAll(/\$t:([a-z_]+\.[\w.]+)/g)) keys.add(m[1]);
          }
        }
      }
    };
    walkDir(path.join(ROOT, 'layouts'));
    const missing: string[] = [];
    for (const locale of ['ko', 'en', 'ja'] as Locale[]) {
      const t = makeT(locale);
      for (const k of keys) if (t(k) === k) missing.push(`${locale}:${k}`);
    }
    expect(keys.size).toBeGreaterThan(0);
    expect(missing).toEqual([]);
  });
});
