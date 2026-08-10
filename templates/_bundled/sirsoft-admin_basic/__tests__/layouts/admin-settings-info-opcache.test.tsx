/**
 * @file admin-settings-info-opcache.test.tsx
 * @description 환경설정 > 정보 탭 OPcache 상태 행/경고 블록 렌더링 테스트
 *
 * 테스트 대상 (_tab_info.json 의 OPcache 블록):
 * - opcache.enabled: true  → 상태 "활성화됨", 경고 블록 미표시
 * - opcache.enabled: false → 상태 "비활성화됨", 경고 블록 표시 (성능 저하 안내)
 * - opcache.enabled: null  → 상태 "확인 불가", 경고 블록 미표시 (확인 불가는 경고 아님)
 * - opcache 키 자체 부재  → 상태 "확인 불가", 경고 블록 미표시 (구버전 응답 방어)
 *
 * 경고 블록의 조건은 `=== false` 엄격 비교여야 한다. `!enabled` 로 쓰면
 * null(확인 불가)에서도 "비활성화됨" 경고가 잘못 뜬다.
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

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} data-testid={`icon-${name}`} />
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다.
// 미등록 시 트리 전체가 렌더되지 않아 "없어야 한다" 계열 단언이 거짓 통과한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * system-info API 응답 생성.
 *
 * @param opcache opcache 페이로드 (undefined 면 키 자체를 생략)
 */
function createSystemInfoResponse(opcache?: { loaded: boolean; enabled: boolean | null }) {
  return {
    success: true,
    data: {
      php_memory_limit: '512M',
      max_execution_time: '30초',
      ...(opcache === undefined ? {} : { opcache }),
    },
  };
}

// _tab_info.json 의 OPcache 블록과 동일한 표현식.
// 프로덕션에서는 $t:key 로 라벨을 해석하나, 테스트 레지스트리에는 i18n 이 없으므로
// 판정 결과 자체를 문자열 리터럴로 확인한다.
const STATUS_EXPR =
  "{{systemInfo?.data?.opcache?.enabled === true ? 'enabled' " +
  ": (systemInfo?.data?.opcache?.enabled === false ? 'disabled' : 'unknown')}}";
const WARNING_IF = '{{systemInfo?.data?.opcache?.enabled === false}}';

const infoLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_settings_info',
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
      id: 'card_system_specs',
      type: 'basic',
      name: 'Div',
      children: [
        {
          type: 'basic',
          name: 'Div',
          props: { className: 'flex-between' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              props: { className: 'text-label', 'data-testid': 'opcache-label' },
              text: 'OPcache',
            },
            {
              type: 'basic',
              name: 'Span',
              props: { className: 'text-heading', 'data-testid': 'opcache-value' },
              text: STATUS_EXPR,
            },
          ],
        },
        {
          if: WARNING_IF,
          type: 'basic',
          name: 'Div',
          props: { className: 'alert-warning', 'data-testid': 'opcache-warning' },
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'flex-center gap-3' },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: {
                    name: 'triangle-exclamation',
                    className: 'text-xl text-amber-600 dark:text-amber-400 flex-shrink-0',
                  },
                },
                {
                  type: 'basic',
                  name: 'P',
                  props: { className: 'text-warning-soft' },
                  text: 'OPcache가 비활성화되어 있습니다.',
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

describe('admin-settings 정보 탭 — OPcache 상태', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  describe('OPcache 활성 (enabled: true)', () => {
    // @scenario opcache_state=enabled, surface=admin_info_row
    // @effects admin_info_row_shows_enabled_disabled_unknown
    it('상태가 활성화됨으로 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: true }),
      });

      await testUtils.render();

      expect(screen.getByTestId('opcache-value').textContent).toBe('enabled');

      testUtils.cleanup();
    });

    it('경고 블록이 표시되지 않아야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: true }),
      });

      await testUtils.render();

      // 긍정 앵커 — 트리가 실제로 렌더됐음을 먼저 확인해야 아래 부정 단언이 의미를 갖는다.
      expect(screen.getByTestId('opcache-label')).toBeTruthy();
      expect(screen.queryByTestId('opcache-warning')).toBeNull();

      testUtils.cleanup();
    });
  });

  describe('OPcache 비활성 (enabled: false)', () => {
    // @scenario opcache_state=loaded_but_disabled, surface=admin_info_row
    // @effects admin_info_row_shows_enabled_disabled_unknown
    it('상태가 비활성화됨으로 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      await testUtils.render();

      expect(screen.getByTestId('opcache-value').textContent).toBe('disabled');

      testUtils.cleanup();
    });

    // @scenario opcache_state=loaded_but_disabled, surface=admin_info_warning_block
    // @effects admin_info_warning_shown_only_when_disabled
    it('성능 저하 경고 블록이 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      await testUtils.render();

      const warning = screen.getByTestId('opcache-warning');
      expect(warning).toBeTruthy();
      expect(warning.className).toContain('alert-warning');
      expect(screen.getByTestId('icon-triangle-exclamation')).toBeTruthy();

      testUtils.cleanup();
    });

    it('확장이 로드돼 있어도 enabled=false 면 경고해야 한다', async () => {
      // 확장 로드 여부만으로 판정하는 회귀 차단 (opcache.enable=0 케이스)
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      await testUtils.render();

      expect(screen.getByTestId('opcache-warning')).toBeTruthy();

      testUtils.cleanup();
    });
  });

  describe('OPcache 확인 불가 (enabled: null)', () => {
    // @scenario opcache_state=ini_get_blocked, surface=admin_info_row
    // @effects admin_info_row_shows_enabled_disabled_unknown
    it('상태가 확인 불가로 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: null }),
      });

      await testUtils.render();

      expect(screen.getByTestId('opcache-value').textContent).toBe('unknown');

      testUtils.cleanup();
    });

    // @scenario opcache_state=ini_get_blocked, surface=admin_info_warning_block
    // @effects admin_info_warning_hidden_when_unknown
    it('경고 블록이 표시되지 않아야 한다 (확인 불가는 경고가 아니다)', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: null }),
      });

      await testUtils.render();

      // 긍정 앵커 — 미렌더로 인한 거짓 통과 차단
      expect(screen.getByTestId('opcache-label')).toBeTruthy();
      expect(screen.queryByTestId('opcache-warning')).toBeNull();

      testUtils.cleanup();
    });
  });

  describe('opcache 키 부재 (구버전 응답 방어)', () => {
    /**
     * opcache 키가 아예 없는 구버전/폴백 응답에서도 화면이 깨지지 않아야 한다.
     *
     * @effects admin_info_defensive_against_missing_opcache_key
     */
    it('상태가 확인 불가로 표시되고 경고 블록이 뜨지 않아야 한다', async () => {
      const testUtils = createLayoutTest(infoLayout);
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse(undefined),
      });

      await testUtils.render();

      expect(screen.getByTestId('opcache-value').textContent).toBe('unknown');
      expect(screen.queryByTestId('opcache-warning')).toBeNull();

      testUtils.cleanup();
    });
  });
});
