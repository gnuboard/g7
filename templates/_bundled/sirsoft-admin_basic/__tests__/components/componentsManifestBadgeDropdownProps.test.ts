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

/** 매니페스트 묶음(composite·layout …)에서 이름으로 항목의 prop 이름을 찾는다. */
function manifestPropsAnyGroup(name: string): string[] {
  for (const group of Object.values(manifest.components) as any[]) {
    const entry = Array.isArray(group) ? group.find((c: any) => c.name === name) : undefined;
    if (entry) return Object.keys(entry.props ?? {});
  }

  return [];
}

describe('components.json — 매니페스트에만 있고 소스가 받지 않는 prop 이 없다', () => {
  /**
   * CardGrid 열 수는 소스가 `gridColumns` 로 받는다. 매니페스트가 `columns` 로 적으면 편집기 속성 칸에서
   * 열 수를 바꿔도 카드 수가 바뀌지 않는다.
   *
   * @scenario case=CardGrid
   * @effects component_manifest_has_no_phantom_props
   */
  it('CardGrid 매니페스트 prop 이 모두 소스 props 에 있다', () => {
    const source = sourceProps('src/components/composite/CardGrid.tsx', 'CardGridProps');
    const phantom = manifestPropsAnyGroup('CardGrid').filter((p) => !source.includes(p) && !INFRA.has(p));

    expect(phantom).toEqual([]);
  });

  /**
   * Container 는 id·className·style·children 만 받는 래퍼다. 매니페스트의 flex·grid 배치 prop 과
   * 「sm·md·lg 구간별 열 수」 responsive 는 어디서도 해석되지 않아 편집기에 동작하지 않는 칸을 세운다.
   *
   * @scenario case=Container
   * @effects component_manifest_has_no_phantom_props
   */
  it('Container 매니페스트 prop 이 모두 소스 props 에 있다', () => {
    const source = sourceProps('src/components/layout/Container.tsx', 'ContainerProps');
    const phantom = manifestPropsAnyGroup('Container').filter((p) => !source.includes(p) && !INFRA.has(p));

    expect(phantom).toEqual([]);
  });
});
