/**
 * @file icon-only-control-names.test.ts
 * @description 아이콘만 담은 단추·링크의 접근 이름 — 템플릿 Icon 이 꾸밈으로 숨겨진 뒤에도 이름이 남는다 (7.1.0 O-C3b-3)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석(DOM 비의존) + 이름 키의 언어 파일 대조.
 *
 * 결함: 번들 템플릿의 Icon 은 이름(ariaLabel)을 받지 않으면 영문 글리프 이름(「trash」·「chevron left」·「ellipsis」)을
 *   role="img" 이름으로 달았다. 이 확장 레이아웃의 아이콘만 담은 단추(삭제·편집·새로고침·쪽 넘김·더보기 등)는
 *   그 영문 이름에 기대 이름을 얻고 있었다. Icon 을 꾸밈(aria-hidden)으로 바꾸면서 이 단추들은 이름을 잃는다.
 *
 * 수정: 아이콘만 담은 단추·링크에 화면 언어를 따르는 이름(aria-label, 필요하면 title)을 직접 단다.
 *
 * @scenario-manifest tests/scenarios/icon-only-control-names.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../../../..');
const REPO = path.resolve(EXT_ROOT, '../../..');
const EXT_ID = 'sirsoft-gdpr';
const LANG_PACK = 'g7-plugin-sirsoft-gdpr-ja';

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

const layoutFiles = (ext: string): string[] => {
  const out: string[] = [];
  const walk = (d: string) => {
    if (!fs.existsSync(d)) return;
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.name.endsWith(ext)) out.push(p);
    }
  };
  walk(path.join(EXT_ROOT, 'resources/layouts'));
  walk(path.join(EXT_ROOT, 'resources/extensions'));
  return out;
};

/** 레이아웃 JSON 에서 이름 출처 없이 아이콘만 담은 단추·링크를 찾는다 */
const findUnnamedIconOnlyControls = (): string[] => {
  const out: string[] = [];
  for (const file of layoutFiles('.json')) {
    if (!fs.existsSync(file.replace(/\.json$/, '.g7.html'))) continue;
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
          if (!own && !acc.named && acc.icons > 0) out.push(`${path.relative(EXT_ROOT, file).replace(/\\/g, '/')} ${n.name}`);
        }
      }
      for (const [k, v] of Object.entries(n)) if (k !== 'props' && typeof v === 'object') visit(v);
      if ((n as LayoutNode).props) for (const v of Object.values((n as LayoutNode).props!)) if (typeof v === 'object') visit(v);
    };
    visit(JSON.parse(fs.readFileSync(file, 'utf8')));
  }
  return out;
};

/** `$partial` 을 풀어 언어 묶음을 만든다 */
const loadBundle = (file: string): Record<string, unknown> => {
  if (!fs.existsSync(file)) return {};
  const dir = path.dirname(file);
  const resolve = (o: Record<string, unknown>): Record<string, unknown> => {
    for (const [k, v] of Object.entries(o)) {
      if (v && typeof v === 'object') {
        const partial = (v as Record<string, unknown>).$partial;
        o[k] = typeof partial === 'string' ? JSON.parse(fs.readFileSync(path.join(dir, partial), 'utf8')) : resolve(v as Record<string, unknown>);
      }
    }
    return o;
  };
  return resolve(JSON.parse(fs.readFileSync(file, 'utf8')));
};

/** 템플릿 언어(관리자·사용자) — partial 디렉터리의 파일 이름이 첫 마디 */
const loadTemplate = (template: string, locale: string): Record<string, unknown> => {
  const dir = locale === 'ja'
    ? path.join(REPO, `lang-packs/_bundled/g7-template-${template}-ja/frontend/partial`)
    : path.join(REPO, `templates/_bundled/${template}/lang/partial/${locale}`);
  const out: Record<string, unknown> = {};
  if (!fs.existsSync(dir)) return out;
  for (const f of fs.readdirSync(dir)) if (f.endsWith('.json')) out[f.replace(/\.json$/, '')] = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  return out;
};

const get = (o: Record<string, unknown>, key: string): unknown => key.split('.').reduce<unknown>((a, p) => (a && typeof a === 'object' ? (a as Record<string, unknown>)[p] : undefined), o);

describe(`${EXT_ID} 레이아웃의 아이콘만 담은 단추·링크`, () => {
  /**
   * @scenario control=layout_icon_only_control
   * @effects layout_icon_only_control_named
   */
  it('아이콘만 담은 단추·링크는 모두 이름(aria-label·title)을 갖는다', () => {
    expect(findUnnamedIconOnlyControls()).toEqual([]);
  });

  /**
   * @scenario control=layout_icon_only_control
   * @effects icon_only_control_keys_resolve
   */
  it('단추·링크 이름으로 쓴 다국어 키가 ko/en/ja 에 모두 있다', () => {
    const missing: string[] = [];
    let count = 0;
    for (const file of layoutFiles('.g7.html')) {
      const rel = path.relative(EXT_ROOT, file).replace(/\\/g, '/');
      const template = /\/user\//.test(rel) ? 'sirsoft-basic' : 'sirsoft-admin_basic';
      const src = fs.readFileSync(file, 'utf8');
      const tags = [...src.matchAll(/<(?:button|a)\s(?:[^>"']|"[^"]*"|'[^']*')*>/g)].map((m) => m[0]).join(' ');
      for (const a of tags.matchAll(/\s(?:aria-label|title)="([^"]*)"/g)) {
        for (const m of a[1].matchAll(/\$t:([a-z][\w-]*\.[\w.]+)/g)) {
          const key = m[1];
          count++;
          for (const locale of ['ko', 'en', 'ja']) {
            let value: unknown;
            if (key.startsWith(`${EXT_ID}.`)) {
              const bundle = locale === 'ja'
                ? loadBundle(path.join(REPO, `lang-packs/_bundled/${LANG_PACK}/frontend/ja.json`))
                : loadBundle(path.join(EXT_ROOT, `resources/lang/${locale}.json`));
              value = get(bundle, key.slice(EXT_ID.length + 1));
            } else {
              value = get(loadTemplate(template, locale), key);
            }
            if (typeof value !== 'string') missing.push(`${locale}:${key} (${rel})`);
          }
        }
      }
    }
    expect(count).toBeGreaterThan(0);
    expect([...new Set(missing)]).toEqual([]);
  });
});
