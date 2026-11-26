/**
 * @file admin-settings-info-not-measurable.test.tsx
 * @description 환경설정 > 정보 탭 메모리/디스크 "측정 불가" 표기와 행 배치 렌더링 테스트 (A-8)
 *
 * 테스트 대상: 실제 `_tab_info.json` 의 `card_system_specs` 노드(시스템 사양 카드)를 그대로 꺼내 렌더한다.
 * 손으로 옮긴 사본은 원본이 바뀌어도(흐림 선언 추가 등) 옛 모양을 재므로 쓰지 않는다.
 *
 * - percentage 숫자      → "used / total (N%)" 표기, 측정 불가 미표시
 * - percentage null      → "측정 불가" 표기 (0% 로 표시하지 않는다 — A-8)
 * - 응답 데이터 미도착   → "-" 플레이스홀더 (측정 불가로 선표시하지 않는다)
 * - 세 상태 모두 메모리·디스크 행(flex 양 끝 정렬)의 자식은 라벨·값 둘이고 값이 마지막 자식이며 빈 div 가 없다.
 *   값 칸이 if 3분기이고 받는 중 분기에 blur_until_loaded 가 있어, 엔진이 if 거짓 노드에도 빈 흐림 래퍼를
 *   남기면 그 빈 div 가 세 번째 자식이 되어 값이 가운데로 밀렸다(engine-v1.99.2). 흐림 선언의 위치와
 *   무관하게 결과(행 자식 = 라벨·값)만 단언한다 — jsdom 은 배치를 계산하지 않으므로 구조로 잰다.
 *
 * percentage null 은 shell_exec 차단(disable_functions)·/proc 미접근 환경에서
 * 서버(SettingsService::unknownUsage)가 내려보내는 "측정 불가" 신호다.
 * `?? 0` 폴백으로 되돌리면 측정 불가가 "0%" 로 위장되므로 이 테스트가 잠근다.
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
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;
// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다 — 미등록이면 트리 전체가 빠져 「없다」 단언이 거짓 통과한다.
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

const infoText = (adminKo as any).settings.info;
const systemSpecsCard = findById(infoTab, 'card_system_specs');
// 실제 화면의 데이터소스 정의 — 탭 조건(if)만 걷는다(이 시험은 탭 전환 없이 카드만 그린다).
const { if: _tabCondition, ...systemInfoSource } = (adminSettingsLayout as any).data_sources.find(
  (ds: { id: string }) => ds.id === 'systemInfo'
);

/**
 * 시스템 사양 카드 하나만 둔 시험 레이아웃을 만듭니다.
 *
 * @param autoFetch false 면 데이터소스를 받지 않는다(받기 전 상태)
 */
function buildLayout(autoFetch = true) {
  return {
    version: '1.0.0',
    layout_name: 'test_admin_settings_info_not_measurable',
    data_sources: [{ ...systemInfoSource, auto_fetch: autoFetch }],
    components: [systemSpecsCard],
  };
}

function createTest(autoFetch = true) {
  return createLayoutTest(buildLayout(autoFetch) as any, {
    translations: { admin: adminKo },
    templateId: 'sirsoft-admin_basic',
    locale: 'ko',
  });
}

/**
 * system-info API 응답 생성 (메모리·디스크 같은 percentage).
 *
 * @param percentage percentage (null = 측정 불가)
 */
function createSystemInfoResponse(percentage: number | null) {
  const usage = (used: string, total: string) => ({
    total: percentage === null ? '알 수 없음' : total,
    used: percentage === null ? '알 수 없음' : used,
    free: percentage === null ? '알 수 없음' : '8 GB',
    percentage,
  });

  return {
    success: true,
    data: {
      memory_usage: usage('8 GB', '16 GB'),
      disk_usage: usage('100 GB', '500 GB'),
    },
  };
}

/**
 * 라벨 글자로 시스템 사양 행(flex 양 끝 정렬 컨테이너)을 찾습니다.
 *
 * @param label 행 라벨 글자
 */
