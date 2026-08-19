/**
 * @file admin-settings-drivers-s3-fields.test.tsx
 * @description 드라이버 탭 S3 블록 렌더링 테스트 (공개 #99 / 내부 #563)
 *
 * 실제 partial(_tab_drivers.json)의 s3_settings 블록을 그대로 렌더하여 검증한다:
 * - s3 선택 시 리전이 자유 입력 Input 으로 렌더 (Select 아님 — A4)
 * - s3_endpoint Input · s3_use_path_style Toggle 렌더 (A3)
 * - 연결 테스트 payload 에 s3_endpoint/s3_use_path_style 포함, s3_url 의도적 제외 (A10)
 * - websocket 테스트 payload 에 server 3필드 포함 (A6)
 * - 저장 payload 가 활성 탭 폼을 키 열거 없이 통째로 전송 (신규 키 자동 포함)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const partialPath = resolve(
  __dirname,
  '../../layouts/partials/admin_settings/_tab_drivers.json'
);
const driversPartial = JSON.parse(readFileSync(partialPath, 'utf-8'));

const settingsLayoutPath = resolve(__dirname, '../../layouts/admin_settings.json');
const settingsLayout = JSON.parse(readFileSync(settingsLayoutPath, 'utf-8'));

// ---------------------------------------------------------------------------
// 테스트용 컴포넌트
// ---------------------------------------------------------------------------

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestInput: React.FC<any> = ({ className, disabled, type, name, placeholder }) => (
  <input
    className={className}
    disabled={disabled}
    type={type}
    name={name}
    placeholder={placeholder}
    data-testid={name}
  />
);

const TestSelect: React.FC<any> = ({ name }) => (
  <select name={name} data-testid={`select-${name}`} />
);

const TestToggle: React.FC<any> = ({ name, disabled }) => (
  <input type="checkbox" role="switch" name={name} disabled={disabled} data-testid={`toggle-${name}`} />
);

const TestButton: React.FC<any> = ({ className, disabled, type, children, text }) => (
  <button className={className} disabled={disabled} type={type} data-testid="test-button">
    {children || text}
  </button>
);

const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestLabel: React.FC<any> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestIcon: React.FC<any> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

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

// ---------------------------------------------------------------------------
// partial JSON 탐색 헬퍼
// ---------------------------------------------------------------------------

/** id 로 노드를 깊이 우선 탐색합니다. */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/** 루트 배열에서 id 노드를 찾습니다. */
function findInPartial(id: string): any {
  for (const root of driversPartial.components ?? [driversPartial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

/** 노드 서브트리에서 조건에 맞는 모든 노드를 수집합니다. */
function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  for (const child of node.children ?? []) collectNodes(child, predicate, acc);
  return acc;
}

/** 레이아웃 JSON 어디에 있든(children/actions/modals 등) apiCall 액션을 전부 수집합니다. */
function collectApiCallsDeep(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (node.handler === 'apiCall') acc.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      value.forEach((item) => collectApiCallsDeep(item, acc));
    } else if (value && typeof value === 'object') {
      collectApiCallsDeep(value, acc);
    }
  }
  return acc;
}

/** 노드 서브트리에서 apiCall 액션들을 수집합니다 (actions 배열 내 중첩 sequence 포함). */
function collectApiCalls(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node.actions)) {
    const walk = (action: any) => {
      if (!action || typeof action !== 'object') return;
      if (action.handler === 'apiCall') acc.push(action);
      for (const nested of action.actions ?? []) walk(nested);
    };
    node.actions.forEach(walk);
  }
  for (const child of node.children ?? []) collectApiCalls(child, acc);
  return acc;
}

// ---------------------------------------------------------------------------
// 렌더링 테스트
// ---------------------------------------------------------------------------

