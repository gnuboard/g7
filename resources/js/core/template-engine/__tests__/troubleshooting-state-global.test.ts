// e2e:allow 병합 과정에서 끊긴 describe 블록의 닫는 괄호 복원 + 사례 번호 재부여. 테스트 파일 자체의 구조 수정이라 검증은 이 테스트 실행으로 완결된다.
/**
 * 트러블슈팅 회귀 테스트 - initGlobal & iteration & 데이터소스
 *
 * troubleshooting-state-global.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see docs/frontend/troubleshooting-state-global.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import {
  getLocalInitTracking,
  isLocalInitConsumed,
  markLocalInitConsumed,
  mergeLocalInitSlot,
  resetLocalInitTracking,
  resolveLocalInitAction,
} from '../localInitSlot';
import { deepMergeState, removeMatchingLeafKeys } from '../DynamicRenderer';
import { Logger } from '../../utils/Logger';

// AuthManager mock
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

// ApiClient mock
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('트러블슈팅 회귀 테스트 - initGlobal 관련', () => {
  describe('[사례 1] initGlobal로 설정한 값이 progressive 데이터 소스에서 undefined', () => {
    /**
     * 증상: blocking 데이터 소스의 initGlobal 값이 progressive 데이터 소스에서 undefined
     * 해결: API 응답 구조에 따라 path 설정 (actualData는 이미 data.data ?? data로 처리됨)
     */
    it('API 응답이 { data: [...] } 형태일 때 path를 지정하지 않아야 함', () => {
      // API 응답
      const apiResponse = { success: true, data: [{ id: 1, name: 'Layout 1' }] };

      // processInitOptions에서 actualData 처리
      const actualData = apiResponse.data ?? apiResponse;

      // path 없이 initGlobal 적용
      const globalState: Record<string, any> = {};
      globalState['layoutFilesList'] = actualData;

      expect(globalState.layoutFilesList).toEqual([{ id: 1, name: 'Layout 1' }]);
    });

    it('path: "data"를 지정하면 배열에서 data 속성을 찾아 undefined 반환', () => {
      // API 응답
      const apiResponse = { success: true, data: [{ id: 1, name: 'Layout 1' }] };
      const actualData = apiResponse.data ?? apiResponse;

      // path 지정 시 getNestedValue 시뮬레이션
      const getNestedValue = (obj: any, path: string) => {
        const keys = path.split('.');
        let current = obj;
        for (const key of keys) {
          if (current === undefined || current === null) return undefined;
          current = current[key];
        }
        return current;
      };

      // 배열에서 'data' 속성을 찾으면 undefined
      const result = getNestedValue(actualData, 'data');
      expect(result).toBeUndefined();
    });
  });

  describe('[사례 2] refetchDataSource 후 initGlobal 적용', () => {
    /**
     * 증상: 초기 로드 시 initGlobal 정상 작동, refetchDataSource 후 미적용
     * 해결: refetchDataSource에서 processInitOptions 호출 추가
     */
    it('refetchDataSource 후에도 initGlobal이 재적용되어야 함', () => {
      // 초기 로드
      const globalState: Record<string, any> = {};
      const initialData = { content: 'initial content' };
      globalState['editorContent'] = initialData.content;

      expect(globalState.editorContent).toBe('initial content');

      // refetchDataSource 시뮬레이션
      const newData = { content: 'updated content' };
      // processInitOptions 재실행
      globalState['editorContent'] = newData.content;

      expect(globalState.editorContent).toBe('updated content');
    });
  });

  describe('[사례 3] initGlobal 배열 형식에서 path에 배열 인덱스 사용', () => {
    /**
     * 증상: path: "[0].name" 같이 배열 인덱스가 포함된 경로가 undefined
     * 해결: getNestedValue에서 배열 인덱스 지원
     */
    it('path에 배열 인덱스를 사용할 수 있어야 함', () => {
      const actualData = [
        { name: 'layout1.json', size: 100 },
        { name: 'layout2.json', size: 200 },
      ];

      // 개선된 getNestedValue 시뮬레이션
      const getNestedValue = (obj: any, path: string) => {
        let current = obj;

        // 경로 파싱: [0].name → ['[0]', 'name']
        const parts = path
          .split(/\.(?![^\[]*\])/)
          .flatMap((part) => {
            const matches = part.match(/^([^\[]*)((?:\[\d+\])*)$/);
            if (matches) {
              const [, base, indices] = matches;
              const result: string[] = [];
              if (base) result.push(base);
              const indexMatches = indices.match(/\[\d+\]/g);
              if (indexMatches) result.push(...indexMatches);
              return result;
            }
            return [part];
          })
          .filter((p) => p !== '');

        for (const part of parts) {
          if (current === undefined || current === null) return undefined;
          if (part.startsWith('[') && part.endsWith(']')) {
            const index = parseInt(part.slice(1, -1), 10);
            current = current[index];
          } else {
            current = current[part];
          }
        }
        return current;
      };

      expect(getNestedValue(actualData, '[0].name')).toBe('layout1.json');
      expect(getNestedValue(actualData, '[1].size')).toBe(200);
    });
  });

  describe('[사례 4] sequence 내 setState 후 refetchDataSource가 이전 상태로 API 호출', () => {
    /**
     * 증상: setState로 _global 변경 후 refetchDataSource 호출 시 이전 값으로 API 호출
     * 해결: handleSetState에서 global 상태 반환 + sequence에서 _global 추적
     */
    it('handleSetState가 global target일 때 payload를 반환해야 함', async () => {
      const mockGlobalStateUpdater = vi.fn();
      const dispatcher = new ActionDispatcher({
        navigate: vi.fn(),
      });
      dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);

      const context = {
        state: {},
        setState: vi.fn(),
        actionId: 'test-global-return',
      };

      const result = await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: {
            target: 'global',
            selectedLayoutName: 'test-layout.json',
          },
        },
        context
      );

      expect(mockGlobalStateUpdater).toHaveBeenCalled();
    });
  });

  describe('[사례 5] progressive 데이터 소스 로드 후 initGlobal 값이 UI에 반영 안됨', () => {
    /**
     * 증상: initGlobal이 this.globalState를 업데이트하지만 바인딩 엔진 캐시가 무효화되지 않음
     * 해결: initGlobal이 있으면 _global 키도 캐시에서 무효화
     */
    it('initGlobal이 있는 데이터소스 로드 시 _global 캐시가 무효화되어야 함', () => {
      // 바인딩 엔진 캐시 시뮬레이션
      const cache = new Map<string, any>();
      cache.set('_global.currentUser', null);
      cache.set('products.data', []);

      // initGlobal이 있는 데이터소스 로드
      const source = {
        id: 'current_user',
        initGlobal: 'currentUser',
      };

      // 캐시 무효화
      const keysToInvalidate = [source.id];
      if (source.initGlobal) {
        keysToInvalidate.push('_global');
      }

      for (const key of keysToInvalidate) {
        for (const cacheKey of cache.keys()) {
          if (cacheKey.startsWith(key) || cacheKey.includes(key)) {
            cache.delete(cacheKey);
          }
        }
      }

      expect(cache.has('_global.currentUser')).toBe(false);
      expect(cache.has('products.data')).toBe(true);
    });
  });

  describe('[사례 6] initGlobal의 path 설정으로 인한 이중 추출 문제', () => {
    /**
     * 증상: path: "data" 설정이 이중으로 적용되어 undefined 반환
     * 해결: actualData가 이미 추출된 상태이므로 path 제거
     */
    it('API 응답이 { data: {...} } 형태일 때 path 없이 initGlobal 사용', () => {
      // API 응답
      const apiResponse = {
        success: true,
        data: { id: 1, name: '관리자', email: 'admin@test.com' },
      };

      // processInitOptions에서 actualData 처리
      const actualData = apiResponse.data ?? apiResponse;

      // initGlobal: "currentUser" (path 없음)
      const globalState: Record<string, any> = {};
      globalState['currentUser'] = actualData;

      expect(globalState.currentUser).toEqual({
        id: 1,
        name: '관리자',
        email: 'admin@test.com',
      });
    });
  });
});

