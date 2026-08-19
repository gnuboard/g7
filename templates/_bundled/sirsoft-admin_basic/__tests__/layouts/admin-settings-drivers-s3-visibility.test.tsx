/**
 * @file admin-settings-drivers-s3-visibility.test.tsx
 * @description S3 접속 정보 블록 노출 조건 테스트 (공개 #99 × #100 교차)
 *
 * 파일 스토리지는 로컬로 두고 공개 자산만 S3/CDN 으로 보내는 구성(이슈 #100 이
 * 제안한 "공개 자산 전용 디스크 분리")에서도 버킷·키·엔드포인트·공개 URL 을
 * 입력할 수 있어야 한다. 종전에는 s3 블록이 `storage_driver === 's3'` 일 때만
 * 렌더되어, 공개 자산 카드가 S3 를 제공하는데 그 접속 정보를 넣을 화면이
 * 없었다(도움말은 보이지 않는 필드를 가리켰다).
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const driversPartial = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_tab_drivers.json'), 'utf-8')
);

const settingsLayout = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/admin_settings.json'), 'utf-8')
);

// ---------------------------------------------------------------------------
// 테스트용 컴포넌트
// ---------------------------------------------------------------------------

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestInput: React.FC<any> = ({ name, type }) => <input name={name} type={type} data-testid={name} />;
const TestSelect: React.FC<any> = ({ name }) => <select name={name} data-testid={`select-${name}`} />;
const TestToggle: React.FC<any> = ({ name }) => (
  <input type="checkbox" role="switch" name={name} data-testid={`toggle-${name}`} />
);
const TestButton: React.FC<any> = ({ children, text }) => <button type="button">{children || text}</button>;
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children || text}</span>;
const TestP: React.FC<any> = ({ children, text }) => <p>{children || text}</p>;
const TestLabel: React.FC<any> = ({ children, text }) => <label>{children || text}</label>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * id 로 노드를 깊이 우선 탐색합니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * partial 루트들에서 id 노드를 찾습니다.
 *
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findInPartial(id: string): any {
  for (const root of driversPartial.components ?? [driversPartial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

/**
 * 주어진 드라이버 폼 상태로 s3 블록을 렌더합니다.
 *
 * @param drivers 폼의 drivers 하위 상태
 * @returns 레이아웃 테스트 유틸
 */
function renderS3Block(drivers: Record<string, unknown>) {
  const s3Settings = findInPartial('s3_settings');
  expect(s3Settings).not.toBeNull();

  return createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_drivers_s3_visibility',
      components: [s3Settings],
    } as any,
    {
      initialState: {
        _local: {
          form: { drivers },
          errors: {},
        },
      },
    }
  );
}

const BASE_DRIVERS = {
  s3_bucket: '',
  s3_region: '',
  s3_access_key: '',
  s3_secret_key: '',
  s3_endpoint: '',
  s3_use_path_style: false,
  s3_url: '',
};

// @scenario consumer=product, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=new_remote_row
// @effects s3_credentials_visible_when_public_asset_disk_is_s3
describe('S3 접속 정보 블록 노출 조건', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('파일 스토리지가 s3 면 종전대로 렌더된다', async () => {
    const testUtils = renderS3Block({
      ...BASE_DRIVERS,
      storage_driver: 's3',
      public_asset_disk: 'none',
    });
    await testUtils.render();

    expect(screen.getByTestId('drivers.s3_bucket')).toBeInTheDocument();
    expect(screen.getByTestId('drivers.s3_url')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('파일 스토리지가 로컬이어도 공개 자산 디스크가 s3 면 접속 필드가 렌더된다', async () => {
    const testUtils = renderS3Block({
      ...BASE_DRIVERS,
      storage_driver: 'local',
      public_asset_disk: 's3',
    });
    await testUtils.render();

    for (const field of ['s3_bucket', 's3_region', 's3_access_key', 's3_secret_key', 's3_endpoint', 's3_url']) {
      expect(screen.getByTestId(`drivers.${field}`)).toBeInTheDocument();
    }
    expect(screen.getByTestId('toggle-drivers.s3_use_path_style')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('두 축 모두 s3 가 아니면 접속 필드가 렌더되지 않는다', async () => {
    const present = renderS3Block({
      ...BASE_DRIVERS,
      storage_driver: 's3',
      public_asset_disk: 'none',
    });
    await present.render();
    // 존재를 먼저 확정한 뒤에 부재를 단언한다 (부재 단독 단언 금지 규율)
    expect(screen.getByTestId('drivers.s3_bucket')).toBeInTheDocument();
    present.cleanup();

    const absent = renderS3Block({
      ...BASE_DRIVERS,
      storage_driver: 'local',
      public_asset_disk: 'public',
    });
    await absent.render();
    expect(screen.queryByTestId('drivers.s3_bucket')).toBeNull();
    absent.cleanup();
  });
});

describe('저장 게이트의 S3 연결 테스트 요구 조건', () => {
  /**
   * 저장 버튼의 disabled 표현식을 찾습니다.
   *
   * @param node 탐색 시작 노드
   * @returns disabled 표현식 문자열 또는 null
   */
  function findSaveDisabledExpression(node: any): string | null {
    if (!node || typeof node !== 'object') return null;
    const disabled = node.props?.disabled;
    if (typeof disabled === 'string' && disabled.includes('driverTestResults')) {
      return disabled;
    }
    for (const value of Object.values(node)) {
      if (Array.isArray(value)) {
        for (const item of value) {
          const found = findSaveDisabledExpression(item);
          if (found) return found;
        }
      } else if (value && typeof value === 'object') {
        const found = findSaveDisabledExpression(value);
        if (found) return found;
      }
    }
    return null;
  }

  it('공개 자산 디스크가 s3 인 경우도 연결 테스트 성공을 요구한다', () => {
    const expression = findSaveDisabledExpression(settingsLayout);

    expect(expression).not.toBeNull();
    expect(expression).toContain(
      "(_local.form?.drivers?.storage_driver === 's3' || _local.form?.drivers?.public_asset_disk === 's3')"
    );
  });

  it('재검증 트리거는 S3 접속 설정 변경으로 한정된다 (공개 자산 디스크 전환 자체는 트리거 아님)', () => {
    const expression = findSaveDisabledExpression(settingsLayout);

    // 파일 스토리지가 s3 인 상태에서 공개 자산 디스크를 public 등으로 바꾸는 것은
    // S3 접속 설정과 무관하므로 연결 테스트를 다시 요구해서는 안 된다.
    expect(expression).not.toContain(
      "(_local.form?.drivers?.public_asset_disk || '') !== (_local.originalDrivers?.public_asset_disk || '')"
    );
    // 접속 설정 변경은 종전대로 트리거로 남아 있어야 한다 (대조군)
    expect(expression).toContain(
      "(_local.form?.drivers?.s3_bucket || '') !== (_local.originalDrivers?.s3_bucket || '')"
    );
  });
});
