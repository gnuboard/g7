/**
 * @file currencyBaseUnitField.test.tsx
 * @description 환율 설정 화면 base_unit(기준 단위) 입력칸 렌더 회귀 (MP08-3 방향 B)
 *
 * 배경:
 * - 환산 공식이 통화별 base_unit 을 분모로 쓰도록 바뀌었고, 운영자가 통화별 base_unit 을
 *   설정 화면에서 입력·편집할 수 있어야 한다(설정 UI). 데이터/공식만 만들고 UI 누락 금지.
 *
 * 회귀 차단:
 * - 카드/테이블 모두 각 통화 행에 base_unit 입력 Input(name=...currencies.{idx}.base_unit) 존재.
 * - base_unit 은 기본 통화 행에도 표시(기본 통화의 base_unit 이 환산 분모이므로) — if 게이트로 가려지지 않음.
 * - 환율 입력(exchange_rate)도 유지(비회귀).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const dir = path.resolve(
  __dirname,
  '../../../layouts/admin/partials/admin_ecommerce_settings'
);
const cardsText = fs.readFileSync(path.join(dir, '_currency_exchange_cards.json'), 'utf8');
const tableText = fs.readFileSync(path.join(dir, '_currency_exchange_table.json'), 'utf8');
const cards = JSON.parse(cardsText);
const table = JSON.parse(tableText);

/** 노드 트리에서 조건(predicate) 만족 노드를 모두 수집 */
function collect(node: unknown, pred: (n: any) => boolean, acc: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, pred, acc));
  } else if (node && typeof node === 'object') {
    if (pred(node)) acc.push(node);
    Object.values(node as Record<string, unknown>).forEach((v) => collect(v, pred, acc));
  }
  return acc;
}

const isBaseUnitInput = (n: any) =>
  n?.name === 'Input' &&
  typeof n?.props?.name === 'string' &&
  n.props.name.includes('.base_unit');

const isExchangeRateInput = (n: any) =>
  n?.name === 'Input' &&
  typeof n?.props?.name === 'string' &&
  n.props.name.includes('.exchange_rate');

describe('MP08-3 — 환율 설정 base_unit 입력칸 렌더', () => {
  it('카드 뷰에 base_unit 입력 Input 이 존재한다', () => {
    const inputs = collect(cards, isBaseUnitInput);
    expect(inputs.length).toBeGreaterThan(0);
    // 인덱스 바인딩 동적 name (반복 렌더 행마다 입력)
    expect(JSON.stringify(inputs)).toContain('currencies.');
  });

  it('테이블 뷰에 base_unit 입력 Input 이 존재한다', () => {
    const inputs = collect(table, isBaseUnitInput);
    expect(inputs.length).toBeGreaterThan(0);
    expect(JSON.stringify(inputs)).toContain('currencies.');
  });

  it('base_unit 입력은 기본 통화 행을 가리는 if 게이트로 감싸이지 않는다', () => {
    // exchange_rate 는 비-기본 통화 한정(if 게이트) 이지만 base_unit 은 전 행 표시여야 한다.
    // base_unit 입력을 직접 감싼 부모에 "currency.code !== default" if 가 없어야 함.
    // 카드: base_unit form-group 에 if 부재 확인
    const baseUnitGroups = collect(
      cards,
      (n) =>
        typeof n?.props?.className === 'string' &&
        collect(n, isBaseUnitInput).length > 0 &&
        n?.if !== undefined &&
        typeof n.if === 'string' &&
        n.if.includes('!==') &&
        n.if.includes('default_currency')
    );
    expect(baseUnitGroups.length).toBe(0);
  });

  it('환율(exchange_rate) 입력은 유지된다(비회귀)', () => {
    expect(collect(cards, isExchangeRateInput).length).toBeGreaterThan(0);
    expect(collect(table, isExchangeRateInput).length).toBeGreaterThan(0);
  });

  it('base_unit 라벨/안내 lang 키를 참조한다', () => {
    expect(cardsText).toContain('exchange_settings.base_unit');
    expect(tableText).toContain('exchange_settings.base_unit');
  });
});

describe('MP08-3 — 환율 설정 422 필드별 에러 표시 (코어 환경설정 일관)', () => {
  // 표준 패턴: className 에 _local.errors?.['...base_unit'] ? input-error, if/text 로 하단 메시지
  const refsBaseUnitError = (text: string) =>
    /_local\.errors\?\.\[[^\]]*base_unit[^\]]*\]/.test(text);
  const refsExchangeRateError = (text: string) =>
    /_local\.errors\?\.\[[^\]]*exchange_rate[^\]]*\]/.test(text);

  it('카드: base_unit 입력칸이 _local.errors 를 참조해 에러 강조/메시지를 표시한다', () => {
    expect(refsBaseUnitError(cardsText)).toBe(true);
  });

  it('테이블: base_unit 입력칸이 _local.errors 를 참조해 에러 강조/메시지를 표시한다', () => {
    expect(refsBaseUnitError(tableText)).toBe(true);
  });

  it('카드: 환율(exchange_rate) 입력칸도 _local.errors 를 참조한다(화면 일관)', () => {
    expect(refsExchangeRateError(cardsText)).toBe(true);
  });

  it('테이블: 환율(exchange_rate) 입력칸도 _local.errors 를 참조한다(화면 일관)', () => {
    expect(refsExchangeRateError(tableText)).toBe(true);
  });

  it('input-error 클래스 패턴(SEO 탭과 동일)을 사용한다', () => {
    expect(cardsText).toContain('input-error');
    expect(tableText).toContain('input-error');
  });
});
