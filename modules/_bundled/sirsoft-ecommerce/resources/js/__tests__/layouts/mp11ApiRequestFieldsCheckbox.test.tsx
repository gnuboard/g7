/**
 * @file mp11ApiRequestFieldsCheckbox.test.tsx
 * @description A13 W3 계산 API 참고 필드 후보 선택 UI 레이아웃 회귀 테스트
 *
 * 검증 항목:
 * - apiRequestFieldOptions computed 가 백엔드 SSoT 데이터소스를 참조 (프론트 하드코딩 금지)
 * - field_api_request_fields 가 자유텍스트 Input → 후보 체크박스 iteration 으로 전환
 * - toggleApiRequestField 핸들러 연결
 * - 자유텍스트 add/update/remove 핸들러 미사용 (silent drop 차단)
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutsDir = path.resolve(__dirname, '../../../../resources/layouts/admin');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(layoutsDir, relPath), 'utf8'));
}

function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (!Array.isArray(node) && predicate(node)) acc.push(node);
  for (const key of Object.keys(node)) collectNodes(node[key], predicate, acc);
  return acc;
}

const form = loadJson('admin_ecommerce_shipping_policy_form.json');
const chargePartial = loadJson('partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json');

describe('A13 W3 계산 API 참고 필드 후보 선택 UI', () => {
  it('apiRequestFieldOptions computed 가 백엔드 SSoT 데이터소스를 참조한다 (프론트 하드코딩 금지)', () => {
    const expr = form.computed?.apiRequestFieldOptions ?? '';
    // 후보 목록은 백엔드 enum(ShippingApiRequestField)이 settings 응답으로 내려준다.
    expect(expr).toContain('ecommerce_settings');
    expect(expr).toContain('shipping');
    expect(expr).toContain('api_request_fields');
    // 후보 값/라벨을 프론트에 하드코딩하면 안 된다 (silent drop + 키 원문 노출 회귀).
    expect(expr).not.toContain("value: 'policy_id'");
    expect(expr).not.toContain('$t(');
  });

  it('field_api_request_fields 가 후보 체크박스 iteration 으로 렌더된다', () => {
    const block = collectNodes(chargePartial, (n) => n.id === 'field_api_request_fields');
    expect(block.length).toBe(1);

    const checkboxIterations = collectNodes(
      block[0],
      (n) => n.iteration && String(n.iteration.source).includes('apiRequestFieldOptions'),
    );
    expect(checkboxIterations.length).toBeGreaterThanOrEqual(1);
  });

  it('체크박스 change 가 toggleApiRequestField 핸들러로 연결된다', () => {
    const toggles = collectNodes(
      chargePartial,
      (n) =>
        Array.isArray(n.actions) &&
        n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.toggleApiRequestField'),
    );
    expect(toggles.length).toBeGreaterThanOrEqual(1);
  });

  it('자유텍스트 add/update/remove 핸들러는 더 이상 사용되지 않는다 (silent drop 차단)', () => {
    const json = JSON.stringify(chargePartial);
    expect(json).not.toContain('sirsoft-ecommerce.addApiRequestField');
    expect(json).not.toContain('sirsoft-ecommerce.updateApiRequestField');
    expect(json).not.toContain('sirsoft-ecommerce.removeApiRequestField');
  });
});
