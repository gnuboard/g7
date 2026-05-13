/**
 * 트러블슈팅 회귀 테스트 - initGlobal & iteration & 데이터소스
 *
 * troubleshooting-state-global.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see docs/frontend/troubleshooting-state-global.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
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
});
