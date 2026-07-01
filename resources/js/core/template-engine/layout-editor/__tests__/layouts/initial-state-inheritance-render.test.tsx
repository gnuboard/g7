/**
 * initial-state-inheritance-render.test.tsx — 상속 병합 초기 상태(initLocal)의 런타임 주입
 *
 *
 * 부모 initLocal + 자식 initLocal(일부 덮음) 의 **병합본**이 실제 마운트 시:
 *   ① 자식 덮은 키 = 자식 값 / 미덮은 부모 키 = 부모 값 으로 `_local` 주입
 *      (shallow merge 런타임 일치)
 *   ② 중첩 객체 depth 보존(JSON 깊은 복사 — TemplateApp.ts:1133)
 *   ③ 되돌린 키(자식 키 제거)는 재마운트 시 부모 값 주입
 *   ④ legacy `state` 선언도 동일 `_local` 주입(별칭, TemplateApp.ts:1122)
 *   ⑤ initGlobal 병합본은 `_global` 주입
 * 를 검증한다.
 *
 * 엔진 사실:
 * - 부모-자식 병합 SSoT = LayoutService.mergeShallow(initLocal/initGlobal/initIsolated)
 *   — 자식이 부모 키를 덮음, 중첩 객체 키는 통째 교체(shallow). 프론트는 **이미 병합된**
 *   initLocal 을 받는다.
 * - 주입 = TemplateApp.ts:1122-1140 — `layoutInitLocal = initLocal || state`(별칭),
 *   각 키를 `JSON.parse(JSON.stringify(value))` 로 `_local` 에 깊은 복사 주입(중첩 depth
 *   무손실). createLayoutTest 가 이 주입 경로를 그대로 재현한다(layoutTestUtils.ts:495/613).
 *
 * 본 테스트는 "(서버가 만든) 병합본을 엔진이 `_local`/`_global` 에 어떻게 주입하는가"를
 * 검증한다(shallow merge 자체의 단위는 LayoutServiceMergeInitLocal PHPUnit 이 담당).
 */

import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '../../../__tests__/utils/layoutTestUtils';

/** 병합 SSoT(shallow merge — 자식이 부모 키를 덮음)를 테스트 입력으로 재현 */
function mergeShallow(
  parent: Record<string, unknown>,
  child: Record<string, unknown>,
): Record<string, unknown> {
  return { ...parent, ...child };
}

/** 최소 레이아웃 — initLocal 주입만 검증하면 되므로 컴포넌트는 텍스트 1개 */
function layoutWith(opts: {
  initLocal?: Record<string, unknown>;
  initGlobal?: Record<string, unknown>;
  state?: Record<string, unknown>;
}) {
  return {
    version: '1.0.0',
    layout_name: 'initial_state_inherit_test',
    ...(opts.initLocal ? { initLocal: opts.initLocal } : {}),
    ...(opts.initGlobal ? { initGlobal: opts.initGlobal } : {}),
    ...(opts.state ? { state: opts.state } : {}),
    components: [
      { id: 'probe', type: 'basic', name: 'Span', text: '상태 주입 테스트' },
    ],
  };
}

/** 부모 initLocal — 매 테스트 신선한 깊은 복사로 사용(테스트 간 오염 방지) */
function parentInitLocal(): Record<string, unknown> {
  return {
    perPage: 20, // 부모 공통 — 자식이 안 덮음
    keyword: '', // 부모 공통 — 자식이 덮음
    filter: { status: 'all', sort: 'recent' }, // 중첩 — 자식이 통째 교체
  };
}

