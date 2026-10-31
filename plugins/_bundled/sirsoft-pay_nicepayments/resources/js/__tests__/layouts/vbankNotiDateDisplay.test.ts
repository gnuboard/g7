/**
 * @file vbankNotiDateDisplay.test.ts
 * @description 주문 상세 › 결제 조회의 가상계좌 입금 통보 시각 표시 테스트
 *
 * 입금 통보 요약(첫·마지막 통보 수신, 입금 확인, 입금 취소)과 통보 이력의 시각 칸은 엔진이 제공하는
 * 날짜 표시로 그려야 한다. 엔진에 없는 `$formatDate(...)` 를 부르면 식이 풀리지 않아 시각 대신 식
 * 원문이 그대로 나왔다(이슈 #695 병합 전수 검사에서 발견). 설치되는 조각 원본(JSON)과 저작본(.g7.html)을
 * 함께 보고, 엔진 바인딩으로 실제 문자열을 만들어 확인한다.
 *
 * 시나리오: tests/scenarios/vbank-noti-time-display.yaml
 */

import { readFileSync } from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { DataBindingEngine } from '../../../../../../../resources/js/core/template-engine/DataBindingEngine';
import extensionLayout from '../../../extensions/admin_order_payment_query.json';

const MARKUP_PATH = path.resolve(__dirname, '../../../extensions/admin_order_payment_query.g7.html');

const RECEIVED_AT = '2026-09-28T10:11:12+09:00';

/**
 * 레이아웃 트리에서 필드 이름을 담은 text 바인딩 하나를 찾습니다.
 *
 * @param node 레이아웃 노드
 * @param needle 바인딩 식에 들어 있어야 하는 경로 조각
 * @return 찾은 text 바인딩(없으면 undefined)
 */
function findTimeText(node: unknown, needle: string): string | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  if (typeof value.text === 'string' && value.text.startsWith('{{') && value.text.includes(needle)) {
    return value.text;
  }
  for (const child of Object.values(value)) {
    const found = findTimeText(child, needle);
    if (found !== undefined) {
      return found;
    }
  }

  return undefined;
}

/**
 * 브라우저 로컬 시각 기준의 기대 문자열을 만듭니다.
 *
 * @param iso ISO 8601 시각
 * @return YYYY-MM-DD HH:mm:ss 문자열
 */
function localDateTime(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/**
 * 조각의 바인딩을 엔진으로 풉니다.
 *
 * @param needle 찾을 바인딩 경로 조각
 * @param summary 입금 통보 요약 값
 * @param noti 통보 이력 한 행
 * @return 화면에 그려질 문자열
 */
function render(needle: string, summary: Record<string, unknown>, noti: Record<string, unknown> = {}): string {
  const text = findTimeText(extensionLayout, needle);
  expect(text, `${needle} 바인딩을 찾지 못함`).toBeDefined();

  return new DataBindingEngine().resolveBindings(text as string, {
    _local: { _vbankNotiData: { summary } },
    noti,
  });
}

describe('가상계좌 입금 통보 시각 표시', () => {
  /**
   * @effects no_undefined_engine_helper_in_fragment
   */
  it('조각 원본과 저작본 어디에도 엔진에 없는 $formatDate 가 없다', () => {
    expect(JSON.stringify(extensionLayout)).not.toContain('$formatDate');
    expect(readFileSync(MARKUP_PATH, 'utf8')).not.toContain('$formatDate');
  });

  /**
   * @scenario field=summary_first_received, value=present
   * @effects time_rendered_as_datetime
   */
  it('첫 통보 수신 시각을 YYYY-MM-DD HH:mm:ss 로 그린다', () => {
    expect(render('summary?.first_received_at', { first_received_at: RECEIVED_AT })).toBe(localDateTime(RECEIVED_AT));
  });

  /**
   * @scenario field=summary_last_received, value=present
   * @effects time_rendered_as_datetime
   */
  it('마지막 통보 수신 시각을 YYYY-MM-DD HH:mm:ss 로 그린다', () => {
    expect(render('summary?.last_received_at', { last_received_at: RECEIVED_AT })).toBe(localDateTime(RECEIVED_AT));
  });

  /**
   * @scenario field=summary_deposited, value=present
   * @effects time_rendered_as_datetime
   */
  it('입금 확인 시각을 YYYY-MM-DD HH:mm:ss 로 그린다', () => {
    expect(render('summary?.deposited_at', { deposited_at: RECEIVED_AT })).toBe(localDateTime(RECEIVED_AT));
  });

  /**
   * @scenario field=summary_cancelled, value=present
   * @effects time_rendered_as_datetime
   */
  it('입금 취소 시각을 YYYY-MM-DD HH:mm:ss 로 그린다', () => {
    expect(render('summary?.cancelled_at |', { cancelled_at: RECEIVED_AT })).toBe(localDateTime(RECEIVED_AT));
  });

  /**
   * @scenario field=history_received, value=present
   * @effects time_rendered_as_datetime
   */
  it('통보 이력의 수신 시각을 YYYY-MM-DD HH:mm:ss 로 그린다', () => {
    expect(render('noti.received_at', {}, { received_at: RECEIVED_AT })).toBe(localDateTime(RECEIVED_AT));
  });

  /**
   * @scenario field=summary_first_received, value=missing
   * @effects missing_summary_time_shows_dash
   */
  it('첫 통보 수신 시각이 없으면 「-」를 그린다', () => {
    expect(render('summary?.first_received_at', {})).toBe('-');
  });

  /**
   * @scenario field=summary_last_received, value=missing
   * @effects missing_summary_time_shows_dash
   */
  it('마지막 통보 수신 시각이 없으면 「-」를 그린다', () => {
    expect(render('summary?.last_received_at', {})).toBe('-');
  });

  /**
   * @scenario field=summary_deposited, value=missing
   * @effects missing_summary_time_shows_dash
   */
  it('입금 확인 시각이 없으면 「-」를 그린다', () => {
    expect(render('summary?.deposited_at', {})).toBe('-');
  });
});