function findRow(label: string): HTMLElement {
  const labelEl = screen.getByText(label, { selector: 'span' });
  const row = labelEl.closest('.flex-between') as HTMLElement | null;
  expect(row).not.toBeNull();

  return row!;
}

/**
 * 행의 배치 불변식 — 흐림이 어디에 선언돼 있든 자식은 라벨·값 둘이고, 값이 마지막 자식이며, 빈 div 가 없다.
 *
 * @param label 행 라벨 글자
 * @param valueText 값 칸에 보여야 할 글자
 */
function expectLabelValueRow(label: string, valueText: string) {
  const row = findRow(label);

  expect(Array.from(row.children).map((el) => el.textContent)).toEqual([label, valueText]);
  expect(row.children).toHaveLength(2);
  expect(row.lastElementChild?.textContent).toBe(valueText);

  const emptyDivs = Array.from(row.querySelectorAll('div')).filter((div) => div.childNodes.length === 0);
  expect(emptyDivs).toHaveLength(0);
}

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
  it('percentage 가 숫자면 사용량과 퍼센트를 표기하고, 값이 행의 마지막 자식이다', async () => {
    const testUtils = createTest();
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(50) });

    // 원본에서 꺼낸 노드·데이터소스가 실제로 있는지부터 — 없으면 아래 단언이 무엇을 재는지 모른다
    expect(systemSpecsCard).toBeTruthy();
    expect(systemInfoSource.endpoint).toBe('/api/admin/settings/system-info');

    await testUtils.render();

    expect(findRow(infoText.memory).lastElementChild?.textContent).toBe('8 GB / 16 GB (50%)');
    expect(screen.queryByText(infoText.not_measurable)).toBeNull();

    expectLabelValueRow(infoText.memory, '8 GB / 16 GB (50%)');
    expectLabelValueRow(infoText.disk, '100 GB / 500 GB (50%)');

    testUtils.cleanup();
  });

  // @scenario shell_exec_guard_surface=settings_memory, measurement_result=not_measurable
  // @effects resources_probe_failure_returns_null_percentage_not_zero
  it('percentage 가 null 이면 0% 가 아니라 측정 불가를 표기하고, 값이 행의 마지막 자식이다', async () => {
    const testUtils = createTest();
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(null) });

    await testUtils.render();

    // 긍정 앵커 — 트리가 실제로 렌더됐음을 먼저 확인
    expect(screen.getByText(infoText.memory)).toBeTruthy();

    expect(findRow(infoText.memory).lastElementChild?.textContent).toBe(infoText.not_measurable);
    expect(screen.queryByText(/\(\d+%\)/)).toBeNull();
    // 화면 어디에도 "(0%)" 위장 표기가 없어야 한다
    expect(document.body.textContent).not.toContain('0%');

    expectLabelValueRow(infoText.memory, infoText.not_measurable);
    expectLabelValueRow(infoText.disk, infoText.not_measurable);

    testUtils.cleanup();
  });

  // @scenario shell_exec_guard_surface=settings_memory, measurement_result=measured
  // @effects resources_probe_failure_returns_null_percentage_not_zero
  it('percentage 0 은 측정 불가가 아니라 정상 측정값(0%)이다', async () => {
    const testUtils = createTest();
    testUtils.mockApi('systemInfo', { response: createSystemInfoResponse(0) });

    await testUtils.render();

    expect(findRow(infoText.memory).lastElementChild?.textContent).toBe('8 GB / 16 GB (0%)');
    expect(screen.queryByText(infoText.not_measurable)).toBeNull();

    testUtils.cleanup();
  });

  // @scenario shell_exec_guard_surface=settings_memory
  // @effects resources_probe_failure_returns_null_percentage_not_zero
  it('응답을 받기 전에는 측정 불가로 선표시하지 않고 "-" 만 행 끝에 둔다', async () => {
    const testUtils = createTest(false);

    await testUtils.render();

    expect(screen.queryByText(infoText.not_measurable)).toBeNull();

    expectLabelValueRow(infoText.memory, '-');
    expectLabelValueRow(infoText.disk, '-');

    testUtils.cleanup();
  });
});