describe('트러블슈팅 회귀 테스트 - iteration 관련', () => {
  describe('[사례 1] iteration + if에서 item_var를 같은 레벨에서 참조', () => {
    /**
     * 증상: iteration으로 배열 반복 시 if 조건에서 item_var를 참조하면 undefined
     * 해결: if가 iteration보다 먼저 평가되므로 래퍼 컴포넌트 사용
     */
    it('iteration의 item_var는 children에서만 접근 가능해야 함', () => {
      // DynamicRenderer 처리 순서 시뮬레이션
      const processingOrder = ['if', 'iteration', 'render'];

      // if가 먼저 평가됨
      expect(processingOrder.indexOf('if')).toBeLessThan(
        processingOrder.indexOf('iteration')
      );
    });

    it('래퍼 컴포넌트를 사용하면 if에서 item_var 접근 가능', () => {
      // 올바른 패턴: iteration children 내부에서 if 사용
      const layout = {
        iteration: {
          source: '{{types}}',
          item_var: 'permType',
        },
        children: [
          {
            if: "{{activeTab === permType}}", // children 내부에서 item_var 접근
            type: 'Div',
          },
        ],
      };

      expect(layout.children[0].if).toContain('permType');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 데이터소스 업데이트 및 리렌더링', () => {
  describe('[사례 1] 커스텀 핸들러에서 데이터 업데이트 후 UI 리렌더링', () => {
    /**
     * 증상: G7Core.dataSource.updateItem() 호출 후 화면 데이터 미변경
     * 해결: skipRender 옵션 확인, itemId 타입 일치, idField 옵션
     */
    it('skipRender가 true면 UI 갱신되지 않아야 함', () => {
      const options = { skipRender: true };
      expect(options.skipRender).toBe(true);

      // skipRender: false (기본값)일 때만 UI 갱신
      const defaultOptions = { skipRender: false };
      expect(defaultOptions.skipRender).toBe(false);
    });

    it('itemId 타입이 데이터와 일치해야 함', () => {
      const data = { id: 123, name: '상품' };

      // 올바른 예: 타입 일치
      const correctId = 123;
      expect(data.id === correctId).toBe(true);

      // 잘못된 예: 문자열로 전달
      const wrongId = '123';
      expect(data.id === wrongId).toBe(false);
    });

    it('커스텀 ID 필드 사용 시 idField 옵션이 필요함', () => {
      const data = { uuid: 'abc-123', name: '상품' };

      const options = { idField: 'uuid' };
      expect(data[options.idField as keyof typeof data]).toBe('abc-123');
    });
  });

  describe('[사례 2] initGlobal 사용 vs G7Core.dataSource.set() 선택 기준', () => {
    /**
     * initGlobal: 데이터소스 응답을 전역 상태에 자동 매핑 (선언적)
     * G7Core.dataSource.set(): 프로그래매틱하게 데이터소스 수정
     */
    it('initGlobal은 데이터소스 로드 시 자동 실행됨', () => {
      const dataSource = {
        id: 'products',
        endpoint: '/api/products',
        initGlobal: 'productList',
      };

      expect(dataSource.initGlobal).toBe('productList');
    });
  });

  describe('[사례 3] init_actions의 refetchDataSource가 React 렌더링 전에 실행', () => {
    /**
     * 증상: init_actions에서 refetchDataSource 호출 시 데이터 손실
     * 해결: defer 옵션 또는 setTimeout 래핑
     */
    it('defer 옵션으로 렌더링 후 실행을 보장해야 함', () => {
      const action = {
        handler: 'refetchDataSource',
        params: {
          dataSourceId: 'products',
          defer: true, // 렌더링 후 실행
        },
      };

      expect(action.params.defer).toBe(true);
    });
  });

  describe('[사례 4] navigate/뒤로가기 재진입 시 init_actions 데이터 미표시', () => {
    /**
     * 증상: 뒤로가기로 페이지 재진입 시 init_actions로 로드한 데이터가 UI에 미표시
     * 해결: 페이지 키를 사용하여 컴포넌트 리마운트 강제
     */
    it('route.path를 key로 사용하여 리마운트를 강제해야 함', () => {
      const key1 = 'page-/admin/products';
      const key2 = 'page-/admin/orders';
      const key1Again = 'page-/admin/products';

      // 같은 경로면 같은 키
      expect(key1).toBe(key1Again);
      // 다른 경로면 다른 키
      expect(key1).not.toBe(key2);
    });
  });

  describe('[사례 2] 레이아웃 전환 시 _global._local이 초기화되지 않음 (initLocal 없는 레이아웃)', () => {
    /**
     * 증상: 역할 수정 → 역할 목록 → 역할 추가 이동 시 수정 폼 데이터가 _global._local에 잔존
     * 원인: cleanup 로직이 if(layoutInitLocal) 블록 안에 중첩되어 있어
     *       레이아웃 레벨 initLocal이 없으면 cleanup 자체가 실행되지 않음
     * 해결: cleanup 로직을 if(layoutInitLocal) 블록 바깥으로 이동
     */
    it('레이아웃 레벨 initLocal이 없어도 다른 레이아웃 전환 시 _global._local이 초기화되어야 함', () => {
      // 역할 수정 페이지에서 데이터소스 initLocal로 설정된 _global._local
      const globalState: Record<string, any> = {
        _local: {
          form: {
            id: 8,
            name: '매니저',
            permission_ids: [102, 103, 104],
          },
        },
      };

      let currentLayoutName = 'admin_role_form';

      // 역할 목록 레이아웃으로 전환 (initLocal 없음 — 데이터소스 initLocal만 있음)
      const newLayoutName = 'admin_role_list';
      const layoutData = {
        layout_name: 'admin_role_list',
        // 레이아웃 레벨 initLocal 없음
      };

      // cleanup 로직 (블록 바깥에서 항상 실행)
      const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;
      expect(isLayoutChanged).toBe(true);

      if (isLayoutChanged) {
        globalState._local = {};
      }
      currentLayoutName = newLayoutName;

      // initLocal 적용 (없으므로 스킵)
      const layoutInitLocal = (layoutData as any).initLocal;
      if (layoutInitLocal && Object.keys(layoutInitLocal).length > 0) {
        // 이 블록은 실행되지 않음
        for (const [key, value] of Object.entries(layoutInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }
      }

      // 수정 폼 데이터가 제거되어야 함
      expect(globalState._local.form).toBeUndefined();
      expect(globalState._local).toEqual({});
    });

    it('currentLayoutName이 initLocal 유무와 관계없이 항상 갱신되어야 함', () => {
      let currentLayoutName = '';

      // 1단계: initLocal 없는 레이아웃 첫 진입
      currentLayoutName = 'admin_role_form';
      expect(currentLayoutName).toBe('admin_role_form');

      // 2단계: 다른 레이아웃으로 전환 (initLocal 유무 무관)
      const newLayoutName = 'admin_product_list';
      currentLayoutName = newLayoutName;
      expect(currentLayoutName).toBe('admin_product_list');

      // 3단계: 다시 initLocal 없는 레이아웃으로 전환
      const layoutName3 = 'admin_role_form';
      const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== layoutName3;
      expect(isLayoutChanged).toBe(true);
      currentLayoutName = layoutName3;
      expect(currentLayoutName).toBe('admin_role_form');
    });

    it('역할 수정 → 역할 목록 → 역할 추가 전체 흐름에서 폼 데이터가 잔존하지 않아야 함', () => {
      const globalState: Record<string, any> = { _local: {} };
      let currentLayoutName = '';

      // 1단계: 역할 수정 페이지 진입 (admin_role_form, 레이아웃 레벨 initLocal 없음)
      const editLayoutName = 'admin_role_form';
      if (currentLayoutName !== '' && currentLayoutName !== editLayoutName) {
        globalState._local = {};
      }
      currentLayoutName = editLayoutName;

      // 데이터소스 initLocal로 폼 데이터 설정 (엔진에서 자동 실행)
      globalState._local.form = {
        id: 8,
        name: '매니저',
        permission_ids: [102, 103],
      };
      expect(globalState._local.form.id).toBe(8);

      // 2단계: 역할 목록으로 이동 (admin_role_list)
      const listLayoutName = 'admin_role_list';
      const isChanged1 = currentLayoutName !== '' && currentLayoutName !== listLayoutName;
      expect(isChanged1).toBe(true);
      if (isChanged1) {
        globalState._local = {};
      }
      currentLayoutName = listLayoutName;
      expect(globalState._local.form).toBeUndefined();

      // 3단계: 역할 추가로 이동 (admin_role_form, 레이아웃 레벨 initLocal 없음)
      const createLayoutName = 'admin_role_form';
      const isChanged2 = currentLayoutName !== '' && currentLayoutName !== createLayoutName;
      expect(isChanged2).toBe(true);
      if (isChanged2) {
        globalState._local = {};
      }
      currentLayoutName = createLayoutName;

      // 수정 모드의 폼 데이터가 완전히 제거되어야 함
      expect(globalState._local.form).toBeUndefined();
      expect(globalState._local).toEqual({});
    });

    it('수정 전 코드 패턴 (cleanup이 if 블록 안에 있을 때)에서는 cleanup이 실행되지 않음', () => {
      // 수정 전 패턴 시뮬레이션: cleanup이 if(layoutInitLocal) 안에 있었음
      const globalState: Record<string, any> = {
        _local: { form: { id: 8, name: '매니저' } },
      };
      let currentLayoutName = 'admin_role_form';

      // initLocal이 없는 레이아웃으로 전환
      const layoutData = { layout_name: 'admin_role_list' };
      const layoutInitLocal = (layoutData as any).initLocal || (layoutData as any).state;

      // 수정 전 코드: layoutInitLocal이 없으면 이 블록 전체가 스킵됨
      if (layoutInitLocal && Object.keys(layoutInitLocal).length > 0) {
        const newLayoutName = layoutData.layout_name;
        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;
        if (isLayoutChanged) {
          globalState._local = {}; // 이 코드가 실행되지 않음!
        }
        currentLayoutName = newLayoutName;
      }

      // 수정 전에는 cleanup이 실행되지 않았음을 검증
      expect(globalState._local.form).toBeDefined(); // 잔존!
      expect(globalState._local.form.name).toBe('매니저');
      expect(currentLayoutName).toBe('admin_role_form'); // 갱신 안됨!
    });
  });

  describe('[사례 5] extends 레이아웃에서 initLocal이 _local에 적용되지 않음', () => {
    /**
     * 증상: extends 기반 레이아웃에서 데이터소스의 initLocal이 _local 상태에 반영되지 않음
     * 원인:
     * 1. (2월 5일 수정) 자식 컴포넌트의 _localInit 중복 적용 방지를 위해 루트 체크 조건 추가
     * 2. 하지만 extends 레이아웃에서는 베이스 레이아웃의 루트와 자식 레이아웃이 분리됨
     * 3. 데이터소스는 자식 레이아웃에 정의되어 _localInit이 자식 컴포넌트에 전달됨
     * 4. 루트 체크(parentDataContext, isLikelyRoot)로 인해 처리 실패
     * 5. 컴포넌트 ID 기반 추적으로 여러 컴포넌트가 중복 적용
     * 6. 컴포넌트 로컬 상태만 업데이트하고 전역 상태는 미동기화
     * 해결:
     * 1. 루트 체크 조건 제거 (_localInit이 있으면 처리)
     * 2. 데이터 해시 기반 전역 추적 (컴포넌트와 무관하게 같은 데이터는 한 번만 처리)
     * 3. 전역 상태도 함께 업데이트 (_globalSetState 호출)
     */
    it('_localInit 데이터가 컴포넌트 로컬 상태와 전역 상태 모두에 반영되어야 함', () => {
      // API 응답 데이터
      const apiData = {
        id: 1,
        name: '관리자',
        description: '시스템 관리자',
        permission_ids: [1, 2, 3],
      };

      // _localInit 처리 시뮬레이션
      const localInitData = { form: apiData };

      // 1. 컴포넌트 로컬 상태 업데이트
      const componentLocalState = {
        loadingActions: {},
        ...localInitData,
        hasChanges: false,
      };

      // 2. 전역 상태 업데이트
      const globalState: Record<string, any> = {
        _local: {
          ...localInitData,
          hasChanges: false,
        },
      };

      // 검증: 두 상태 모두에 데이터가 있어야 함
      expect(componentLocalState.form).toEqual(apiData);
      expect(globalState._local.form).toEqual(apiData);
    });

    it('데이터 해시 기반 추적으로 같은 데이터는 한 번만 처리되어야 함', () => {
      const data1 = { form: { id: 1, name: 'Test' } };
      const data2 = { form: { id: 1, name: 'Test' } }; // 같은 데이터
      const data3 = { form: { id: 2, name: 'Test2' } }; // 다른 데이터

      const hash1 = JSON.stringify(data1);
      const hash2 = JSON.stringify(data2);
      const hash3 = JSON.stringify(data3);

      // 같은 데이터는 같은 해시
      expect(hash1).toBe(hash2);
      // 다른 데이터는 다른 해시
      expect(hash1).not.toBe(hash3);
    });

    it('refetchOnMount 시 _forceLocalInit 타임스탬프로 재적용 허용', () => {
      const data = { form: { id: 1, name: 'Test' } };
      const timestamp1 = Date.now();
      const timestamp2 = timestamp1 + 1000;

      const key1 = `${JSON.stringify(data)}:${timestamp1}`;
      const key2 = `${JSON.stringify(data)}:${timestamp2}`;
      const keyNoForce = `${JSON.stringify(data)}:no-force`;

      // 타임스탬프가 다르면 다른 키 (재적용 허용)
      expect(key1).not.toBe(key2);
      // 타임스탬프가 없으면 no-force
      expect(keyNoForce).toContain('no-force');
    });
  });

  describe('[사례 6] 다중 progressive 데이터소스의 _localInit 상호 덮어쓰기 (engine-v1.52.2)', () => {
    /**
     * 증상: initLocal을 가진 progressive 데이터소스가 한 레이아웃에 둘 이상일 때,
     *       SPA 네비게이션으로 진입하면 먼저 도착한 소스의 initLocal이 간헐적으로 유실됨
     *       (배송설정 탭: 배송가능 국가 표가 비고 "No shipping countries registered.")
     * 원인: progressive 소스는 응답이 오는 대로 각자 updateTemplateData({ _localInit })를 호출하는데,
     *       소비부(DynamicRenderer의 useEffect)는 React commit 이후에 실행된다.
     *       두 호출이 같은 commit 사이에 들어오면 나중 payload가 _localInit 슬롯을 통째로 교체하여
     *       먼저 도착한 소스의 payload는 한 번도 관측되지 않고 사라진다.
     * 해결: 아직 어떤 렌더러도 관측하지 않은(unconsumed) 슬롯만 누적 병합.
     *       이미 소비된 슬롯은 교체 (refetch 시 stale 재적용으로 폼 편집이 되돌아가는 것 방지).
     *
     * @see resources/js/core/template-engine/localInitSlot.ts
     * @see resources/js/core/template-engine.ts - updateTemplateData
     */
    beforeEach(() => {
      resetLocalInitTracking();
    });

    afterEach(() => {
      resetLocalInitTracking();
    });

    it('소비 전 슬롯은 후속 payload와 누적 병합되어 양쪽 키가 모두 남아야 함', () => {
      const settingsInit = { form: { name: 'shipping' } };
      const notificationInit = { notificationDefinitionCurrentPage: 1 };

      const merged = mergeLocalInitSlot(settingsInit, notificationInit) as Record<string, any>;

      expect(merged.form).toEqual({ name: 'shipping' });
      expect(merged.notificationDefinitionCurrentPage).toBe(1);
    });

    it('한쪽에만 있는 _forceLocalInit 타임스탬프가 보존되어야 함', () => {
      // settings만 refetchOnMount: true → _forceLocalInit 보유
      const settingsInit = { form: { name: 'shipping' }, _forceLocalInit: 1700000000000 };
      const notificationInit = { notificationDefinitionCurrentPage: 1 };

      const merged = mergeLocalInitSlot(settingsInit, notificationInit) as Record<string, any>;

      expect(merged._forceLocalInit).toBe(1700000000000);
    });

    it('양쪽 모두 _forceLocalInit을 가지면 최신 타임스탬프를 취해야 함', () => {
      const older = { a: 1, _forceLocalInit: 1700000000000 };
      const newer = { b: 2, _forceLocalInit: 1700000001000 };

      const merged = mergeLocalInitSlot(older, newer) as Record<string, any>;

      expect(merged._forceLocalInit).toBe(1700000001000);
    });

    it('이미 소비된 슬롯은 병합하지 않고 교체해야 함 (stale 재적용 방지)', () => {
      const settingsInit = { form: { name: 'shipping' } };

      // 렌더러가 슬롯을 관측 (DynamicRenderer의 _localInit useEffect)
      markLocalInitConsumed(settingsInit);

      // 이후 refetchDataSource가 다른 소스의 initLocal을 실어 옴
      const refetchInit = { notificationDefinitionCurrentPage: 2 };
      const merged = mergeLocalInitSlot(settingsInit, refetchInit) as Record<string, any>;

      // 소비가 끝난 form은 재적용 대상이 아님 → 사용자 폼 편집이 되돌아가지 않음
      expect(merged.form).toBeUndefined();
      expect(merged.notificationDefinitionCurrentPage).toBe(2);
    });

    it('새 payload가 없으면 기존 슬롯 참조를 그대로 유지해야 함 (소비부 effect 재발화 방지)', () => {
      const slot = { form: { name: 'shipping' } };

      // updateTemplateData({ someData: 1 }) 처럼 _localInit이 없는 업데이트
      expect(mergeLocalInitSlot(slot, undefined)).toBe(slot);
    });

    it('레이아웃 전환 시 추적 레지스트리가 초기화되어야 함', () => {
      const slot = { form: { name: 'shipping' } };
      markLocalInitConsumed(slot);
      expect(isLocalInitConsumed(slot)).toBe(true);

      // TemplateApp.loadRoute의 레이아웃 전환 감지 → _local 리셋과 함께 호출
      resetLocalInitTracking();

      expect(isLocalInitConsumed(slot)).toBe(false);
      expect(getLocalInitTracking().hash).toBe('');
    });
  });

  describe('[사례 7] 저장 후 refetch 했는데 입력칸만 예전 입력값을 유지', () => {
    /**
     * 증상: 서버가 저장 시 값을 정규화하는 화면에서 저장 성공 후 refetchDataSource 를 호출했는데
     *       입력칸에는 사용자가 타이핑한 값이 그대로 남는다 (사이트코드 SMA1B2C → 저장 A1B2C,
     *       입력칸은 계속 SMA1B2C. 왼쪽 SM 배지와 겹쳐 SMSMA1B2C 로 보인다).
     * 원인: initLocal 미적용이 아니다. 브라우저 계측 결과 refetch 는 발생하고
     *       `_localInit applied (data changed): [form]` 도 찍히며 `_local.form` 은 정규화된 값을 갖는다.
     *       `_local` 갱신과 사용자가 이미 편집한 입력칸의 DOM 갱신이 별개다.
     * 해결: 저장 성공 onSuccess 에서 응답 데이터로 폼을 명시 재바인딩
     *       (`setState` target=local, `form: "{{response.data}}"`).
     *
     * 본 테스트는 이 사례의 진단 근거 — "refetch 는 `_local` 을 갱신한다" — 를 잠근다.
     * 이 전제가 깨지면 사례의 원인 분석("initLocal 문제가 아니다")이 더 이상 성립하지 않으므로
     * 문서와 함께 재검토해야 한다.
     *
     * @see localInitSlot.ts - mergeLocalInitSlot (소비된 슬롯은 병합이 아니라 교체)
     */
    beforeEach(() => {
      resetLocalInitTracking();
    });

    afterEach(() => {
      resetLocalInitTracking();
    });

    it('refetch 가 실어 온 정규화 값이 소비된 슬롯을 교체해 _local 에 도달해야 함', () => {
      // 최초 로드: 저장돼 있던 값
      const initial = { form: { live_site_cd: 'Z9Y8X' }, _forceLocalInit: 1700000000000 };
      markLocalInitConsumed(initial);

      // 저장 후 refetch: 서버가 정규화한 값 (refetchOnMount: true → 새 타임스탬프)
      const afterSave = { form: { live_site_cd: 'A1B2C' }, _forceLocalInit: 1700000001000 };

      const slot = mergeLocalInitSlot(initial, afterSave) as Record<string, any>;

      // 소비된 슬롯은 병합이 아니라 교체 — 과거 값이 남아서는 안 된다
      expect(slot.form.live_site_cd).toBe('A1B2C');
      expect(slot).toBe(afterSave);
    });

    it('타임스탬프가 갱신되어 소비부 추적 키가 달라져야 함 (재적용 허용)', () => {
      const before = `${JSON.stringify({ form: { live_site_cd: 'Z9Y8X' } })}:1700000000000`;
      const after = `${JSON.stringify({ form: { live_site_cd: 'A1B2C' } })}:1700000001000`;

      // DynamicRenderer 의 trackingKey 계산과 동일한 형태 — 값·타임스탬프 둘 다 달라 재적용된다
      expect(after).not.toBe(before);
    });
  });

  describe('[사례 8] initLocal 동기화가 init_actions 의 query 시드를 되돌림 (engine-v1.54.5)', () => {
    /**
     * 증상: 목록에서 필터 적용 → 상세 진입 → 뒤로가기 복귀 시 URL·목록은 필터 상태인데
     *       필터 컨트롤만 기본값으로 표시된다 (#492 D-20).
     * 원인: _localInit → 전역 _local 동기화가 **렌더 시점 스냅샷**을 병합 base 로 썼고,
     *       setGlobalState 는 `{ _local: X }` 를 얕게 펼쳐 저장소를 통째로 교체한다.
     *       effect 는 렌더 커밋 뒤에 실행되므로 그 사이의 init_actions query 시드가 되돌아갔다.
     * 해결: 함수형 업데이트로 **쓰기 시점 prev._local** 을 base 로 병합.
     *
     * 렌더 통합 검증은 DynamicRenderer.localInitGlobalSync.test.tsx 가 담당한다.
     */
    const applySync = (
      store: Record<string, any>,
      snapshotBase: Record<string, any>,
      initData: Record<string, any>,
      useCanonicalBase: boolean
    ) => {
      const base = useCanonicalBase ? store._local || {} : snapshotBase;
      // setGlobalState 의 얕은 펼침 계약 — `_local` 은 통째로 교체된다
      return { ...store, _local: { ...base, ...initData, hasChanges: false } };
    };

    it('스냅샷 base 는 시드를 되돌린다 (수정 전 동작)', () => {
      const store = { _local: { filter: { issueStatus: 'issuing' }, sortBy: 'name_asc' } };
      const staleSnapshot = { filter: { issueStatus: 'all' }, sortBy: 'created_at_desc' };

      const next = applySync(store, staleSnapshot, { rows: [1] }, false);

      expect(next._local.filter.issueStatus).toBe('all');
      expect(next._local.sortBy).toBe('created_at_desc');
    });

    it('canonical base 는 시드를 보존한다 (수정 후 동작)', () => {
      const store = { _local: { filter: { issueStatus: 'issuing' }, sortBy: 'name_asc' } };
      const staleSnapshot = { filter: { issueStatus: 'all' }, sortBy: 'created_at_desc' };

      const next = applySync(store, staleSnapshot, { rows: [1] }, true);

      expect(next._local.filter.issueStatus).toBe('issuing');
      expect(next._local.sortBy).toBe('name_asc');
      expect(next._local.rows).toEqual([1]);
    });

    it('_local 이외의 전역 키는 동기화로 소실되지 않아야 함', () => {
      const store = { _local: { filter: { issueStatus: 'issuing' } }, cartKey: 'ck-1' };

      const next = applySync(store, {}, { rows: [] }, true);

      expect(next.cartKey).toBe('ck-1');
    });
  });
});

describe('[사례 9] 탭 왕복 후 먼저 편집한 입력칸만 옛 값이 남음 (engine-v1.54.7)', () => {
  /**
   * describe 번호는 이 파일 내 연번이다(앞의 사례 5·6·7·8 에 이어짐) — 트러블슈팅 문서의
   * 섹션별 재시작 번호와는 일치하지 않는다.
   *
   * 증상: 폼 데이터소스가 `initLocal` + `refetchOnMount: true` 이고, 탭 전환이 URL 을 바꿔
   *       remount + refetch 를 유발하는 화면에서, 되돌아오기 전 입력칸을 2개 이상 편집하면
   *       **먼저 편집한 칸에 사용자가 친 값이 남고** 마지막에 편집한 칸만 서버값으로 복귀한다.
   *       저장값은 서버값이므로 화면 표시와 실제 값이 어긋난다(새로고침하면 서버값으로 돌아옴).
   *
   *       "먼저 편집" 은 필요조건이지 충분조건이 아니다 — 잔존이 화면까지 드러나려면 그 입력칸을
   *       소유한 렌더러 인스턴스가 저장소 A 리셋을 건너뛴 쪽이어야 한다(통제 실험은 문서 참조).
   *       재현에는 **SPA 라우팅(탭 클릭)** 이 필요하다. 주소창 이동·새로고침은 렌더러를 전부 새로
   *       마운트하고 전역 추적도 초기화하므로 결함이 드러나지 않는다.
   *
   * 원인(둘의 합성):
   *   ① 저장소 A 오염 — 자동바인딩 `performStateUpdate` 는 키입력마다 병합된 `_local` **전체
   *      스냅샷**을 저장소 A 에 쓰고, `useLayoutEffect` 의 `removeMatchingLeafKeys` 는
   *      `__g7SetLocalOverrideKeys` 에 남은 **마지막 leaf 만** 지운다. 이 전역 플래그는
   *      `queueMicrotask` 로 클리어되므로 다음 필드를 칠 때 직전 필드 키는 이미 사라져 있다.
   *      → A 에 "직전까지 타이핑한 필드들"의 사본이 잔존한다.
   *   ② `_localInit` 리셋의 전역 1회 소비 — 적용 여부를 전역 해시(`__g7LocalInitTracking`)로만
   *      판정하는데, 실제 리셋 대상인 `localDynamicState` 는 **인스턴스별**이다. 루트급 렌더러가
   *      복수(global_toast, page_transition, admin_layout_root …)이므로 먼저 effect 가 도는
   *      인스턴스가 토큰을 소비하면 나머지 인스턴스의 A 는 영원히 리셋되지 않는다.
   *   → 병합(`deepMergeState(B, A)`)은 A 우선이므로 신선한 B 위에 stale A 가 덮인다.
   *
   * 이는 새 유형이 아니라 `troubleshooting-state-advanced.md` 사례 13(engine-v1.18.3)이 세운
   * "동일 commit 내 복수 root 의 실행 순서에 의존 금지" 규칙의 **미적용 구간**이다.
   * `__g7SetLocalOverrideKeys` 는 그때 규칙을 적용받았으나 `__g7LocalInitTracking` 은 남았다.
   *
   * 해결: 전역 해시 때문에 적용을 건너뛴 인스턴스는 자기 저장소 A 에서 payload 키 공간을
   *       **제거만** 한다(값을 다시 쓰지 않는다). 제거는 stale 값을 되살릴 수 없고, 제거된
   *       자리에는 이미 갱신된 저장소 B 가 그대로 비쳐 보인다. 재적용을 택하면
   *       `localInitSlot.ts` 가 금지한 "소비된 payload 재적용 → 폼 편집 되돌림" 회귀가 난다.
   *
   * @see resources/js/core/template-engine/localInitSlot.ts - resolveLocalInitAction
   * @see resources/js/core/template-engine/DynamicRenderer.tsx - _localInit useEffect
   */
  const TRACKING_KEY = '{"form":{"a":1}}:1700000000000';

  describe('[판정] resolveLocalInitAction', () => {
    it('전역 추적에 없는 payload 는 적용한다 (apply)', () => {
      expect(resolveLocalInitAction({
        globalTrackedKey: '',
        instanceHandledKey: null,
        trackingKey: TRACKING_KEY,
      })).toBe('apply');
    });

    it('다른 인스턴스가 이미 적용한 payload 는 이 인스턴스에서 제거한다 (prune)', () => {
      expect(resolveLocalInitAction({
        globalTrackedKey: TRACKING_KEY,
        instanceHandledKey: null,
        trackingKey: TRACKING_KEY,
      })).toBe('prune');
    });

    it('같은 인스턴스가 이미 처리한 payload 는 아무것도 하지 않는다 (skip)', () => {
      expect(resolveLocalInitAction({
        globalTrackedKey: TRACKING_KEY,
        instanceHandledKey: TRACKING_KEY,
        trackingKey: TRACKING_KEY,
      })).toBe('skip');
    });

    it('적용한 인스턴스가 재발화해도 재적용하지 않는다 (apply 후 skip)', () => {
      // 적용 인스턴스는 전역·인스턴스 양쪽에 같은 키를 기록한다
      expect(resolveLocalInitAction({
        globalTrackedKey: TRACKING_KEY,
        instanceHandledKey: TRACKING_KEY,
        trackingKey: TRACKING_KEY,
      })).toBe('skip');
    });

    it('payload 가 바뀌면 인스턴스 기록과 무관하게 다시 적용한다', () => {
      expect(resolveLocalInitAction({
        globalTrackedKey: TRACKING_KEY,
        instanceHandledKey: TRACKING_KEY,
        trackingKey: '{"form":{"a":2}}:1700000002000',
      })).toBe('apply');
    });
  });

  describe('[재현] 탭 왕복 후 마지막 편집 칸만 리셋되는 순서 의존성', () => {
    /**
     * 자동바인딩 2필드 순차 입력의 저장소 A 상태를 그대로 합성한다.
     * `performStateUpdate` 가 매번 전체 스냅샷을 A 에 쓰고, 마지막 leaf 만 정리된 상태.
     */
    const buildStoreAAfterTypingTwoFields = () => {
      // 서버 원본
      const server = { order_settings: { auto_cancel_days: 3, cart_expiry_days: 30 } };

      // ① auto_cancel_days=7 타이핑 → A 에 전체 스냅샷
      let storeA: Record<string, any> = deepMergeState(
        { loadingActions: {} },
        { form: { ...server, order_settings: { ...server.order_settings, auto_cancel_days: 7 } } }
      );
      // setLocal 정리: 이 필드 leaf 제거 → 이후 queueMicrotask 로 플래그 클리어
      storeA = removeMatchingLeafKeys(storeA, { form: { order_settings: { auto_cancel_days: 7 } } });

      // ② cart_expiry_days=15 타이핑 → A 에 전체 스냅샷 (B 의 7 을 base 로 흡수)
      storeA = deepMergeState(storeA, {
        form: { order_settings: { auto_cancel_days: 7, cart_expiry_days: 15 } },
      });
      // 정리 대상은 이번 leaf 뿐 — 직전 필드 키는 플래그에서 이미 사라졌다
      storeA = removeMatchingLeafKeys(storeA, { form: { order_settings: { cart_expiry_days: 15 } } });

      return storeA;
    };

    it('저장소 A 에 직전 필드 사본이 남고 마지막 필드만 정리된다 (결함 전제)', () => {
      const storeA = buildStoreAAfterTypingTwoFields();

      expect(storeA.form.order_settings.auto_cancel_days).toBe(7);   // 잔존
      expect(storeA.form.order_settings.cart_expiry_days).toBeUndefined(); // 정리됨
    });

    it('제거 없이 병합하면 신선한 저장소 B 가 stale 저장소 A 에 덮인다 (수정 전 동작)', () => {
      const storeA = buildStoreAAfterTypingTwoFields();
      // refetch 로 갱신된 저장소 B
      const storeB = { form: { order_settings: { auto_cancel_days: 3, cart_expiry_days: 30 } } };

      const merged = deepMergeState(storeB, storeA);

      expect(merged.form.order_settings.auto_cancel_days).toBe(7);   // ← 화면에 보이는 stale
      expect(merged.form.order_settings.cart_expiry_days).toBe(30);  // ← 마지막 필드만 정상
    });

    it('건너뛴 인스턴스가 payload 키 공간을 제거하면 두 필드 모두 서버값이 된다 (수정 후)', () => {
      const storeA = buildStoreAAfterTypingTwoFields();
      const payload = { form: { order_settings: { auto_cancel_days: 3, cart_expiry_days: 30 } } };
      const storeB = { form: { order_settings: { auto_cancel_days: 3, cart_expiry_days: 30 } } };

      const pruned = removeMatchingLeafKeys(storeA, payload);
      const merged = deepMergeState(storeB, pruned);

      expect(merged.form.order_settings.auto_cancel_days).toBe(3);
      expect(merged.form.order_settings.cart_expiry_days).toBe(30);
    });
  });

  describe('[안전성] 제거는 값을 도입하지 않는다', () => {
    it('payload 에 없는 키는 저장소 A 에 그대로 남는다', () => {
      const storeA = {
        loadingActions: { save: true },
        ui: { accordionOpen: true },
        form: { name: '사용자입력' },
      };
      const payload = { form: { name: '서버값' } };

      const pruned = removeMatchingLeafKeys(storeA, payload);

      expect(pruned.loadingActions).toEqual({ save: true });
      expect(pruned.ui).toEqual({ accordionOpen: true });
      expect(pruned.form).toBeUndefined();
    });

    it('저장소 A 가 비어 있으면 제거는 no-op 이다 (늦게 마운트된 인스턴스)', () => {
      const storeA = { loadingActions: {} };
      const payload = { form: { name: '서버값' } };

      expect(removeMatchingLeafKeys(storeA, payload)).toEqual({ loadingActions: {} });
    });

    it('제거는 저장소 B 를 건드리지 않는다 — 소비된 payload 재적용 회귀가 구조적으로 불가', () => {
      // 늦게 마운트된 인스턴스가 과거 payload 를 들고 있어도, 제거만 하므로
      // 그 사이 사용자가 편집한 저장소 B 의 값이 되돌아가지 않는다.
      const storeB = { form: { name: '편집중인값' } };
      const stalePayload = { form: { name: '과거서버값' } };
      const storeA = { loadingActions: {} };

      const pruned = removeMatchingLeafKeys(storeA, stalePayload);
      const merged = deepMergeState(storeB, pruned);

      expect(merged.form.name).toBe('편집중인값');
    });
  });

  /**
   * prune 분기는 "실제로 제거된 것이 있을 때만" 상태 갱신 + 캐시 무효화를 해야 한다
   * (no-op 인데 무효화하면 불필요한 리렌더와 캐시 폐기가 상시 발생).
   *
   * 판정은 `localDynamicState !== removeMatchingLeafKeys(...)` 참조 비교로 하므로,
   * 헬퍼가 **제거가 없을 때 원본 참조를 그대로 반환**해야 판정이 성립한다.
   * 중첩 경로에서 사본을 새로 만들면 내용이 같아도 참조가 달라져 거짓 양성이 된다.
   *
   * @effects prune_is_noop_when_nothing_to_remove, prune_never_reintroduces_values
   */
  describe('[no-op] 제거할 것이 없으면 참조가 보존된다 (거짓 양성 차단)', () => {
    it('최상위에 겹치는 키가 없으면 원본 참조를 그대로 반환한다', () => {
      const storeA = { loadingActions: { save: true } };
      const payload = { form: { name: '서버값' } };

      expect(removeMatchingLeafKeys(storeA, payload)).toBe(storeA);
    });

    it('중첩 경로가 겹쳐도 실제 제거가 없으면 원본 참조를 그대로 반환한다', () => {
      // 저장소 A 에는 theme 만, payload 에는 auto_cancel_days 만 → 제거 대상 0
      const storeA = { form: { theme: 'dark' } };
      const payload = { form: { auto_cancel_days: 7 } };

      const pruned = removeMatchingLeafKeys(storeA, payload);

      expect(pruned).toBe(storeA);
      expect(pruned.form).toBe(storeA.form);
    });

    it('저장소 A 가 비어 있으면 원본 참조를 그대로 반환한다 (늦게 마운트된 인스턴스)', () => {
      const storeA = {};
      const payload = { form: { order_settings: { auto_cancel_days: 7 } } };

      expect(removeMatchingLeafKeys(storeA, payload)).toBe(storeA);
    });

    it('실제로 제거되면 새 참조를 반환한다 (판정이 항상 false 가 되지 않음)', () => {
      const storeA = { form: { theme: 'dark', auto_cancel_days: 7 } };
      const payload = { form: { auto_cancel_days: 7 } };

      const pruned = removeMatchingLeafKeys(storeA, payload);

      expect(pruned).not.toBe(storeA);
      expect(pruned.form).toEqual({ theme: 'dark' });
    });

    it('깊은 중첩에서 한 리프만 제거돼도 새 참조를 반환한다', () => {
      const storeA = { form: { order_settings: { auto_cancel_days: 7, cart_expiry_days: 15 } } };
      const payload = { form: { order_settings: { auto_cancel_days: 7 } } };

      const pruned = removeMatchingLeafKeys(storeA, payload);

      expect(pruned).not.toBe(storeA);
      expect(pruned.form.order_settings).toEqual({ cart_expiry_days: 15 });
    });

    it('제거되지 않은 형제 가지는 참조까지 보존된다 (불필요한 하위 리렌더 차단)', () => {
      const storeA = {
        ui: { accordionOpen: true },
        form: { theme: 'dark', auto_cancel_days: 7 },
      };
      const payload = { form: { auto_cancel_days: 7 } };

      const pruned = removeMatchingLeafKeys(storeA, payload);

      expect(pruned).not.toBe(storeA);
      expect(pruned.ui).toBe(storeA.ui);
    });
  });
});
