/**
 * @file admin-settings-info-opcache.test.tsx
 * @description 환경설정 > 정보 탭 OPcache 상태 행/경고 블록 렌더링 테스트
 *
 * 테스트 대상: 실제 `_tab_info.json` 의 OPcache 경고 블록(`opcache_warning`)과 상태 행(`card_system_specs` 안)을 그대로 렌더한다:
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
import { findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import infoTab from '../../layouts/partials/admin_settings/_tab_info.json';
import adminSettingsLayout from '../../layouts/admin_settings.json';
import adminKo from '../../lang/partial/ko/admin.json';

type Props = { className?: string; children?: React.ReactNode; text?: string };

const TestDiv: React.FC<Props> = ({ className, children, text }) => <div className={className}>{children || text}</div>;
const TestSpan: React.FC<Props> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestH3: React.FC<Props> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestP: React.FC<Props> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다.
// 미등록 시 트리 전체가 렌더되지 않아 "없어야 한다" 계열 단언이 거짓 통과한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    Span: basic('Span', TestSpan),
    H3: basic('H3', TestH3),
    P: basic('P', TestP),
    Icon: basic('Icon', TestIcon),
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

// 실제 `_tab_info.json` 의 OPcache 경고 블록(탭 맨 위)과 시스템 사양 카드(OPcache 상태 행 포함)를 그대로 렌더한다.
// 손으로 옮긴 사본은 원본이 바뀌어도 옛 모양을 재므로 쓰지 않는다. 라벨은 실제 ko 언어팩으로 해석한다.
const infoText = (adminKo as any).settings.info;
const opcacheWarningNode = findById(infoTab, 'opcache_warning');
const systemSpecsCard = findById(infoTab, 'card_system_specs');
// 실제 화면의 데이터소스 정의 — 탭 조건(if)만 걷는다(이 시험은 탭 전환 없이 노드만 그린다).
const { if: _tabCondition, ...systemInfoSource } = (adminSettingsLayout as any).data_sources.find(
  (ds: { id: string }) => ds.id === 'systemInfo'
);

const infoLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_settings_info',
  data_sources: [systemInfoSource],
  components: [opcacheWarningNode, systemSpecsCard],
};

function createTest() {
  return createLayoutTest(infoLayout as any, {
    translations: { admin: adminKo },
    templateId: 'sirsoft-admin_basic',
    locale: 'ko',
  });
}

/** OPcache 상태 행의 값 칸 글자 (행 = 라벨을 담은 flex 양 끝 정렬 컨테이너, 값 = 마지막 자식) */
function opcacheValue(): string | null | undefined {
  const row = screen.getByText(infoText.opcache, { selector: 'span' }).closest('.flex-between');
  expect(row).not.toBeNull();

  return row!.lastElementChild?.textContent;
}

/** OPcache 경고 블록 (없으면 null) */
function opcacheWarning(container: HTMLElement): HTMLElement | null {
  return container.querySelector('.alert-warning');
}

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
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: true }),
      });

      const { container } = await testUtils.render();

      expect(opcacheValue()).toBe(infoText.opcache_enabled);

      testUtils.cleanup();
    });

    // @scenario opcache_state=enabled, surface=admin_info_row
    // @effects admin_info_warning_shown_only_when_disabled
    it('경고 블록이 표시되지 않아야 한다', async () => {
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: true }),
      });

      const { container } = await testUtils.render();

      // 긍정 앵커 — 트리가 실제로 렌더됐음을 먼저 확인해야 아래 부정 단언이 의미를 갖는다.
      expect(screen.getByText(infoText.opcache)).toBeTruthy();
      expect(opcacheWarning(container)).toBeNull();

      testUtils.cleanup();
    });
  });

  describe('OPcache 비활성 (enabled: false)', () => {
    // @scenario opcache_state=loaded_but_disabled, surface=admin_info_row
    // @effects admin_info_row_shows_enabled_disabled_unknown
    it('상태가 비활성화됨으로 표시되어야 한다', async () => {
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      const { container } = await testUtils.render();

      expect(opcacheValue()).toBe(infoText.opcache_disabled);

      testUtils.cleanup();
    });

    // @scenario opcache_state=loaded_but_disabled, surface=admin_info_warning_block
    // @effects admin_info_warning_shown_only_when_disabled
    it('성능 저하 경고 블록이 표시되어야 한다', async () => {
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      const { container } = await testUtils.render();

      const warning = opcacheWarning(container);
      expect(warning).not.toBeNull();
      expect(warning!.textContent).toBe(infoText.opcache_disabled_warning);
      expect(warning!.querySelector('[data-icon="triangle-exclamation"]')).not.toBeNull();

      testUtils.cleanup();
    });

    // @scenario opcache_state=loaded_but_disabled, surface=admin_info_warning_block
    // @effects admin_info_warning_shown_only_when_disabled
    it('확장이 로드돼 있어도 enabled=false 면 경고해야 한다', async () => {
      // 확장 로드 여부만으로 판정하는 회귀 차단 (opcache.enable=0 케이스)
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: false }),
      });

      const { container } = await testUtils.render();

      expect(opcacheWarning(container)).not.toBeNull();

      testUtils.cleanup();
    });
  });

  describe('OPcache 확인 불가 (enabled: null)', () => {
    // @scenario opcache_state=ini_get_blocked, surface=admin_info_row
    // @effects admin_info_row_shows_enabled_disabled_unknown
    it('상태가 확인 불가로 표시되어야 한다', async () => {
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: null }),
      });

      const { container } = await testUtils.render();

      expect(opcacheValue()).toBe(infoText.opcache_unknown);

      testUtils.cleanup();
    });

    // @scenario opcache_state=ini_get_blocked, surface=admin_info_warning_block
    // @effects admin_info_warning_hidden_when_unknown
    it('경고 블록이 표시되지 않아야 한다 (확인 불가는 경고가 아니다)', async () => {
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse({ loaded: true, enabled: null }),
      });

      const { container } = await testUtils.render();

      // 긍정 앵커 — 미렌더로 인한 거짓 통과 차단
      expect(screen.getByText(infoText.opcache)).toBeTruthy();
      expect(opcacheWarning(container)).toBeNull();

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
      const testUtils = createTest();
      testUtils.mockApi('systemInfo', {
        response: createSystemInfoResponse(undefined),
      });

      const { container } = await testUtils.render();

      expect(opcacheValue()).toBe(infoText.opcache_unknown);
      expect(opcacheWarning(container)).toBeNull();

      testUtils.cleanup();
    });
  });
});
