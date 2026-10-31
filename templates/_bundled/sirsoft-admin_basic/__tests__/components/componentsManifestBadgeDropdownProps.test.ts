/**
 * components.json — Badge·SearchableDropdown 이 실제로 받는 props 를 매니페스트에 싣는다 (#599 validate P7)
 *
 * 레이아웃 작성자·레이아웃 편집기는 매니페스트만 읽는다. 소스 인터페이스에 prop 을 더하고
 * 매니페스트를 두면 작성자는 그 prop 이 없는 줄 알고(예: Badge `icon`), 편집기는 그 prop 을
 * 편집 칸으로 세우지 못한다. 기대 목록을 손으로 적지 않고 소스 인터페이스에서 파생한다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

const root = path.resolve(__dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'components.json'), 'utf-8'));

/** 편집기 주입·DOM 공통 속성 — 레이아웃이 직접 적는 prop 이 아니다 */
const INFRA = new Set(['id', 'editorAttrs', 'onChange', 'onSearch']);

/** 소스 `export interface {Name}Props { … }` 의 최상위 prop 이름을 뽑는다. */
function sourceProps(rel: string, iface: string): string[] {
  const src = fs.readFileSync(path.join(root, rel), 'utf-8');
  const body = src.match(new RegExp(`export interface ${iface}[^{]*\\{([\\s\\S]*?)\\n\\}`))?.[1] ?? '';
  const withoutComments = body.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');

  return [...withoutComments.matchAll(/^\s{2}(\w+)\??:/gm)].map((m) => m[1]).filter((p) => !INFRA.has(p));
}

function manifestProps(name: string): string[] {
  const entry = manifest.components.composite.find((c: any) => c.name === name);

  return Object.keys(entry?.props ?? {});
}

describe('components.json — Badge·SearchableDropdown props 동기', () => {
  /**
   * Badge 의 `icon`·`variant` 가 매니페스트에 있다.
   *
   * @scenario case=Badge
   * @effects component_manifest_lists_every_source_prop
   */
  it('Badge 소스 props 가 매니페스트에 모두 있다', () => {
    const missing = sourceProps('src/components/composite/Badge.tsx', 'BadgeProps').filter(
      (p) => !manifestProps('Badge').includes(p),
    );

    expect(missing).toEqual([]);
  });

  /**
   * SearchableDropdown 의 `selectedCountLabel` 이 매니페스트에 있다.
   *
   * @scenario case=SearchableDropdown
   * @effects component_manifest_lists_every_source_prop
   */
  it('SearchableDropdown 소스 props 가 매니페스트에 모두 있다', () => {
    const missing = sourceProps('src/components/composite/SearchableDropdown.tsx', 'SearchableDropdownProps').filter(
      (p) => !manifestProps('SearchableDropdown').includes(p),
    );

    expect(missing).toEqual([]);
  });
});