describe('드라이버 탭 S3 블록 (실 partial 렌더)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  function renderS3Block() {
    const s3Settings = findInPartial('s3_settings');
    expect(s3Settings).not.toBeNull();

    const layout = {
      version: '1.0.0',
      layout_name: 'test_drivers_s3',
      components: [s3Settings],
    };

    return createLayoutTest(layout as any, {
      initialState: {
        _local: {
          form: {
            drivers: {
              storage_driver: 's3',
              s3_bucket: '',
              s3_region: '',
              s3_access_key: '',
              s3_secret_key: '',
              s3_endpoint: '',
              s3_use_path_style: false,
              s3_url: '',
            },
          },
          errors: {},
        },
      },
    });
  }

  // @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
  // @effects invalid_region_rejected
  it('s3 선택 시 리전이 자유 입력 Input 으로 렌더된다 (Select 아님)', async () => {
    const testUtils = renderS3Block();
    await testUtils.render();

    // 존재 확정 후 부재 단언 (부재 단언 단독 금지 규율)
    expect(screen.getByTestId('drivers.s3_region')).toBeInTheDocument();
    expect(screen.getByTestId('drivers.s3_region').tagName).toBe('INPUT');
    expect(screen.queryByTestId('select-drivers.s3_region')).toBeNull();

    testUtils.cleanup();
  });

  it('s3_endpoint Input 과 s3_use_path_style Toggle 이 렌더된다', async () => {
    const testUtils = renderS3Block();
    await testUtils.render();

    expect(screen.getByTestId('drivers.s3_endpoint')).toBeInTheDocument();
    expect(screen.getByTestId('toggle-drivers.s3_use_path_style')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('기존 s3 필드(bucket/access/secret/url)가 유지된다 (회귀 가드)', async () => {
    const testUtils = renderS3Block();
    await testUtils.render();

    for (const field of ['s3_bucket', 's3_access_key', 's3_secret_key', 's3_url']) {
      expect(screen.getByTestId(`drivers.${field}`)).toBeInTheDocument();
    }

    testUtils.cleanup();
  });
});

// ---------------------------------------------------------------------------
// 구조(payload 계약) 테스트 — 실제 partial JSON 을 직접 검증
// ---------------------------------------------------------------------------

describe('드라이버 탭 연결 테스트 payload 계약', () => {
  // @scenario region_shape=aws_region,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
  // @effects endpoint_injected
  it('s3 테스트 payload 에 s3_endpoint/s3_use_path_style 이 포함되고 s3_url 은 제외된다', () => {
    const s3Settings = findInPartial('s3_settings');
    const apiCalls = collectApiCalls(s3Settings);
    const testCall = apiCalls.find(
      (a) => a.target === '/api/admin/settings/test-driver'
    );

    expect(testCall).toBeDefined();

    const body = testCall.params.body;
    expect(Object.keys(body)).toEqual(
      expect.arrayContaining([
        'storage_driver',
        's3_bucket',
        's3_region',
        's3_access_key',
        's3_secret_key',
        's3_endpoint',
        's3_use_path_style',
      ])
    );
    // s3_url 은 공개 URL base — 연결 테스트와 무관 (의도적 제외, A10)
    expect(body).not.toHaveProperty('s3_url');
  });

  it('websocket 테스트 payload 에 server 3필드가 포함된다 (A6)', () => {
    const roots = driversPartial.components ?? [driversPartial];
    const allCalls: any[] = [];
    for (const root of roots) collectApiCalls(root, allCalls);

    const websocketCall = allCalls.find(
      (a) =>
        a.target === '/api/admin/settings/test-driver' &&
        a.params?.body?.websocket_enabled !== undefined
    );

    expect(websocketCall).toBeDefined();
    expect(Object.keys(websocketCall.params.body)).toEqual(
      expect.arrayContaining([
        'websocket_host',
        'websocket_port',
        'websocket_scheme',
        'websocket_server_host',
        'websocket_server_port',
        'websocket_server_scheme',
      ])
    );
  });

  it('리전 Select 하드코딩 옵션(regions.*)이 partial 에서 제거되었다', () => {
    const raw = JSON.stringify(driversPartial);
    // 존재 확정 — 대체된 자유 입력 힌트 키가 같은 s3 블록에 실려 있다
    // (partial 경로/구조가 바뀌어 빈 대상을 검사하면 부재 단언만으로는 공회전 통과)
    expect(raw).toContain('admin.settings.drivers.storage.s3_region_desc');
    expect(raw).not.toContain('storage.regions.');
  });

  it('onError fallback 메시지가 다국어 키를 사용한다 (a9)', () => {
    const raw = JSON.stringify(driversPartial);
    // 존재 확정 — 영문 하드코딩을 대체한 다국어 키가 실제로 쓰이고 있다
    expect(raw).toContain('$t:admin.settings.drivers.test_failed');
    expect(raw).not.toContain('Connection test failed');
  });

  // @scenario region_shape=aws_region,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
  // @effects local_roundtrip_preserves_new_keys
  it('저장 payload 는 활성 탭 폼을 키 열거 없이 통째로 전송한다 (신규 s3 키 자동 포함)', () => {
    const saveCalls = collectApiCallsDeep(settingsLayout).filter(
      (a) => a.target === '/api/admin/settings' && a.params?.method === 'POST'
    );

    // 존재 확정 후 계약 단언
    expect(saveCalls.length).toBeGreaterThan(0);

    const saveBody = saveCalls.map((a) => a.params?.body).find(
      (body) => typeof body === 'string' && body.includes('_tab')
    );
    expect(saveBody).toBeDefined();

    // 탭 폼 객체를 통째로 전달 — s3_endpoint/s3_use_path_style 등 신규 키가
    // 별도 열거 없이 자동 포함된다 (열거 방식이면 키 추가 시 조용히 누락)
    expect(saveBody).toContain('[tab]: form[tab] || {}');
    expect(saveBody).not.toContain('s3_');
  });
});
