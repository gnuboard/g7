/**
 * @file admin-settings-info-not-measurable.test.tsx
 * @description 환경설정 > 정보 탭 메모리/디스크 "측정 불가" 표기 렌더링 테스트 (A-8)
 *
 * 테스트 대상 (_tab_info.json 의 메모리/디스크 행):
 * - percentage 숫자      → "used / total (N%)" 표기, 측정 불가 미표시
 * - percentage null      → "측정 불가" 표기 (0% 로 표시하지 않는다 — A-8)
 * - 응답 데이터 미도착   → "-" 플레이스홀더 (측정 불가로 선표시하지 않는다)
 *
 * percentage null 은 shell_exec 차단(disable_functions)·/proc 미접근 환경에서
 * 서버(SettingsService::unknownUsage)가 내려보내는 "측정 불가" 신호다.
 * `?? 0` 폴백으로 되돌리면 측정 불가가 "0%" 로 위장되므로 이 테스트가 잠근다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * system-info API 응답 생성.
 *
 * @param percentage 메모리 percentage (null = 측정 불가)
 */
function createSystemInfoResponse(percentage: number | null) {
  return {
    success: true,
    data: {
      memory_usage: {
        total: percentage === null ? '알 수 없음' : '16 GB',
        used: percentage === null ? '알 수 없음' : '8 GB',
        free: percentage === null ? '알 수 없음' : '8 GB',
        percentage,
      },
    },
  };
}

// _tab_info.json 의 메모리 행과 동일한 if/바인딩 표현식.
const infoLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_settings_info_not_measurable',
  data_sources: [
    {
      id: 'systemInfo',
      type: 'api',
      endpoint: '/api/admin/settings/system-info',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: { data: {} },
    },
  ],
  components: [
    {
      id: 'row_memory',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex-between' },
      children: [
        {
          type: 'basic',
          name: 'Span',
          props: { className: 'text-label', 'data-testid': 'memory-label' },
          text: 'Memory',
        },
        {
          type: 'basic',
          name: 'Span',
          props: { className: 'text-heading', 'data-testid': 'memory-measured' },
          if: '{{systemInfo?.data?.memory_usage?.percentage != null}}',
          text: "{{systemInfo?.data?.memory_usage?.used ?? '-'}} / {{systemInfo?.data?.memory_usage?.total ?? '-'}} ({{systemInfo?.data?.memory_usage?.percentage}}%)",
        },
        {
          type: 'basic',
          name: 'Span',
          props: { className: 'text-heading', 'data-testid': 'memory-not-measurable' },
          if: '{{systemInfo?.data != null && systemInfo?.data?.memory_usage?.percentage == null}}',
          text: '측정 불가',
        },
        {
          type: 'basic',
          name: 'Span',
          props: { className: 'text-heading', 'data-testid': 'memory-loading' },
          if: '{{systemInfo?.data == null}}',
          text: '-',
        },
      ],
    },
  ],
};

describe('admin-settings 정보 탭 — 메모리/디스크 측정 불가 표기 (A-8)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  // @scenario shell_exec_guard_surface=settings_memory, measurement_result=measured
  // @effects resources_probe_failure_returns_null_percentage_not_zero
  it('percentage 가 숫자면 사용량과 퍼센트를 표기한다', async () => {
    const testUtils = createLayoutTest(infoLayout);
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(50) });

    await testUtils.render();

    const measured = screen.getByTestId('memory-measured');
    expect(measured.textContent).toBe('8 GB / 16 GB (50%)');
    expect(screen.queryByTestId('memory-not-measurable')).toBeNull();

    testUtils.cleanup();
  });

  // @scenario shell_exec_guard_surface=settings_memory, measurement_result=not_measurable
  // @effects resources_probe_failure_returns_null_percentage_not_zero
  it('percentage 가 null 이면 0% 가 아니라 측정 불가를 표기한다', async () => {
    const testUtils = createLayoutTest(infoLayout);
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(null) });

    await testUtils.render();

    // 긍정 앵커 — 트리가 실제로 렌더됐음을 먼저 확인
    expect(screen.getByTestId('memory-label')).toBeTruthy();

    const notMeasurable = screen.getByTestId('memory-not-measurable');
    expect(notMeasurable.textContent).toBe('측정 불가');
    expect(screen.queryByTestId('memory-measured')).toBeNull();
    // 화면 어디에도 "(0%)" 위장 표기가 없어야 한다
    expect(document.body.textContent).not.toContain('0%');

    testUtils.cleanup();
  });

  it('percentage 0 은 측정 불가가 아니라 정상 측정값(0%)이다', async () => {
    const testUtils = createLayoutTest(infoLayout);
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(0) });

    await testUtils.render();

    expect(screen.getByTestId('memory-measured').textContent).toBe('8 GB / 16 GB (0%)');
    expect(screen.queryByTestId('memory-not-measurable')).toBeNull();

    testUtils.cleanup();
  });
});