describe('초기 상태(initLocal) 상속 병합 런타임 주입', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
  });

  it('① 자식 덮은 키=자식 값, 미덮은 부모 키=부모 값으로 _local 주입 (shallow)', async () => {
    const child = {
      keyword: '검색', // 부모 키 덮음
      page: 3, // 자식 고유 키
    };
    const merged = mergeShallow(parentInitLocal(), child);

    testUtils = createLayoutTest(layoutWith({ initLocal: merged }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();

    const local = testUtils.getState()._local;
    expect(local.keyword).toBe('검색'); // 자식 덮음
    expect(local.perPage).toBe(20); // 미덮은 부모
    expect(local.page).toBe(3); // 자식 고유
  });

  it('② 중첩 객체는 통째 교체(shallow)되며 depth 가 보존된다 (JSON 깊은 복사)', async () => {
    // 자식이 filter 를 통째 교체(shallow merge 는 중첩 deep merge 아님).
    const child = {
      filter: { status: 'open', sort: 'popular', extra: { pinned: true } },
    };
    const merged = mergeShallow(parentInitLocal(), child);

    testUtils = createLayoutTest(layoutWith({ initLocal: merged }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();

    const local = testUtils.getState()._local;
    // 자식 filter 로 통째 교체 — 부모의 sort:'recent' 는 남지 않음(shallow).
    expect(local.filter).toEqual({ status: 'open', sort: 'popular', extra: { pinned: true } });
    // 중첩 depth(extra.pinned) 보존
    expect(local.filter.extra.pinned).toBe(true);
  });

  it('②-b 3-depth 중첩 값(a.b.c)도 무손실 주입된다 (depth 무제한)', async () => {
    // 엔진은 JSON 깊은 복사로 depth 제한 없이 주입한다(TemplateApp.ts:1133).
    const merged = mergeShallow(parentInitLocal(), {
      manualAddress: { recipient: { name: '홍길동', phone: { mobile: '010' } } },
    });

    testUtils = createLayoutTest(layoutWith({ initLocal: merged }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();

    const local = testUtils.getState()._local;
    expect((local.manualAddress as any).recipient.name).toBe('홍길동');
    expect((local.manualAddress as any).recipient.phone.mobile).toBe('010');
  });

  it('③ 되돌린 키(자식 키 제거)는 재마운트 시 부모 값으로 주입된다', async () => {
    // 덮음 상태
    const overridden = mergeShallow(parentInitLocal(), { keyword: '검색' });
    testUtils = createLayoutTest(layoutWith({ initLocal: overridden }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();
    expect(testUtils.getState()._local.keyword).toBe('검색');
    testUtils.cleanup();

    // 되돌림: 자식 키 제거 → 병합본은 부모만 → 부모 값(빈 문자열) 주입
    const reverted = mergeShallow(parentInitLocal(), {});
    testUtils = createLayoutTest(layoutWith({ initLocal: reverted }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();
    expect(testUtils.getState()._local.keyword).toBe('');
  });

  it('④ legacy `state` 선언도 initLocal 과 동일하게 _local 로 주입된다 (별칭)', async () => {
    // 자식이 옛 이름 `state` 로 선언 — 엔진은 initLocal 별칭으로 주입.
    const merged = mergeShallow(parentInitLocal(), { tab: 'profile' });

    testUtils = createLayoutTest(layoutWith({ state: merged }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();

    const local = testUtils.getState()._local;
    expect(local.tab).toBe('profile');
    expect(local.perPage).toBe(20);
    expect(local.filter).toEqual({ status: 'all', sort: 'recent' });
  });

  it('⑤ initGlobal 병합본은 _global 로 주입된다 (shallow)', async () => {
    const parentGlobal = { theme: 'light', sidebar: 'expanded' };
    const childGlobal = { theme: 'dark' }; // 부모 theme 덮음
    const merged = mergeShallow(parentGlobal, childGlobal);

    testUtils = createLayoutTest(layoutWith({ initGlobal: merged }), {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
    });
    await testUtils.render();

    const global = testUtils.getState()._global;
    expect(global.theme).toBe('dark'); // 자식 덮음
    expect(global.sidebar).toBe('expanded'); // 미덮은 부모
  });
});
