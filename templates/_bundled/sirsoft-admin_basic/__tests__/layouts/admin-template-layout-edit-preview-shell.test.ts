/**
 * @file admin-template-layout-edit-preview-shell.test.ts
 * @description 코드 편집기 미리보기가 편집 중인 템플릿 종류의 화면 셸로 열리는지
 *
 * 미리보기 창은 서버가 내준 토큰으로 `/preview/{token}` 을 열었다. 이 주소는 사용자 화면 셸이라 활성 사용자 템플릿
 * (sirsoft-basic)의 컴포넌트·다국어·라우트를 받아 그렸고, sirsoft-admin_basic 레이아웃 미리보기가 관리자 템플릿 자원이 아닌
 * 사용자 템플릿 자원으로 그려졌다(7.1.0 O-G3-6). 코어가 관리자 셸에도 `*\/admin/preview/:token` 을 주입하므로, 서버가
 * 템플릿 종류에 맞춰 내주는 `preview_url` 을 그대로 연다. 응답에 주소가 없을 때만 토큰으로 사용자 셸 주소를 만든다.
 *
 * @scenario-manifest tests/scenarios/layout-edit-preview-shell.yaml
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

type Json = Record<string, any>;

const load = (name: string): Json =>
  JSON.parse(readFileSync(resolve(__dirname, '../../layouts', `${name}.json`), 'utf-8'));

const LAYOUT_EDIT = load('admin_template_layout_edit');
const EXTENSION_PICKER = load('partials/admin_template_layout_edit/_modal_extension_preview_layout');

/**
 * 진입점별 미리보기 openWindow 를 고른다.
 * - layout_file: 편집 화면의 레이아웃 파일 미리보기(`…/layouts/{이름}/preview`)
 * - extension_overlay: 편집 화면의 덮어쓰기형 확장 조각 미리보기(`…/layout-extensions/{id}/preview`)
 * - extension_picker: 주입형 확장 조각의 대상 레이아웃 고르는 창(`_modal_extension_preview_layout`)
 */
function entryWindows(entry: 'layout_file' | 'extension_overlay' | 'extension_picker'): Json[] {
  if (entry === 'extension_picker') return previewOpenWindows(EXTENSION_PICKER);
  const pattern = entry === 'layout_file' ? /\/layouts\/[^/]+\/preview$/ : /\/layout-extensions\/[^/]+\/preview$/;
  return previewOpenWindows(LAYOUT_EDIT, pattern);
}

/** 미리보기 apiCall 의 onSuccess 에 있는 openWindow 를 모두 모은다. */
function previewOpenWindows(tree: unknown, targetPattern: RegExp = /\/preview$/): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (!n || typeof n !== 'object') return;
    const obj = n as Json;
    if (obj.handler === 'apiCall' && targetPattern.test(String(obj.target ?? ''))) {
      const success = Array.isArray(obj.onSuccess) ? obj.onSuccess : [obj.onSuccess];
      success.filter((a: Json) => a?.handler === 'openWindow').forEach((a: Json) => out.push(a));
    }
    Object.values(obj).forEach(walk);
  };
  walk(tree);
  return out;
}

function evalBinding(expr: unknown, ctx: Record<string, unknown>): unknown {
  if (typeof expr !== 'string') return expr;
  const whole = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim());
  // eslint-disable-next-line no-new-func
  const run = (body: string) => new Function(...Object.keys(ctx), `return (${body});`)(...Object.values(ctx));
  if (whole && !whole[1].includes('}}')) return run(whole[1]);
  return expr.replace(/\{\{([\s\S]*?)\}\}/g, (_, body: string) => String(run(body) ?? ''));
}

