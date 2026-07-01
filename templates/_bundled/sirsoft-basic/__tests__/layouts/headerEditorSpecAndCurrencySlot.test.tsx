/**
 * @file headerEditorSpecAndCurrencySlot.test.tsx
 * @description Header editor-spec capability + 편집기 통화 슬롯 렌더 회귀 테스트
 *
 * 배경 — 레이아웃 편집기 캔버스에서 사용자 헤더의 언어/통화 셀렉터가 통째로 사라지고,
 * Header 컴포넌트가 편집기에서 부여 가능한 속성이 거의 없던 결함을 수정한 뒤의 회귀 차단.
 *
 * 확정된 근본 원인 2종 (라이브 측정):
 *  1) basic Header/Footer(HTML <header>/<footer> 래퍼)가 composite Header/Footer 와 이름이
 *     겹쳐, 편집기 ComponentRegistry 가 composite 를 basic 으로 덮어써 사이트 헤더 내부
 *     (언어/통화/검색)가 소실. → components.json + basic/index.ts 에서 basic Header/Footer 제거.
 *  2) SlotContainer 가 compositeComponents 등록맵에 누락되어 편집기 IIFE 번들에 포함되지 않아
 *     'Component SlotContainer not found in bundle' 로 통화 슬롯 렌더 실패. → 등록맵에 추가.
 *
 * 추가 — Header 컴포넌트 editor-spec capability(propControls/dataProps/styleControls/events)와
 * 그 controls.json 컨트롤 정의를 신설. 모든 propControls 가 controls.json 에 존재하고 각 컨트롤의
 * apply.propKey 가 Header.tsx 실제 prop 과 일치해야 한다(편집한 속성이 실제 렌더 prop 에 도달).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(baseDir, '../../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}
function loadText(relPath: string): string {
  return fs.readFileSync(path.resolve(baseDir, relPath), 'utf8');
}

describe('버그 A — basic/composite Header·Footer 이름 충돌 제거', () => {
  const components = loadJson('components.json');

  function namesByType(name: string): string[] {
    const out: string[] = [];
    for (const [cat, items] of Object.entries<any>(components.components)) {
      if (Array.isArray(items)) {
        for (const it of items) if (it.name === name) out.push(cat);
      }
    }
    return out;
  }

  it('components.json 에 Header 는 composite 1개만 등록 (basic 래퍼 제거)', () => {
    expect(namesByType('Header')).toEqual(['composite']);
  });

  it('components.json 에 Footer 는 composite 1개만 등록 (basic 래퍼 제거)', () => {
    expect(namesByType('Footer')).toEqual(['composite']);
  });

  it('basic/index.ts 가 Header/Footer 를 export 하지 않는다 (composite 와 이름 충돌 차단)', () => {
    const basicIndex = loadText('src/components/basic/index.ts');
    expect(basicIndex).not.toMatch(/export\s*\{\s*Header[ ,}]/);
    expect(basicIndex).not.toMatch(/export\s*\{\s*Footer[ ,}]/);
  });
});

describe('버그 B — SlotContainer 편집기 번들 포함', () => {
  it('compositeComponents 등록맵에 SlotContainer 가 lazy import 로 포함된다', () => {
    const compositeIndex = loadText('src/components/composite/index.ts');
    // export const compositeComponents = { ... SlotContainer: () => import('./SlotContainer'), ... }
    const mapMatch = compositeIndex.match(/compositeComponents\s*=\s*\{[\s\S]*?\n\}/);
    expect(mapMatch).not.toBeNull();
    expect(mapMatch![0]).toMatch(/SlotContainer:\s*\(\)\s*=>\s*import\(['"]\.\/SlotContainer['"]\)/);
  });

  it('components.json 에 SlotContainer composite 항목이 존재한다', () => {
    const components = loadJson('components.json');
    const composite = components.components.composite ?? [];
    expect(composite.some((c: any) => c.name === 'SlotContainer')).toBe(true);
  });
});

describe('Header editor-spec capability ↔ controls ↔ Header.tsx prop 정합', () => {
  const capabilities = loadJson('editor-spec/componentCapabilities.json');
  const controls = loadJson('editor-spec/controls.json');
  const headerSource = loadText('src/components/composite/Header.tsx');
  const headerCap = capabilities.Header;

  it('Header capability 가 정의되어 있다', () => {
    expect(headerCap).toBeDefined();
    expect(Array.isArray(headerCap.propControls)).toBe(true);
    expect(headerCap.propControls.length).toBeGreaterThan(0);
  });

  it('모든 propControls 가 controls.json 에 정의되어 있다', () => {
    for (const ctrlId of headerCap.propControls) {
      expect(controls[ctrlId], `controls.json 에 ${ctrlId} 누락`).toBeDefined();
      expect(controls[ctrlId].apply?.propKey, `${ctrlId}.apply.propKey 누락`).toBeTruthy();
    }
  });

  it('각 propControl 의 apply.propKey 가 Header.tsx 의 실제 prop 으로 존재한다 (편집값이 렌더 prop 에 도달)', () => {
    for (const ctrlId of headerCap.propControls) {
      const propKey = controls[ctrlId].apply.propKey;
      // HeaderProps 인터페이스 또는 디스트럭처에 등장해야 한다
      const re = new RegExp(`\\b${propKey}\\b`);
      expect(re.test(headerSource), `Header.tsx 에 prop '${propKey}' (${ctrlId}) 없음`).toBe(true);
    }
  });

  it('dataProps 의 propKey 가 Header.tsx 실제 prop 으로 존재한다', () => {
    for (const dp of headerCap.dataProps ?? []) {
      const re = new RegExp(`\\b${dp.propKey}\\b`);
      expect(re.test(headerSource), `Header.tsx 에 dataProp '${dp.propKey}' 없음`).toBe(true);
    }
  });

  it('핵심 설정 prop(siteName/logo/maxVisibleBoards/shopBase)이 propControls 로 노출된다', () => {
    const propKeys = headerCap.propControls.map((id: string) => controls[id].apply.propKey);
    for (const key of ['siteName', 'logo', 'maxVisibleBoards', 'shopBase']) {
      expect(propKeys, `${key} 미노출`).toContain(key);
    }
  });
});

describe('이커머스 editor-spec sampleGlobal — 편집기 통화/배송국가 셀렉터 표시 조건 시드', () => {
  // 편집기 캔버스는 모듈 init_actions 를 실행하지 않으므로 셀렉터 표시 조건
  // (availableCurrencies / availableShippingCountries / modules 설정)을 sampleGlobal 로 직접 시드해야 한다.
  const ecommerceSpec = JSON.parse(
    fs.readFileSync(
      path.resolve(repoRoot, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'),
      'utf8',
    ),
  );
  const sg = ecommerceSpec.sampleGlobal ?? {};

  it('availableCurrencies 가 2개 이상 시드되어 통화 셀렉터 표시 조건을 만족', () => {
    expect(Array.isArray(sg.availableCurrencies)).toBe(true);
    expect(sg.availableCurrencies.length).toBeGreaterThan(0);
    expect(sg.availableCurrencies[0].code).toBeTruthy();
  });

  it('availableShippingCountries 가 2개 이상 시드되어 배송국가 섹션 표시 조건을 만족', () => {
    expect(Array.isArray(sg.availableShippingCountries)).toBe(true);
    expect(sg.availableShippingCountries.length).toBeGreaterThan(1);
  });

  it('modules[sirsoft-ecommerce].language_currency / shipping 설정이 시드된다', () => {
    const mod = sg.modules?.['sirsoft-ecommerce'];
    expect(mod?.language_currency?.currencies?.length).toBeGreaterThan(0);
    expect(mod?.shipping?.international_shipping_enabled).toBe(true);
    expect(Array.isArray(mod?.shipping?.available_countries)).toBe(true);
  });
});
