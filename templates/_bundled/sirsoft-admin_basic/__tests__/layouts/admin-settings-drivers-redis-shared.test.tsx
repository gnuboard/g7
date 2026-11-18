/**
 * @file admin-settings-drivers-redis-shared.test.tsx
 * @description 드라이버 탭 — 캐시·세션·큐가 함께 쓰는 Redis 접속 칸과 연결 시험
 *
 * Redis 접속 칸(호스트·포트·비밀번호·DB)과 연결 시험 단추는 캐시 카드 안에 한 벌만 있다. 세션·큐도 같은 저장값으로
 * Redis 에 붙으므로(서버 `DriverConnectionTester::testAll()` 도 세 자리 중 하나만 redis 면 시험한다), 캐시가 Redis 가
 * 아니어도 세션·큐 중 하나가 Redis 면 칸과 단추가 보여야 한다. 종전에는 캐시 = Redis 일 때만 보여, 캐시가 파일인
 * 사이트는 세션·큐용 접속값을 확인·시험할 곳이 없었다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import tabDrivers from '../../layouts/partials/admin_settings/_tab_drivers.json';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestText: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestInput: React.FC<any> = ({ name }) => <input data-name={name} />;
const TestButton: React.FC<any> = ({ children, text }) => <button type="button">{children || text}</button>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestSelect: React.FC<any> = ({ name }) => <select data-name={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성한다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    H3: basic('H3', TestText),
    Label: basic('Label', TestText),
    Span: basic('Span', TestText),
    P: basic('P', TestText),
    Input: basic('Input', TestInput),
    Button: basic('Button', TestButton),
    Icon: basic('Icon', TestIcon),
    Badge: { component: TestText, metadata: { name: 'Badge', type: 'composite' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

const translations = {
  admin: {
    settings: {
      env_locked_badge: '.env 고정',
      drivers: {
        testing: '테스트 중...',
        test_connection: '연결 테스트',
        test_success: '연결 성공',
        test_failed: '연결 실패',
        common: { optional: '선택', unavailable_saved_value_prefix: '저장된 값', unavailable_saved_value_suffix: '(사용 불가)' },
        cache: {
          title: '캐시',
          desc: '캐시 설명',
          driver: '캐시 드라이버',
          redis_host: 'Redis 호스트',
          redis_port: 'Redis 포트',
          redis_password: 'Redis 비밀번호',
          redis_database: 'Redis 데이터베이스',
          redis_shared_hint: '세션·큐가 쓰는 Redis 접속 정보입니다.',
        },
        session: { title: '세션', desc: '세션 설명', driver: '세션 드라이버', lifetime: '유지 시간', minutes: '분', redis_shared_notice: '세션 공유 안내' },
      },
    },
  },
};

const cards = (tabDrivers as any).children;
const cacheCard = cards.find((c: any) => c.id === 'card_cache_settings');
const sessionCard = cards.find((c: any) => c.id === 'card_session_settings');

/**
 * 캐시·세션 카드를 주어진 드라이버 값으로 렌더링할 유틸을 만든다.
 *
 * @param drivers 폼의 drivers 상태
 * @returns createLayoutTest 유틸
 */
function makeUtils(drivers: Record<string, unknown>) {
  return createLayoutTest(
    { version: '1.0.0', layout_name: 'test_redis_shared', components: [cacheCard, sessionCard] } as any,
    {
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin', role: 'super_admin' }, authType: 'admin' },
      translations,
      locale: 'ko',
      initialState: {
        _local: {
          form: { available_drivers: { cache: [], session: [] }, drivers: { redis_host: '127.0.0.1', ...drivers } },
          errors: null,
        },
      },
    },
  );
}

describe('드라이버 탭 — 캐시·세션·큐 공용 Redis 접속 칸', () => {
  let registry: ComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
    (registry as any).registry = {};
  });

  // @scenario redis_user=session, redis_user=queue
  // @effects redis_fields_and_test_shown_when_any_driver_uses_redis, shared_hint_explains_why_fields_are_in_cache_card
  it.each([
    ['session', { cache_driver: 'file', session_driver: 'redis', queue_driver: 'database' }],
    ['queue', { cache_driver: 'file', session_driver: 'database', queue_driver: 'redis' }],
  ])('캐시가 파일이어도 %s 가 Redis 면 접속 칸·연결 테스트와 공용 안내가 보인다', async (_who, drivers) => {
    testUtils = makeUtils(drivers);
    const { container } = await testUtils.render();
    const text = container.textContent ?? '';

    expect(container.querySelector('input[data-name="drivers.redis_host"]')).not.toBeNull();
    expect(container.querySelector('input[data-name="drivers.redis_database"]')).not.toBeNull();
    expect(text).toContain('연결 테스트');
    expect(text).toContain('세션·큐가 쓰는 Redis 접속 정보입니다.');
  });

  // @scenario redis_user=cache
  // @effects redis_fields_and_test_shown_when_any_driver_uses_redis
  it('캐시가 Redis 면 칸이 보이고 공용 안내는 없다(캐시 칸 그대로)', async () => {
    testUtils = makeUtils({ cache_driver: 'redis', session_driver: 'database', queue_driver: 'database' });
    const { container } = await testUtils.render();

    expect(container.querySelector('input[data-name="drivers.redis_host"]')).not.toBeNull();
    expect(container.textContent ?? '').not.toContain('세션·큐가 쓰는 Redis 접속 정보입니다.');
  });

  // @scenario redis_user=none
  // @effects redis_fields_hidden_when_no_driver_uses_redis
  it('세 자리 모두 Redis 가 아니면 Redis 칸·연결 테스트가 없다', async () => {
    testUtils = makeUtils({ cache_driver: 'file', session_driver: 'database', queue_driver: 'sync' });
    const { container } = await testUtils.render();

    expect(container.querySelector('input[data-name="drivers.redis_host"]')).toBeNull();
    expect(container.textContent ?? '').not.toContain('연결 테스트');
  });
});