describe('코드 편집기 미리보기 — 템플릿 종류의 화면 셸로 연다', () => {
  /**
   * layout_file 진입점 — 서버가 내준 관리자 셸 주소를 그대로 연다.
   *
   * @scenario template_type=admin, entry=layout_file
   * @effects preview_opens_server_preview_url
   */
  it('layout_file: 서버가 내준 관리자 셸 주소를 그대로 연다', () => {
    const wins = entryWindows('layout_file');
    expect(wins.length, 'layout_file: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/admin/preview/TKN' } } })).toBe('/admin/preview/TKN');
    }
  });

  /**
   * extension_overlay 진입점 — 서버가 내준 관리자 셸 주소를 그대로 연다.
   *
   * @scenario template_type=admin, entry=extension_overlay
   * @effects preview_opens_server_preview_url
   */
  it('extension_overlay: 서버가 내준 관리자 셸 주소를 그대로 연다', () => {
    const wins = entryWindows('extension_overlay');
    expect(wins.length, 'extension_overlay: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/admin/preview/TKN' } } })).toBe('/admin/preview/TKN');
    }
  });

  /**
   * extension_picker 진입점 — 서버가 내준 관리자 셸 주소를 그대로 연다.
   *
   * @scenario template_type=admin, entry=extension_picker
   * @effects preview_opens_server_preview_url
   */
  it('extension_picker: 서버가 내준 관리자 셸 주소를 그대로 연다', () => {
    const wins = entryWindows('extension_picker');
    expect(wins.length, 'extension_picker: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/admin/preview/TKN' } } })).toBe('/admin/preview/TKN');
    }
  });

  /**
   * layout_file 진입점 — 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다.
   *
   * @scenario template_type=user, entry=layout_file
   * @effects preview_opens_server_preview_url
   */
  it('layout_file: 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다', () => {
    const wins = entryWindows('layout_file');
    expect(wins.length, 'layout_file: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/preview/TKN' } } })).toBe('/preview/TKN');
    }
  });

  /**
   * extension_overlay 진입점 — 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다.
   *
   * @scenario template_type=user, entry=extension_overlay
   * @effects preview_opens_server_preview_url
   */
  it('extension_overlay: 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다', () => {
    const wins = entryWindows('extension_overlay');
    expect(wins.length, 'extension_overlay: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/preview/TKN' } } })).toBe('/preview/TKN');
    }
  });

  /**
   * extension_picker 진입점 — 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다.
   *
   * @scenario template_type=user, entry=extension_picker
   * @effects preview_opens_server_preview_url
   */
  it('extension_picker: 사용자 템플릿은 서버가 내준 사용자 셸 주소를 연다', () => {
    const wins = entryWindows('extension_picker');
    expect(wins.length, 'extension_picker: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN', preview_url: '/preview/TKN' } } })).toBe('/preview/TKN');
    }
  });

  /**
   * layout_file 진입점 — 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다.
   *
   * @scenario template_type=unknown, entry=layout_file
   * @effects preview_falls_back_to_token_path
   */
  it('layout_file: 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다', () => {
    const wins = entryWindows('layout_file');
    expect(wins.length, 'layout_file: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN' } } })).toBe('/preview/TKN');
    }
  });

  /**
   * extension_overlay 진입점 — 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다.
   *
   * @scenario template_type=unknown, entry=extension_overlay
   * @effects preview_falls_back_to_token_path
   */
  it('extension_overlay: 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다', () => {
    const wins = entryWindows('extension_overlay');
    expect(wins.length, 'extension_overlay: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN' } } })).toBe('/preview/TKN');
    }
  });

  /**
   * extension_picker 진입점 — 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다.
   *
   * @scenario template_type=unknown, entry=extension_picker
   * @effects preview_falls_back_to_token_path
   */
  it('extension_picker: 응답에 주소가 없으면 토큰으로 사용자 셸 주소를 만든다', () => {
    const wins = entryWindows('extension_picker');
    expect(wins.length, 'extension_picker: 미리보기 openWindow 가 없습니다.').toBeGreaterThan(0);
    for (const win of wins) {
      expect(evalBinding(win.params?.path, { response: { data: { token: 'TKN' } } })).toBe('/preview/TKN');
    }
  });
});
