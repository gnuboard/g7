/**
 * @file component-manifest-integrity.test.ts
 * @description 컴포넌트 manifest(components.json) 정합성 검증
 *
 * 배경(이슈 #44): 마일리지 내역 화면의 PC 테이블이 Thead/Tbody 를 사용하는데
 * components.json manifest 에 Thead/Tbody/Tr/Th/Td 가 누락되어 ComponentRegistry 에
 * 등록되지 않아("컴포넌트를 찾을 수 없습니다") 테이블 영역이 빈 화면으로 렌더된 회귀.
 *
 * 코어 ComponentRegistry.registerComponentsFromManifest 는 manifest 의 components 배열에
 * 선언된 name 만 module[name] 으로 조회해 등록한다. 즉 src 에서 export 되어도
 * manifest 에 없으면 미등록 → 런타임 렌더 실패.
 */

import { describe, it, expect } from 'vitest';
import manifest from '../components.json';

type ComponentMeta = { name: string; type: string };

const allComponents: ComponentMeta[] = [
  ...(manifest.components.basic ?? []),
  ...(manifest.components.composite ?? []),
  ...(manifest.components.layout ?? []),
] as ComponentMeta[];

const names = new Set(allComponents.map((c) => c.name));

describe('components.json manifest 정합성', () => {
  it('테이블 계열 컴포넌트(Table/Thead/Tbody/Tr/Th/Td)가 모두 manifest 에 등록되어야 한다 (회귀 차단)', () => {
    // Table 만 있고 하위 요소(Thead/Tbody/Tr/Th/Td)가 누락되면 테이블 렌더가 깨진다.
    for (const tableComponent of ['Table', 'Thead', 'Tbody', 'Tr', 'Th', 'Td']) {
      expect(names.has(tableComponent), `${tableComponent} 가 components.json 에 선언되어야 함`).toBe(true);
    }
  });

  it('모든 컴포넌트 name 은 PascalCase 여야 한다 (코어가 module[name] 으로 번들 export 와 매칭)', () => {
    // 번들 export 는 PascalCase(Thead 등). manifest name 이 소문자면 module["thead"] 조회 실패.
    const invalid = allComponents.filter((c) => !/^[A-Z]/.test(c.name));
    expect(invalid.map((c) => c.name)).toEqual([]);
  });

  it('각 컴포넌트 메타는 name 과 type 을 갖는다 (코어 validateManifest 통과 조건)', () => {
    for (const c of allComponents) {
      expect(typeof c.name).toBe('string');
      expect(c.name.length).toBeGreaterThan(0);
      expect(['basic', 'composite', 'layout']).toContain(c.type);
    }
  });

  it('테이블 계열 컴포넌트는 manifest 에 중복 선언되지 않아야 한다', () => {
    // 이번 보강 대상(Table 계열)에 한정한 중복 검사. 코어 registerComponent 는 중복 시 덮어쓰므로
    // 같은 name 이 두 번 선언되면 의도치 않은 메타로 덮일 수 있다.
    const tableNames = ['Table', 'Thead', 'Tbody', 'Tr', 'Th', 'Td'];
    for (const name of tableNames) {
      const count = allComponents.filter((c) => c.name === name).length;
      expect(count, `${name} 가 manifest 에 정확히 1번 선언되어야 함`).toBe(1);
    }
  });
});
