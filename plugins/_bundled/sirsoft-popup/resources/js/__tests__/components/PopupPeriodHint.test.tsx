/**
 * @file PopupPeriodHint.test.tsx
 * @description 저장 후 상태 안내 — 사이트 시간대 벽시계로 예약·노출중·종료·미완성을 판정하고, 시계를 받기 전에는 불러오는 중을 보인다.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { PopupPeriodHint } from '../../components/composite/PopupPeriodHint';
import { periodStatus } from '../../support/editor';
import { installCore } from './core';

/** 서버 시각 — 서울 2026-12-01 09:00:00 */
const SERVER_NOW = '2026-12-01T00:00:00Z';

/** 기준 시각(epoch 밀리초) */
const INSTANT = Date.parse(SERVER_NOW);

describe('PopupPeriodHint', () => {
  beforeEach(() => {
    installCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  // @scenario surface=period_hint_status_boundaries
  it('사이트 시간대 벽시계로 판정한다 — 시작 정각부터 노출, 종료 정각까지 노출', () => {
    const tz = 'Asia/Seoul';

    expect(periodStatus('range', true, '2026-12-01T09:01', '2026-12-31T23:59', tz, INSTANT)).toBe('scheduled');
    expect(periodStatus('range', true, '2026-12-01T09:00', '2026-12-31T23:59', tz, INSTANT)).toBe('active');
    expect(periodStatus('range', true, '2026-11-01T00:00', '2026-12-01T09:00', tz, INSTANT)).toBe('active');
    expect(periodStatus('range', true, '2026-11-01T00:00', '2026-12-01T08:59', tz, INSTANT)).toBe('ended');
    expect(periodStatus('range', true, '', '2026-12-31T23:59', tz, INSTANT)).toBe('incomplete');
    expect(periodStatus('always', true, '', '', tz, INSTANT)).toBe('active');
    expect(periodStatus('range', false, '2026-12-01T09:00', '2026-12-31T23:59', tz, INSTANT)).toBe('inactive');

    const { container } = render(<PopupPeriodHint period="range" start="2026-12-01T09:01" end="2026-12-31T23:59" timezone={tz} serverNow={SERVER_NOW} />);
    expect(container.querySelector('[data-period-status]')?.getAttribute('data-period-status')).toBe('scheduled');
    expect(container.textContent).toContain('Asia/Seoul');
  });

  // @scenario surface=period_hint_clock_loading
  it('사이트 시간대·서버 시각을 받기 전에는 불러오는 중 문구만 보인다', () => {
    const { container } = render(<PopupPeriodHint period="range" start="2026-12-01T09:00" end="2026-12-31T23:59" />);

    expect(container.querySelector('[data-period-status]')).toBeNull();
    expect(container.textContent).not.toContain('sirsoft-popup.');
    expect(container.textContent?.length).toBeGreaterThan(0);
  });
});
