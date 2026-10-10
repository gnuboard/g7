/**
 * @file admin-extension-text-resolution.test.ts
 * @description 확장 관리 창·서랍의 문구가 키 원문으로 남지 않고, 미리보기 판정이 새 파일과 섞이지 않는다
 *
 * 1) `$t:defer:` 는 반복(iteration·셀 템플릿) 서브트리에서만 항목 문맥과 함께 해소된다. 반복 밖 노드는
 *    렌더러가 그 토큰을 건너뛰어 원문이 그대로 남는다. 브라우저 실측에서 모듈 상세 창 아이콘의 대체 글이
 *    `$t:defer:extensions.icon.alt|name=게시판` 으로, 수동 설치 미리보기의 「아이콘 포함」 안내가
 *    `$t:defer:extensions.manifest_preview.icon_present|path=assets/icon.svg` 로 보였다.
 * 2) 수동 설치 미리보기 서랍은 검사 버튼을 누를 때만 직전 결과를 지웠다. 새 파일을 고른 순간에는 앞 파일의
 *    판정이 새 파일 이름 바로 아래 남아, 아직 검사하지 않은 새 파일의 결과처럼 읽혔다(브라우저 실측).
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

type LayoutNode = Record<string, unknown>;

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');

/** 레이아웃 디렉터리의 JSON 파일(조각 포함)을 모두 모은다. */
function layoutFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return layoutFiles(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

/** 반복 서브트리 밖에 놓인 `$t:defer:` 문자열을 모은다. 주석(comment)은 제외한다. */
function deferOutsideIteration(node: unknown, inIteration: boolean, found: string[]): string[] {
  if (Array.isArray(node)) {
    node.forEach((child) => deferOutsideIteration(child, inIteration, found));
    return found;
  }
  if (node && typeof node === 'object') {
    const obj = node as LayoutNode;
    const here = inIteration || obj.iteration !== undefined;
    Object.entries(obj).forEach(([key, value]) => {
      if (key === 'comment') return;
      const childScope = here || key === 'cellChildren' || key === 'expandChildren';
      deferOutsideIteration(value, childScope, found);
    });
    return found;
  }
  if (typeof node === 'string' && !inIteration && node.includes('$t:defer:')) {
    found.push(node);
  }
  return found;
}

const DRAWERS = ['admin_module_list', 'admin_plugin_list', 'admin_template_list', 'admin_language_pack_list'].map(
  (dir) => ({
    dir,
    layout: JSON.parse(
      fs.readFileSync(path.join(LAYOUT_ROOT, 'partials', dir, '_drawer_manifest_preview.json'), 'utf-8'),
    ) as LayoutNode,
  }),
);

/** 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: LayoutNode) => boolean, found: LayoutNode[] = []): LayoutNode[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    const obj = node as LayoutNode;
    if (match(obj)) found.push(obj);
    Object.values(obj).forEach((value) => collect(value, match, found));
  }
  return found;
}

describe('확장 관리 — 문구 해소 범위', () => {
  // @scenario screen=modules, view=gallery, icon_state=present, install_state=active, device=desktop_1440, theme=light
  // @effects extension_texts_resolve_outside_iteration
  it('관리자 템플릿 레이아웃 어디에도 반복 밖 `$t:defer:` 가 없다', () => {
    const offenders = layoutFiles(LAYOUT_ROOT).flatMap((file) => {
      const layout = JSON.parse(fs.readFileSync(file, 'utf-8'));
      return deferOutsideIteration(layout, false, []).map(
        (value) => `${path.relative(LAYOUT_ROOT, file)} → ${value.slice(0, 80)}`,
      );
    });

    expect(offenders).toEqual([]);
  });
});

describe('수동 설치 미리보기 — 새 파일을 고르면 직전 판정을 비운다', () => {
  // @scenario screen=modules, view=gallery, icon_state=present, install_state=uninstalled, device=desktop_1440, theme=light
  // @effects preview_clears_previous_result_on_file_change
  it.each(DRAWERS)('$dir: 파일 선택 변경 핸들러가 결과를 null 로 비운다', ({ layout }) => {
    const input = collect(layout, (n) => n.name === 'FileInput')[0];
    expect(input, 'FileInput 을 찾지 못했다').toBeDefined();

    const writes = collect(input.actions, (n) => n.handler === 'setState');
    const resultKey = Object.keys((writes[0]?.params ?? {}) as LayoutNode).find((key) => /PreviewResult$/.test(key));

    expect(resultKey, '파일을 고를 때 *PreviewResult 를 함께 쓰지 않는다').toBeDefined();
    expect((writes[0].params as LayoutNode)[resultKey as string]).toBeNull();
  });
});
