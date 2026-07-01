/**
 * @file mp12ApiConfigSettings.test.tsx
 * @description MP12 계산 API 고급 설정(메서드/인증/매핑/응답형식/테스트호출) 레이아웃 회귀 테스트
 *
 * 검증 항목:
 * - method/auth/response_type 옵션 computed 가 백엔드 데이터소스 참조 (프론트 하드코딩 금지)
 * - 인증 헤더명/토큰이 auth_type 에 따라 조건부 노출
 * - 응답 경로가 response_type=json 일 때만 노출
 * - 요청 필드 매핑 input 이 체크된 후보만 노출 + updateApiFieldMap 핸들러 연결
 * - 테스트 호출 버튼이 testShippingApi 핸들러 연결 + endpoint 없으면 비활성
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

describe('MP12 계산 API 고급 설정', () => {
  it('method/auth/response_type 옵션 computed 가 백엔드 데이터소스를 참조한다 (하드코딩 금지)', () => {
    const c = form.computed ?? {};
    for (const [key, ds] of [
      ['apiHttpMethodOptions', 'api_http_methods'],
      ['apiAuthTypeOptions', 'api_auth_types'],
      ['apiResponseTypeOptions', 'api_response_types'],
    ]) {
      const expr = c[key] ?? '';
      expect(expr).toContain('ecommerce_settings');
      expect(expr).toContain(ds);
      // enum 값/라벨을 프론트에 하드코딩하지 않음
      expect(expr).not.toContain('$t(');
    }
  });

  it('HTTP 메서드/인증 Select 가 updateApiConfigField 핸들러로 연결된다', () => {
    const configUpdaters = collectNodes(
      chargePartial,
      (n) =>
        Array.isArray(n.actions) &&
        n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.updateApiConfigField'),
    );
    // http_method, auth_type, response_type, response_path, auth_header_name, auth_token = 최소 5+
    expect(configUpdaters.length).toBeGreaterThanOrEqual(5);
  });

  it('인증 헤더명은 auth_type=custom_header 일 때만 노출된다', () => {
    const headerBlock = collectNodes(chargePartial, (n) => n.id === 'field_api_auth_header_name');
    expect(headerBlock.length).toBe(1);
    expect(String(headerBlock[0].if)).toContain("=== 'custom_header'");
  });

  it('인증 토큰은 auth_type 이 none 이 아닐 때만 노출되고 password 타입이다', () => {
    const tokenBlock = collectNodes(chargePartial, (n) => n.id === 'field_api_auth_token');
    expect(tokenBlock.length).toBe(1);
    expect(String(tokenBlock[0].if)).toContain("!== 'none'");

    const pwInput = collectNodes(tokenBlock[0], (n) => n.name === 'Input' && n.props?.type === 'password');
    expect(pwInput.length).toBe(1);
  });

  it('응답 경로는 response_type=json 일 때만 노출된다', () => {
    const pathBlock = collectNodes(chargePartial, (n) => n.id === 'field_api_response_path');
    expect(pathBlock.length).toBe(1);
    expect(String(pathBlock[0].if)).toContain("=== 'json'");
  });

  it('요청 필드 매핑 input 이 체크된 후보만 노출하고 updateApiFieldMap 으로 연결된다', () => {
    const mapIterations = collectNodes(
      chargePartial,
      (n) => n.iteration && String(n.iteration.source).includes('api_request_fields') && String(n.iteration.source).includes('filter'),
    );
    expect(mapIterations.length).toBeGreaterThanOrEqual(1);

    const mapUpdaters = collectNodes(
      chargePartial,
      (n) =>
        Array.isArray(n.actions) &&
        n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.updateApiFieldMap'),
    );
    expect(mapUpdaters.length).toBeGreaterThanOrEqual(1);
  });

  it('테스트 호출 버튼이 testShippingApi 핸들러 연결 + endpoint 없으면 비활성', () => {
    const testBlock = collectNodes(chargePartial, (n) => n.id === 'field_api_test_call');
    expect(testBlock.length).toBe(1);

    const buttons = collectNodes(
      testBlock[0],
      (n) =>
        n.name === 'Button' &&
        Array.isArray(n.actions) &&
        n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.testShippingApi'),
    );
    expect(buttons.length).toBe(1);
    // endpoint 없으면 disabled
    expect(String(buttons[0].props?.disabled)).toContain('api_endpoint');
  });
});
