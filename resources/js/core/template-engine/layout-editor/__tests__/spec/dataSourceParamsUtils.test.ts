/**
 * dataSourceParamsUtils.test.ts — ①
 *
 * 데이터소스 요청 파라미터(params) 블럭 ↔ 객체 변환 라운드트립 검증.
 *
 * 시나리오: tests/scenarios/layout-editor-data-sources.yaml (params_block_roundtrip)
 *
 * @effects params_object_to_rows_preserves_key_order,
 *   rows_to_params_restores_number_boolean_null_literals,
 *   rows_to_params_keeps_expression_and_plain_as_string,
 *   rows_to_params_drops_empty_key_rows,
 *   nested_object_array_value_detected_for_raw_fallback,
 *   roundtrip_object_to_rows_to_object_is_lossless
 */

import { describe, expect, it } from 'vitest';
import {
  paramsToRows,
  rowsToParams,
  hasNestedParamValue,
} from '../../spec/dataSourceParamsUtils';

describe('dataSourceParamsUtils — paramsToRows', () => {
  it('객체를 키 순서 보존 행 배열로', () => {
    const rows = paramsToRows({ page: '{{query.page}}', per_page: 10, q: '' });
    expect(rows).toEqual([
      { key: 'page', value: '{{query.page}}' },
      { key: 'per_page', value: '10' },
      { key: 'q', value: '' },
    ]);
  });

  it('숫자/불린/null 값은 JSON 문자열 표현으로', () => {
    const rows = paramsToRows({ n: 5, b: true, z: null });
    expect(rows).toEqual([
      { key: 'n', value: '5' },
      { key: 'b', value: 'true' },
      { key: 'z', value: 'null' },
    ]);
  });

  it('null/비객체/배열 입력 → 빈 배열', () => {
    expect(paramsToRows(null)).toEqual([]);
    expect(paramsToRows(undefined)).toEqual([]);
    expect(paramsToRows('x')).toEqual([]);
    expect(paramsToRows([1, 2])).toEqual([]);
  });
});

describe('dataSourceParamsUtils — rowsToParams', () => {
  it('순수 숫자/불린/null 은 리터럴 타입으로 복원', () => {
    const obj = rowsToParams([
      { key: 'n', value: '10' },
      { key: 'f', value: '-3.5' },
      { key: 'b', value: 'true' },
      { key: 'bf', value: 'false' },
      { key: 'z', value: 'null' },
    ]);
    expect(obj).toEqual({ n: 10, f: -3.5, b: true, bf: false, z: null });
  });

  it('표현식/평문은 문자열로 보존(리터럴 승격 안 함)', () => {
    const obj = rowsToParams([
      { key: 'page', value: '{{query.page ?? 1}}' },
      { key: 'plain', value: 'hello' },
      { key: 'mixed', value: '회원 {{x}}' },
      { key: 'objlit', value: '{ "a": 1 }' },
    ]);
    expect(obj).toEqual({
      page: '{{query.page ?? 1}}',
      plain: 'hello',
      mixed: '회원 {{x}}',
      objlit: '{ "a": 1 }', // 객체 리터럴 문자열은 문자열 유지(중첩은 raw 폴백 영역)
    });
  });

  it('빈 키 행은 제외(무손실 입력 보조)', () => {
    const obj = rowsToParams([
      { key: '', value: 'orphan' },
      { key: '  ', value: 'x' },
      { key: 'keep', value: '1' },
    ]);
    expect(obj).toEqual({ keep: 1 });
  });

  it('특수문자 키(filters[0][field]) 보존', () => {
    const obj = rowsToParams([{ key: 'filters[0][field]', value: 'all' }]);
    expect(obj).toEqual({ 'filters[0][field]': 'all' });
  });
});

describe('dataSourceParamsUtils — hasNestedParamValue', () => {
  it('중첩 객체/배열 값 감지', () => {
    expect(hasNestedParamValue({ a: { b: 1 } })).toBe(true);
    expect(hasNestedParamValue({ a: [1, 2] })).toBe(true);
  });
  it('평탄 스칼라 값은 false', () => {
    expect(hasNestedParamValue({ a: '1', b: 2, c: true, d: null })).toBe(false);
    expect(hasNestedParamValue(null)).toBe(false);
  });
});

describe('dataSourceParamsUtils — 라운드트립 무손실', () => {
  it('객체 → 행 → 객체 (표현식·숫자·불린 혼합)', () => {
    const original = {
      page: '{{query.page}}',
      per_page: 10,
      is_notice: true,
      'filters[0][field]': 'all',
    };
    const restored = rowsToParams(paramsToRows(original));
    expect(restored).toEqual(original);
  });
});
