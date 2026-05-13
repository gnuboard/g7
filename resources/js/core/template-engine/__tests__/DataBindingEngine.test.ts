import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine, DataBindingError } from '../DataBindingEngine';

describe('DataBindingEngine', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('resolveBindings', () => {
    it('단일 변수 바인딩', () => {
      const context = { name: '홍길동' };
      const result = engine.resolveBindings('Hello {{name}}', context);
      expect(result).toBe('Hello 홍길동');
    });

    it('중첩 객체 접근', () => {
      const context = {
        user: {
          profile: {
            name: '홍길동',
          },
        },
      };
      const result = engine.resolveBindings('Name: {{user.profile.name}}', context);
      expect(result).toBe('Name: 홍길동');
    });

    it('배열 인덱스 접근', () => {
      const context = {
        items: ['A', 'B', 'C'],
      };
      const result = engine.resolveBindings('First: {{items[0]}}, Second: {{items[1]}}', context);
      expect(result).toBe('First: A, Second: B');
    });

    it('여러 바인딩 처리', () => {
      const context = {
        firstName: '길동',
        lastName: '홍',
      };
      const result = engine.resolveBindings('{{lastName}} {{firstName}}님', context);
      expect(result).toBe('홍 길동님');
    });

    it('존재하지 않는 변수는 빈 문자열 반환', () => {
      const context = {};
      const result = engine.resolveBindings('Hello {{name}}', context);
      expect(result).toBe('Hello ');
    });

    it('숫자 값 바인딩', () => {
      const context = { age: 30, price: 1000 };
      const result = engine.resolveBindings('Age: {{age}}, Price: {{price}}', context);
      expect(result).toBe('Age: 30, Price: 1000');
    });

    it('불리언 값 바인딩', () => {
      const context = { isActive: true, isHidden: false };
      const result = engine.resolveBindings('Active: {{isActive}}, Hidden: {{isHidden}}', context);
      expect(result).toBe('Active: true, Hidden: false');
    });
  });

  describe('resolve', () => {
    it('단일 경로 해석', () => {
      const context = { name: '홍길동' };
      const result = engine.resolve('name', context);
      expect(result).toBe('홍길동');
    });

    it('중첩 경로 해석', () => {
      const context = {
        user: {
          email: 'hong@example.com',
        },
      };
      const result = engine.resolve('user.email', context);
      expect(result).toBe('hong@example.com');
    });

    it('배열 경로 해석', () => {
      const context = {
        data: {
          items: [10, 20, 30],
        },
      };
      const result = engine.resolve('data.items[1]', context);
      expect(result).toBe(20);
    });

    it('기본값 옵션', () => {
      const context = {};
      const result = engine.resolve('missing', context, { defaultValue: 'N/A' });
      expect(result).toBe('N/A');
    });

    it('null-safe 모드', () => {
      const context = { user: null };
      const result = engine.resolve('user.name', context, { nullSafe: true });
      expect(result).toBeUndefined();
    });
  });

  describe('에러 처리', () => {
    it('순환 참조 감지', () => {
      const context: any = { a: {} };
      context.a.b = context.a; // 순환 참조

      expect(() => {
        engine.resolve('a.b.c', context, { detectCircular: true });
      }).toThrow(DataBindingError);
    });

    it('깊은 중첩 경로 접근', () => {
      const context = {
        level1: {
          level2: {
            level3: {
              level4: {
                level5: {
                  level6: {
                    level7: {
                      level8: {
                        level9: {
                          level10: {
                            level11: 'deep value',
                          },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
        },
      };

      // 깊은 경로도 정상 접근 가능
      const result = engine.resolve(
        'level1.level2.level3.level4.level5.level6.level7.level8.level9.level10.level11',
        context
      );
      expect(result).toBe('deep value');
    });

    it('null-safe 모드 비활성화 시 에러', () => {
      const context = { user: null };

      expect(() => {
        engine.resolve('user.name', context, { nullSafe: false });
      }).toThrow(DataBindingError);
    });
  });

  describe('캐싱', () => {
    it('캐시 저장 및 조회', () => {
      const context = { name: '홍길동' };

      // 첫 번째 호출 (캐시 저장)
      const result1 = engine.resolve('name', context);

      // 두 번째 호출 (캐시에서 조회)
      const result2 = engine.resolve('name', context);

      expect(result1).toBe(result2);
      expect(result1).toBe('홍길동');
    });

    it('캐시 초기화', () => {
      const context = { name: '홍길동' };

      engine.resolve('name', context);
      const stats1 = engine.getCacheStats();
      expect(stats1.size).toBeGreaterThan(0);

      engine.clearCache();
      const stats2 = engine.getCacheStats();
      expect(stats2.size).toBe(0);
    });

    it('캐시 통계', () => {
      const context = { name: '홍길동', age: 30 };

      engine.resolve('name', context);
      engine.resolve('age', context);

      const stats = engine.getCacheStats();
      expect(stats.size).toBe(2);
    });

    it('_global.* 경로는 캐시하지 않음', () => {
      const context = { _global: { count: 1 } };

      engine.resolve('_global.count', context);
      const stats1 = engine.getCacheStats();

      // _global 경로는 캐시되지 않음
      expect(stats1.size).toBe(0);

      // 컨텍스트 값을 변경해도 새 값 반환
      context._global.count = 2;
      const result = engine.resolve('_global.count', context);
      expect(result).toBe(2);
    });

    it('_isolated.* 경로는 캐시하지 않음', () => {
      const context = { _isolated: { count: 1 } };

      engine.resolve('_isolated.count', context);
      const stats1 = engine.getCacheStats();

      // _isolated 경로는 캐시되지 않음
      expect(stats1.size).toBe(0);

      // 컨텍스트 값을 변경해도 새 값 반환
      context._isolated.count = 2;
      const result = engine.resolve('_isolated.count', context);
      expect(result).toBe(2);
    });

    it('_local.* 경로는 영구 캐시하지 않음 (렌더 사이클 캐시만 사용)', () => {
      const context = { _local: { count: 1 } };

      engine.resolve('_local.count', context);
      const stats = engine.getCacheStats();

      // _local 경로는 영구 캐시(30s TTL)에 저장하지 않음
      // 동적으로 자주 변경되므로 렌더 사이클 캐시만 사용
      // (startRenderCycle() 호출 후에만 활성화)
      expect(stats.size).toBe(0);
    });
  });

  describe('복잡한 데이터 구조', () => {
    it('객체 배열 접근', () => {
      const context = {
        users: [
          { name: '홍길동', age: 30 },
          { name: '김철수', age: 25 },
        ],
      };

      const result = engine.resolve('users[0].name', context);
      expect(result).toBe('홍길동');
    });

    it('다차원 배열 접근', () => {
      const context = {
        matrix: [
          [1, 2, 3],
          [4, 5, 6],
        ],
      };

      const result = engine.resolve('matrix[1][2]', context);
      expect(result).toBe(6);
    });

    it('복합 경로', () => {
      const context = {
        company: {
          departments: [
            {
              name: '개발팀',
              members: [
                { name: '홍길동', role: 'Frontend' },
                { name: '김철수', role: 'Backend' },
              ],
            },
          ],
        },
      };

      const result = engine.resolve('company.departments[0].members[0].name', context);
      expect(result).toBe('홍길동');
    });
  });

  describe('evaluateExpression', () => {
    it('기본 표현식 평가', () => {
      const context = { count: 5 };
      const result = engine.evaluateExpression('count + 10', context);
      expect(result).toBe(15);
    });

    it('삼항 연산자 평가', () => {
      const context = { isActive: true };
      const result = engine.evaluateExpression('isActive ? "활성" : "비활성"', context);
      expect(result).toBe('활성');
    });

    it('논리 연산자 평가', () => {
      const context = { a: true, b: false };
      expect(engine.evaluateExpression('a && b', context)).toBe(false);
      expect(engine.evaluateExpression('a || b', context)).toBe(true);
      expect(engine.evaluateExpression('!b', context)).toBe(true);
    });

    it('비교 연산자 평가', () => {
      const context = { value: 10 };
      expect(engine.evaluateExpression('value > 5', context)).toBe(true);
      expect(engine.evaluateExpression('value < 5', context)).toBe(false);
      expect(engine.evaluateExpression('value === 10', context)).toBe(true);
    });

    it('optional chaining 평가', () => {
      const context = { user: { name: '홍길동' } };
      expect(engine.evaluateExpression('user?.name', context)).toBe('홍길동');
      expect(engine.evaluateExpression('user?.email', context)).toBeUndefined();
    });

    it('nullish coalescing 평가', () => {
      const context = { value: null, defaultValue: '기본값' };
      expect(engine.evaluateExpression('value ?? defaultValue', context)).toBe('기본값');
    });

    it('미정의 변수에 대한 optional chaining 처리', () => {
      // users가 컨텍스트에 없는 경우
      const context = { otherData: 'test' };
      // users?.data?.total ?? 0 표현식이 에러 없이 평가되어야 함
      const result = engine.evaluateExpression('users?.data?.total ?? 0', context);
      expect(result).toBe(0);
    });

    it('미정의 변수에 대한 nullish coalescing 처리', () => {
      const context = {};
      const result = engine.evaluateExpression('undefinedVar ?? "기본값"', context);
      expect(result).toBe('기본값');
    });

    it('복합 표현식에서 미정의 변수 처리', () => {
      const context = { existingVar: 10 };
      // missingVar가 없어도 에러 없이 평가
      const result = engine.evaluateExpression('missingVar?.value ?? existingVar', context);
      expect(result).toBe(10);
    });

    it('중첩 객체 접근 표현식', () => {
      const context = {
        users: {
          data: {
            total: 100,
            from: 1,
            to: 10,
          },
        },
      };
      expect(engine.evaluateExpression('users?.data?.total ?? 0', context)).toBe(100);
      expect(engine.evaluateExpression('users?.data?.from ?? 0', context)).toBe(1);
      expect(engine.evaluateExpression('users?.data?.to ?? 0', context)).toBe(10);
    });

    it('데이터 로드 전후 시뮬레이션', () => {
      // 로드 전: users가 없음
      const contextBefore = { route: { id: '1' } };
      const resultBefore = engine.evaluateExpression('users?.data?.total ?? 0', contextBefore);
      expect(resultBefore).toBe(0);

      // 로드 후: users가 있음
      const contextAfter = {
        route: { id: '1' },
        users: {
          data: {
            total: 50,
            from: 1,
            to: 10,
          },
        },
      };
      const resultAfter = engine.evaluateExpression('users?.data?.total ?? 0', contextAfter);
      expect(resultAfter).toBe(50);
    });
  });

  describe('resolveBindings with expressions', () => {
    it('표현식 바인딩 처리', () => {
      const context = { count: 5 };
      const result = engine.resolveBindings('Total: {{count > 0 ? count : "없음"}}', context);
      expect(result).toBe('Total: 5');
    });

    it('미정의 변수가 포함된 표현식 바인딩', () => {
      const context = {};
      const result = engine.resolveBindings('Value: {{missingVar ?? "기본"}}', context);
      expect(result).toBe('Value: 기본');
    });

    it('optional chaining이 포함된 표현식 바인딩', () => {
      const context = {};
      const result = engine.resolveBindings('Total: {{users?.data?.total ?? 0}}', context);
      expect(result).toBe('Total: 0');
    });
  });

  describe('$t: 토큰 전처리 (preprocessTranslationTokens)', () => {
    it('표현식 내 $t: 패턴을 문자열로 자동 변환', () => {
      const context = { _global: {} };
      const result = engine.evaluateExpression('_global.errorMessage || $t:common.error', context);
      expect(result).toBe('$t:common.error');
    });

    it('값이 있으면 $t: 대신 해당 값 반환', () => {
      const context = { _global: { errorMessage: '실제 에러 메시지' } };
      const result = engine.evaluateExpression('_global.errorMessage || $t:common.error', context);
      expect(result).toBe('실제 에러 메시지');
    });

    it('삼항 연산자에서 $t: 패턴 처리', () => {
      const context = { isError: true };
      const result = engine.evaluateExpression('isError ? $t:common.error : $t:common.success', context);
      expect(result).toBe('$t:common.error');
    });

    it('따옴표로 감싸진 $t: 패턴은 $t() 함수 호출로 변환되어 번역됨', () => {
      const context = { _global: {}, $templateId: 'test-template', $locale: 'ko' };
      const result = engine.evaluateExpression('_global.msg || "$t:common.test"', context);
      // TranslationEngine이 로드되지 않은 경우 키를 그대로 반환
      expect(typeof result).toBe('string');
    });

    it('중첩된 키 경로의 $t: 패턴 처리', () => {
      const context = { showError: false };
      const result = engine.evaluateExpression('showError ? $t:admin.users.error_message : $t:admin.users.success_message', context);
      expect(result).toBe('$t:admin.users.success_message');
    });

    it('하이픈 포함 모듈키 $t: 패턴 처리 (따옴표 없음)', () => {
      const context = { _global: {} };
      const result = engine.evaluateExpression('_global.msg || $t:sirsoft-page.admin.page.published', context);
      expect(result).toBe('$t:sirsoft-page.admin.page.published');
    });

    it('하이픈 포함 모듈키 $t: 패턴 처리 (따옴표 안)', () => {
      const context = { isPublished: true, $templateId: '', $locale: 'ko' };
      const result = engine.evaluateExpression(
        "isPublished ? '$t:sirsoft-page.admin.page.published_status.published' : '$t:sirsoft-page.admin.page.published_status.unpublished'",
        context
      );
      // $t() 함수로 변환되어 TranslationEngine.translate() 호출
      // TranslationEngine이 해당 키를 찾지 못하면 키 자체를 반환
      expect(typeof result).toBe('string');
      // 중요: 따옴표 안의 $t: 패턴이 $t() 함수 호출로 변환되어야 함
      // 변환 실패 시 결과가 "$t:sirsoft-page..." 문자열 리터럴이 됨
      // 변환 성공 시 TranslationEngine.translate() 결과가 반환됨 (키 못 찾으면 키 반환)
      expect(result).not.toContain("'$t:");  // 따옴표가 남아있으면 변환 실패
    });

    it('하이픈 포함 모듈키 삼항 연산자 (따옴표 없음)', () => {
      const context = { flag: false };
      const result = engine.evaluateExpression('flag ? $t:sirsoft-board.admin.key1 : $t:sirsoft-board.admin.key2', context);
      expect(result).toBe('$t:sirsoft-board.admin.key2');
    });

    // @since engine-v1.38.2
    // 회귀 방지: action context 가 $templateId 를 누락한 경우에도
    // window.__templateApp.getConfig() 로부터 회수하여 $t() 가 정상 동작해야 한다.
    // (admin_module_list 토스트가 raw key 를 노출하던 버그)
    it('$templateId 누락 시 window.__templateApp.getConfig() 로부터 fallback', async () => {
      const { TranslationEngine } = await import('../TranslationEngine');
      const te = TranslationEngine.getInstance();
      // 테스트 격리: 직접 translations 맵에 데이터 주입
      (te as any).translations.set('sirsoft-admin_basic:ko', {
        admin: { modules: { activate_success: '모듈 활성화 성공' } },
      });

      const originalTemplateApp = (globalThis as any).__templateApp;
      (globalThis as any).__templateApp = {
        getConfig: () => ({ templateId: 'sirsoft-admin_basic', locale: 'ko' }),
      };

      try {
        // action context 에는 $templateId 가 없음 (실제 dispatchAction 컨텍스트 시뮬레이션)
        const context = { $event: { target: { checked: true } } };
        const result = engine.evaluateExpression(
          "$event.target.checked ? '$t:admin.modules.activate_success' : '$t:admin.modules.deactivate_success'",
          context
        );
        expect(result).toBe('모듈 활성화 성공');
      } finally {
        (globalThis as any).__templateApp = originalTemplateApp;
        (te as any).translations.delete('sirsoft-admin_basic:ko');
      }
    });
  });

  describe('$localized 헬퍼 함수', () => {
    it('문자열 값은 그대로 반환', () => {
      const context = {
        _global: { moduleName: '샘플 모듈' },
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$localized(_global.moduleName)', context);
      expect(result).toBe('샘플 모듈');
    });

    it('다국어 객체에서 현재 로케일 값 반환 (ko)', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '게시판 모듈', en: 'Board Module' },
          },
        },
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$localized(_global.selectedModule.name)', context);
      expect(result).toBe('게시판 모듈');
    });

    it('다국어 객체에서 현재 로케일 값 반환 (en)', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '게시판 모듈', en: 'Board Module' },
          },
        },
        $locale: 'en',
      };
      const result = engine.evaluateExpression('$localized(_global.selectedModule.name)', context);
      expect(result).toBe('Board Module');
    });

    it('현재 로케일이 없으면 ko 폴백', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '게시판 모듈', en: 'Board Module' },
          },
        },
        $locale: 'ja', // 일본어 로케일 (해당 값 없음)
      };
      const result = engine.evaluateExpression('$localized(_global.selectedModule.name)', context);
      expect(result).toBe('게시판 모듈');
    });

    it('ko도 없으면 en 폴백', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { en: 'Board Module', fr: 'Module de tableau' },
          },
        },
        $locale: 'ja',
      };
      const result = engine.evaluateExpression('$localized(_global.selectedModule.name)', context);
      expect(result).toBe('Board Module');
    });

    it('null 값은 빈 문자열 반환', () => {
      const context = {
        _global: { moduleName: null },
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$localized(_global.moduleName)', context);
      expect(result).toBe('');
    });

    it('undefined 값은 빈 문자열 반환', () => {
      const context = {
        _global: {},
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$localized(_global.moduleName)', context);
      expect(result).toBe('');
    });

    it('$locale 없으면 ko 기본값 사용', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '게시판 모듈', en: 'Board Module' },
          },
        },
        // $locale 없음
      };
      const result = engine.evaluateExpression('$localized(_global.selectedModule.name)', context);
      expect(result).toBe('게시판 모듈');
    });

    it('resolveBindings에서 $localized 사용', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '샘플 모듈', en: 'Sample Module' },
          },
        },
        $locale: 'ko',
      };
      const result = engine.resolveBindings(
        '{{$localized(_global.selectedModule.name)}}을 설치하시겠습니까?',
        context
      );
      expect(result).toBe('샘플 모듈을 설치하시겠습니까?');
    });

    it('복잡한 표현식에서 $localized 사용', () => {
      const context = {
        _global: {
          selectedModule: {
            name: { ko: '결제 모듈', en: 'Payment Module' },
            status: 'active',
          },
        },
        $locale: 'ko',
      };
      const result = engine.evaluateExpression(
        '_global.selectedModule.status === "active" ? $localized(_global.selectedModule.name) + " (활성)" : $localized(_global.selectedModule.name)',
        context
      );
      expect(result).toBe('결제 모듈 (활성)');
    });

    it('iteration 변수에서 $localized 사용 (menu.name)', () => {
      // iteration 컨텍스트 시뮬레이션
      const context = {
        menu: {
          name: { ko: '대시보드', en: 'Dashboard' },
          path: '/admin/dashboard',
        },
        menu_index: 0,
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$localized(menu.name)', context);
      expect(result).toBe('대시보드');
    });

    it('iteration 변수에서 $localized와 문자열 연결', () => {
      const context = {
        perm: {
          name: { ko: '사용자 조회', en: 'View Users' },
          identifier: 'users.view',
        },
        perm_index: 0,
        $locale: 'ko',
      };
      const result = engine.evaluateExpression(
        '$localized(perm.name) + " (" + perm.identifier + ")"',
        context
      );
      expect(result).toBe('사용자 조회 (users.view)');
    });

    it('iteration 변수에서 영어 로케일로 $localized 사용', () => {
      const context = {
        role: {
          name: { ko: '관리자', en: 'Administrator' },
          identifier: 'admin',
        },
        role_index: 0,
        $locale: 'en',
      };
      const result = engine.evaluateExpression(
        '$localized(role.name) + " (" + role.identifier + ")"',
        context
      );
      expect(result).toBe('Administrator (admin)');
    });

    it('resolveBindings에서 iteration 변수 사용', () => {
      const context = {
        menu: {
          name: { ko: '사용자 관리', en: 'User Management' },
        },
        $locale: 'ko',
      };
      const result = engine.resolveBindings('메뉴: {{$localized(menu.name)}}', context);
      expect(result).toBe('메뉴: 사용자 관리');
    });

    // ─── fallbackKey (engine-v1.x — 다국어 라벨 lang pack 보강) ──────────
    it('fallbackKey 미지정 시 기존 동작 유지 (활성 locale → ko → en → 첫 키)', () => {
      const context = {
        country: { name: { ko: '대한민국', en: 'South Korea' }, code: 'KR' },
        $locale: 'ja',
      };
      // ja 키 없으면 ko 폴백
      const result = engine.evaluateExpression('$localized(country.name)', context);
      expect(result).toBe('대한민국');
    });

    it('fallbackKey 지정 + 활성 locale 키 존재 시 value 우선', () => {
      const context = {
        country: { name: { ko: '대한민국', en: 'South Korea', ja: '韓国' }, code: 'KR' },
        $locale: 'ja',
      };
      // ja 키 있으면 fallbackKey 무시
      const result = engine.evaluateExpression(
        "$localized(country.name, 'sirsoft-ecommerce::settings.countries.KR.name')",
        context
      );
      expect(result).toBe('韓国');
    });

    it('fallbackKey 지정 + 활성 locale 키 부재 + lang key 미해석 시 ko fallback', () => {
      const context = {
        country: { name: { ko: '대한민국', en: 'South Korea' }, code: 'KR' },
        $locale: 'ja',
      };
      // ja 키 없음, fallbackKey 도 미등록 → ko 로 fallback
      const result = engine.evaluateExpression(
        "$localized(country.name, 'sirsoft-ecommerce::settings.countries.KR.name')",
        context
      );
      // $t() 가 키를 그대로 반환 (등록 안됨) → value.ko
      expect(result).toBe('대한민국');
    });

    it('value=null + fallbackKey 미해석 → 빈 문자열', () => {
      const context = { $locale: 'ja' };
      const result = engine.evaluateExpression("$localized(null, 'no.such.key')", context);
      expect(result).toBe('');
    });
  });

  describe('$t() 헬퍼 함수', () => {
    it('$t() 함수가 표현식에서 사용 가능해야 함', () => {
      const context = {
        $templateId: 'test-template',
        $locale: 'ko',
      };
      // $t() 함수가 정의되어 있는지 확인 (번역 엔진이 로드되지 않아도 키 반환)
      const result = engine.evaluateExpression('$t("admin.settings.test")', context);
      expect(typeof result).toBe('string');
    });

    it('삼항 연산자와 함께 $t() 사용', () => {
      const context = {
        isActive: true,
        $templateId: 'test-template',
        $locale: 'ko',
      };
      const result = engine.evaluateExpression(
        'isActive ? $t("common.active") : $t("common.inactive")',
        context
      );
      // 번역 엔진이 로드되지 않은 경우 키 자체 반환
      expect(typeof result).toBe('string');
    });

    it('$t()가 빈 키에 대해 빈 문자열 반환', () => {
      const context = {
        $templateId: 'test-template',
        $locale: 'ko',
      };
      const result = engine.evaluateExpression('$t("")', context);
      expect(result).toBe('');
    });

    it('$t()가 null 키에 대해 빈 문자열 반환', () => {
      const context = {
        $templateId: 'test-template',
        $locale: 'ko',
        nullValue: null,
      };
      const result = engine.evaluateExpression('$t(nullValue)', context);
      expect(result).toBe('');
    });

    it('동적 조건과 $t() 조합', () => {
      const context = {
        hasReadWriteSplit: false,
        $templateId: 'test-template',
        $locale: 'ko',
      };
      const result = engine.evaluateExpression(
        'hasReadWriteSplit ? $t("admin.settings.read_write_split") : $t("admin.settings.write_only")',
        context
      );
      expect(typeof result).toBe('string');
      // hasReadWriteSplit가 false이므로 "admin.settings.write_only" 키가 반환됨
      expect(result).toContain('write_only');
    });
  });

  /**
   * 회귀 테스트 (Regression Tests)
   *
   * 트러블슈팅 가이드에 기록된 버그 사례들의 재발 방지를 위한 테스트
   * @see docs/frontend/troubleshooting-cache.md
   * @see docs/frontend/troubleshooting-state-setstate.md
   */
  describe('Regression Tests', () => {
    describe('[TS-CACHE-1~4] skipCache 옵션 검증', () => {
      /**
       * [TS-CACHE-1] DataGrid Row Action에서 잘못된 ID 사용
       *
       * 증상: 사용자 101번을 먼저 클릭한 후, 99번을 클릭해도 101로 이동
       * 원인: resolveBindings 호출 시 skipCache 미사용으로 첫 번째 값 캐싱
       */
      it('[TS-CACHE-1] 다른 컨텍스트에서 같은 경로 접근 시 skipCache: true면 새 값 반환', () => {
        // 첫 번째 행: id = 101
        const context1 = { row: { id: 101, name: '홍길동' } };
        const result1 = engine.resolveBindings('/admin/users/{{row.id}}', context1);
        expect(result1).toBe('/admin/users/101');

        // 두 번째 행: id = 99 (캐시 사용 시 101이 반환되는 버그)
        const context2 = { row: { id: 99, name: '김철수' } };

        // skipCache: false (기본값) - 캐시된 값 반환될 수 있음 (버그 상황)
        // 주석: 캐시 동작 확인용 - 실제로는 skipCache: true 사용 권장
        const _resultWithCache = engine.resolveBindings('/admin/users/{{row.id}}', context2);
        void _resultWithCache; // 의도적 미사용 (버그 상황 시연용)

        // skipCache: true - 항상 새 값 반환 (수정된 동작)
        const resultWithSkipCache = engine.resolveBindings('/admin/users/{{row.id}}', context2, {
          skipCache: true,
        });
        expect(resultWithSkipCache).toBe('/admin/users/99');
      });

      /**
       * [TS-CACHE-2] 페이지네이션 쿼리 파라미터 캐싱
       *
       * 증상: 3페이지에서 4페이지 클릭 시 ?page=3으로 API 호출됨
       * 원인: {{query.page}} 표현식이 캐싱되어 이전 값 반환
       */
      it('[TS-CACHE-2] 쿼리 파라미터 변경 시 skipCache: true면 새 값 반환', () => {
        // 3페이지
        const context1 = { query: { page: 3, limit: 10 } };
        const result1 = engine.resolveBindings('/api/users?page={{query.page}}', context1);
        expect(result1).toBe('/api/users?page=3');

        // 4페이지로 변경
        const context2 = { query: { page: 4, limit: 10 } };

        // skipCache: true로 호출해야 새 값 반환
        const result2 = engine.resolveBindings('/api/users?page={{query.page}}', context2, {
          skipCache: true,
        });
        expect(result2).toBe('/api/users?page=4');
      });

      /**
       * [TS-CACHE-3] 모달 데이터소스 캐시
       *
       * 증상: 두 번째 행부터 "상세보기" 클릭 시 이전 행 데이터 표시
       * 원인: resolveBindings()에서 _global 경로 캐싱
       */
      it('[TS-CACHE-3] _global 상태 변경 후 skipCache: true면 새 값 반환', () => {
        // 첫 번째 모듈 선택
        const context1 = {
          _global: { selectedModule: { identifier: 'module-1', name: '모듈1' } },
        };
        const result1 = engine.resolveBindings(
          '/api/modules/{{_global.selectedModule.identifier}}',
          context1
        );
        expect(result1).toBe('/api/modules/module-1');

        // 두 번째 모듈 선택
        const context2 = {
          _global: { selectedModule: { identifier: 'module-2', name: '모듈2' } },
        };

        // _global 경로는 원래 캐시하지 않지만, 명시적으로 skipCache 확인
        const result2 = engine.resolveBindings(
          '/api/modules/{{_global.selectedModule.identifier}}',
          context2,
          { skipCache: true }
        );
        expect(result2).toBe('/api/modules/module-2');
      });

      /**
       * [TS-CACHE-4] cellChildren 반복 렌더링
       *
       * 증상: DataGrid의 모든 행에 첫 번째 행의 데이터가 표시됨
       * 원인: resolve()가 row.name 경로를 캐싱
       */
      it('[TS-CACHE-4] 반복 렌더링 시 skipCache: true면 각 행의 올바른 값 반환', () => {
        const rows = [
          { id: 1, name: '관리자', email: 'admin@test.com' },
          { id: 2, name: '사용자1', email: 'user1@test.com' },
          { id: 3, name: '사용자2', email: 'user2@test.com' },
        ];

        const results: string[] = [];

        // 각 행에 대해 resolve 호출 (cellChildren 시뮬레이션)
        rows.forEach((row) => {
          const context = { row };
          const name = engine.resolve('row.name', context, { skipCache: true });
          results.push(name as string);
        });

        // 각 행의 이름이 올바르게 반환되어야 함
        expect(results).toEqual(['관리자', '사용자1', '사용자2']);
      });
    });

    describe('[TS-CACHE-6] 배열 타입 체크 (Array.isArray vs ??)', () => {
      /**
       * [TS-CACHE-6] ?? 연산자 대신 Array.isArray 사용 필요
       *
       * 증상: 배열 메서드 호출 시 "map is not a function" 에러
       * 원인: ?? 연산자는 null/undefined만 체크, 빈 객체 {}도 통과
       */
      it('[TS-CACHE-6] ?? 연산자는 빈 객체를 배열로 처리하지 못함', () => {
        const context = {
          items: {}, // 빈 객체 (배열이 아님)
        };

        // ?? 연산자는 null/undefined만 체크하므로 {}가 통과
        const result = engine.evaluateExpression('items ?? []', context);
        expect(result).toEqual({}); // 빈 객체 반환 (배열 아님)
        expect(Array.isArray(result)).toBe(false);
      });

      it('[TS-CACHE-6] Array.isArray로 배열 여부 정확히 판단', () => {
        const context = {
          items: {}, // 빈 객체
          emptyArray: [],
          validArray: [1, 2, 3],
          nullValue: null,
        };

        // 빈 객체 체크
        const result1 = engine.evaluateExpression(
          'Array.isArray(items) ? items : []',
          context
        );
        expect(result1).toEqual([]);
        expect(Array.isArray(result1)).toBe(true);

        // null 체크
        const result2 = engine.evaluateExpression(
          'Array.isArray(nullValue) ? nullValue : []',
          context
        );
        expect(result2).toEqual([]);

        // 정상 배열 체크
        const result3 = engine.evaluateExpression(
          'Array.isArray(validArray) ? validArray : []',
          context
        );
        expect(result3).toEqual([1, 2, 3]);
      });

      it('[TS-CACHE-6] 배열 메서드 사용 전 Array.isArray 체크 패턴', () => {
        const context = {
          _local: {
            form: {
              items: [
                { code: 'A', name: 'Item A' },
                { code: 'B', name: 'Item B' },
              ],
            },
          },
        };

        // 올바른 패턴: Array.isArray 체크 후 map 사용
        const result = engine.evaluateExpression(
          '(Array.isArray(_local.form?.items) ? _local.form.items : []).map(i => i.code)',
          context
        );
        expect(result).toEqual(['A', 'B']);
      });

      it('[TS-CACHE-6] 초기화 중 상태에서 Array.isArray 체크', () => {
        // 상태 초기화 중: items가 undefined
        const contextBeforeInit = {
          _local: {
            form: {},
          },
        };

        const result1 = engine.evaluateExpression(
          '(Array.isArray(_local.form?.items) ? _local.form.items : []).length',
          contextBeforeInit
        );
        expect(result1).toBe(0);

        // 상태 초기화 완료 후
        const contextAfterInit = {
          _local: {
            form: {
              items: [{ id: 1 }, { id: 2 }],
            },
          },
        };

        const result2 = engine.evaluateExpression(
          '(Array.isArray(_local.form?.items) ? _local.form.items : []).length',
          contextAfterInit
        );
        expect(result2).toBe(2);
      });
    });

    describe('[TS-CACHE-8] 캐시 무효화', () => {
      /**
       * [TS-CACHE-8] SPA 네비게이션 후 _localInit 캐시 미무효화
       *
       * 증상: 뒤로가기 후 새 데이터가 아닌 캐시된 이전 값 표시
       * 원인: _local 경로 캐시가 무효화되지 않음
       */
      it('[TS-CACHE-8] invalidateCacheByKeys로 캐시 무효화 (정적 경로)', () => {
        // _local은 영구 캐시하지 않으므로, 정적 경로로 테스트
        const context1 = {
          user: {
            form: {
              id: 1,
              name: 'Guest',
              permission_ids: [],
            },
          },
        };

        // user.form.permission_ids 캐싱 (정적 경로)
        const result1 = engine.resolve('user.form.permission_ids', context1);
        expect(result1).toEqual([]);

        // 캐시 통계 확인
        const stats1 = engine.getCacheStats();
        expect(stats1.size).toBeGreaterThan(0);

        // user 관련 캐시 무효화
        engine.invalidateCacheByKeys(['user']);

        // 두 번째 페이지 데이터 (Admin 역할)
        const context2 = {
          user: {
            form: {
              id: 2,
              name: 'Admin',
              permission_ids: [1, 2, 3, 4, 5],
            },
          },
        };

        // 캐시 무효화 후 새 값 반환
        const result2 = engine.resolve('user.form.permission_ids', context2);
        expect(result2).toEqual([1, 2, 3, 4, 5]);
      });

      it('[TS-CACHE-8] 특정 키 패턴으로 캐시 무효화', () => {
        // _local은 영구 캐시하지 않으므로, 정적 경로로 테스트
        const context = {
          data: { count: 1, name: 'test' },
          user: { id: 1, name: 'User' },
        };

        // 여러 경로 캐싱 (정적 경로만 캐싱됨)
        engine.resolve('data.count', context);
        engine.resolve('data.name', context);
        engine.resolve('user.id', context);
        engine.resolve('user.name', context);

        const stats1 = engine.getCacheStats();
        // data와 user 모두 캐시됨
        expect(stats1.size).toBeGreaterThanOrEqual(2);

        // data 관련만 무효화
        engine.invalidateCacheByKeys(['data']);

        // user 관련 캐시는 유지되어야 함
        const stats2 = engine.getCacheStats();
        // user 캐시가 남아있거나 전체 무효화 여부 확인
        expect(stats2.size).toBeLessThan(stats1.size);
      });
    });

    describe('resolveObject iteration 건너뛰기 (engine-v1.19.1)', () => {
      it('iteration이 있는 객체는 선평가하지 않고 원본 그대로 반환한다', () => {
        const obj = {
          type: 'basic',
          name: 'Div',
          iteration: {
            source: "{{Object.entries(order.data?.mc_subtotal_amount ?? {}).filter(([code]) => code !== 'KRW')}}",
            item_var: 'currency',
          },
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: '{{currency[1]?.formatted}}',
            },
          ],
        };

        const context = {
          order: { data: { mc_subtotal_amount: { KRW: { formatted: '10,000원' }, USD: { formatted: '$10.00' } } } },
        };

        const result = engine.resolveObject(obj, context);

        // iteration 객체는 선평가 건너뛰기 → 원본 문자열 유지
        expect(result.iteration.source).toBe(
          "{{Object.entries(order.data?.mc_subtotal_amount ?? {}).filter(([code]) => code !== 'KRW')}}"
        );
        expect(result.children[0].text).toBe('{{currency[1]?.formatted}}');
      });

      it('iteration이 없는 일반 객체는 정상적으로 해석한다', () => {
        const obj = {
          type: 'basic',
          name: 'Span',
          text: '{{order.data?.total_amount_formatted}}',
        };

        const context = {
          order: { data: { total_amount_formatted: '10,000원' } },
        };

        const result = engine.resolveObject(obj, context);

        expect(result.text).toBe('10,000원');
      });

      it('배열 내 iteration 객체도 건너뛴다', () => {
        const obj = {
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: '{{order.data?.subtotal_amount_formatted}}',
            },
            {
              type: 'basic',
              name: 'Div',
              iteration: {
                source: 'currencies',
                item_var: 'currency',
              },
              text: '{{currency.formatted}}',
            },
          ],
        };

        const context = {
          order: { data: { subtotal_amount_formatted: '10,000원' } },
          currencies: [{ formatted: '$10.00' }],
        };

        const result = engine.resolveObject(obj, context);

        // 일반 객체는 해석됨
        expect(result.children[0].text).toBe('10,000원');
        // iteration 객체는 원본 유지
        expect(result.children[1].text).toBe('{{currency.formatted}}');
        expect(result.children[1].iteration.source).toBe('currencies');
      });
    });

    describe('getNestedValue 배열 인덱스 파싱', () => {
      /**
       * 배열 인덱스 포함 경로 파싱 테스트
       *
       * 증상: data.data[0].options 형식의 경로 파싱 실패
       * 원인: [0] 문법을 인식하지 못함
       */
      it('data.data[0].options[0].id 형식의 복합 경로 파싱', () => {
        const context = {
          data: {
            data: [
              {
                options: [{ id: 100, name: 'Option A' }, { id: 200, name: 'Option B' }],
              },
              {
                options: [{ id: 300, name: 'Option C' }],
              },
            ],
          },
        };

        expect(engine.resolve('data.data[0].options[0].id', context)).toBe(100);
        expect(engine.resolve('data.data[0].options[1].name', context)).toBe('Option B');
        expect(engine.resolve('data.data[1].options[0].id', context)).toBe(300);
      });

      it('연속 배열 인덱스 접근', () => {
        const context = {
          matrix: [
            [
              [1, 2],
              [3, 4],
            ],
            [
              [5, 6],
              [7, 8],
            ],
          ],
        };

        expect(engine.resolve('matrix[0][0][0]', context)).toBe(1);
        expect(engine.resolve('matrix[1][1][1]', context)).toBe(8);
      });

      it('객체 배열 내 중첩 객체 접근', () => {
        const context = {
          users: [
            {
              profile: {
                addresses: [
                  { city: 'Seoul', zip: '12345' },
                  { city: 'Busan', zip: '67890' },
                ],
              },
            },
          ],
        };

        expect(engine.resolve('users[0].profile.addresses[0].city', context)).toBe('Seoul');
        expect(engine.resolve('users[0].profile.addresses[1].zip', context)).toBe('67890');
      });
    });
  });

  describe('{{raw:...}} 바인딩 — 번역 면제 (engine-v1.27.0)', () => {
    describe('resolveBindings', () => {
      it('단순 경로에 raw 마커 래핑', () => {
        const context = { post: { title: '$t:admin.dashboard' } };
        const result = engine.resolveBindings('{{raw:post.title}}', context);
        expect(result).toBe('\uFDD0$t:admin.dashboard\uFDD1');
      });

      it('raw: 없는 바인딩은 마커 없음', () => {
        const context = { post: { title: '$t:admin.dashboard' } };
        const result = engine.resolveBindings('{{post.title}}', context);
        expect(result).toBe('$t:admin.dashboard');
      });

      it('복잡한 표현식 지원 (삼항)', () => {
        const context = { cond: true, a: '$t:key1', b: '$t:key2' };
        const result = engine.resolveBindings('{{raw:cond ? a : b}}', context);
        expect(result).toBe('\uFDD0$t:key1\uFDD1');
      });

      it('null/undefined 결과는 빈 문자열 래핑', () => {
        const context = { post: {} };
        const result = engine.resolveBindings('{{raw:post.missing}}', context);
        expect(result).toBe('\uFDD0\uFDD1');
      });

      it('혼합 보간 — raw 바인딩과 일반 바인딩 공존', () => {
        const context = { post: { title: 'Hello $t:key' }, author: 'Admin' };
        const result = engine.resolveBindings('제목: {{raw:post.title}} - 작성자: {{author}}', context);
        expect(result).toBe('제목: \uFDD0Hello $t:key\uFDD1 - 작성자: Admin');
      });
    });

    describe('resolveObject', () => {
      it('단일 바인딩 — 문자열 값 래핑', () => {
        const obj = { data: '{{raw:post.title}}' };
        const context = { post: { title: '$t:admin.dashboard' } };
        const result = engine.resolveObject(obj, context);
        expect(result.data).toBe('\uFDD0$t:admin.dashboard\uFDD1');
      });

      it('단일 바인딩 — 객체/배열 재귀 래핑', () => {
        const obj = { data: '{{raw:items}}' };
        const context = { items: [{ label: '$t:foo' }, { label: '$t:bar' }] };
        const result = engine.resolveObject(obj, context);
        expect(result.data[0].label).toBe('\uFDD0$t:foo\uFDD1');
        expect(result.data[1].label).toBe('\uFDD0$t:bar\uFDD1');
      });

      it('단일 바인딩 — 복잡한 표현식 + raw:', () => {
        const obj = { text: '{{raw:cond ? a : b}}' };
        const context = { cond: false, a: 'val1', b: '$t:val2' };
        const result = engine.resolveObject(obj, context);
        expect(result.text).toBe('\uFDD0$t:val2\uFDD1');
      });

      it('raw: 없는 단일 바인딩은 마커 없음', () => {
        const obj = { data: '{{post.title}}' };
        const context = { post: { title: '$t:admin.dashboard' } };
        const result = engine.resolveObject(obj, context);
        expect(result.data).toBe('$t:admin.dashboard');
      });

      it('null 값은 래핑하지 않음', () => {
        const obj = { data: '{{raw:post.missing}}' };
        const context = { post: {} };
        const result = engine.resolveObject(obj, context);
        expect(result.data).toBeUndefined();
      });
    });
  });
});
