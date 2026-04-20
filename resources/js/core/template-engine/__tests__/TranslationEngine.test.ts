import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  TranslationEngine,
  TranslationError,
  TranslationContext,
  TranslationDictionary,
} from '../TranslationEngine';

describe('TranslationEngine', () => {
  let engine: TranslationEngine;

  beforeEach(() => {
    engine = new TranslationEngine({
      defaultLocale: 'ko',
      fallbackLocale: 'en',
      cacheTTL: 1000, // 1초 (테스트용)
    });

    // fetch mock 초기화
    global.fetch = vi.fn();
  });

  describe('생성자 및 초기화', () => {
    it('기본 옵션으로 인스턴스 생성', () => {
      const newEngine = new TranslationEngine();
      expect(newEngine).toBeInstanceOf(TranslationEngine);
    });

    it('커스텀 옵션으로 인스턴스 생성', () => {
      const customEngine = new TranslationEngine({
        defaultLocale: 'en',
        fallbackLocale: 'ko',
        cacheTTL: 5000,
      });
      expect(customEngine).toBeInstanceOf(TranslationEngine);
    });
  });

  describe('loadTranslations', () => {
    it('다국어 파일 로드 성공', async () => {
      const mockTranslations: TranslationDictionary = {
        dashboard: {
          title: '대시보드',
          subtitle: '시스템 현황',
        },
        common: {
          save: '저장',
          cancel: '취소',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockTranslations,
      });

      const result = await engine.loadTranslations('template-1', 'ko');

      expect(result).toEqual(mockTranslations);
      expect(global.fetch).toHaveBeenCalledWith('/api/templates/template-1/lang/ko.json');
    });

    it('커스텀 API 베이스 URL 사용', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko', 'https://api.example.com');

      expect(global.fetch).toHaveBeenCalledWith(
        'https://api.example.com/templates/template-1/lang/ko.json'
      );
    });

    it('API 호출 실패 시 에러 발생', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        statusText: 'Not Found',
      });

      await expect(engine.loadTranslations('template-1', 'ko')).rejects.toThrow(
        TranslationError
      );
    });

    it('네트워크 에러 시 에러 발생', async () => {
      (global.fetch as any).mockRejectedValueOnce(new Error('Network error'));

      await expect(engine.loadTranslations('template-1', 'ko')).rejects.toThrow(
        TranslationError
      );
    });
  });

  describe('translate', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        dashboard: {
          title: '대시보드',
          subtitle: '시스템 현황',
        },
        common: {
          save: '저장',
          cancel: '취소',
        },
        messages: {
          greeting: '안녕하세요, {name}님',
          items_count: '총 {count}개의 항목',
        },
      };

      const enTranslations: TranslationDictionary = {
        dashboard: {
          title: 'Dashboard',
          subtitle: 'System Status',
        },
        common: {
          save: 'Save',
          cancel: 'Cancel',
        },
        messages: {
          greeting: 'Hello, {name}',
          items_count: 'Total {count} items',
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/lang/ko')) {
          return Promise.resolve({
            ok: true,
            json: async () => koTranslations,
          });
        } else if (url.includes('/lang/en')) {
          return Promise.resolve({
            ok: true,
            json: async () => enTranslations,
          });
        }
        return Promise.resolve({ ok: false, statusText: 'Not Found' });
      });

      await engine.loadTranslations('template-1', 'ko');
      await engine.loadTranslations('template-1', 'en');
    });

    it('단순 키 번역', () => {
      const result = engine.translate('dashboard.title', context);
      expect(result).toBe('대시보드');
    });

    it('중첩된 키 번역', () => {
      const result = engine.translate('common.save', context);
      expect(result).toBe('저장');
    });

    it('파라미터 치환', () => {
      const result = engine.translate('messages.greeting', context, '|name=홍길동');
      expect(result).toBe('안녕하세요, 홍길동님');
    });

    it('여러 파라미터 치환', () => {
      const result = engine.translate('messages.items_count', context, '|count=42');
      expect(result).toBe('총 42개의 항목');
    });

    it('데이터 컨텍스트에서 파라미터 바인딩', () => {
      const dataContext = { user: { name: '홍길동' } };
      const result = engine.translate(
        'messages.greeting',
        context,
        '|name={{user.name}}',
        dataContext
      );
      expect(result).toBe('안녕하세요, 홍길동님');
    });

    it('존재하지 않는 키는 키 자체 반환', () => {
      const result = engine.translate('non.existent.key', context);
      expect(result).toBe('non.existent.key');
    });

    it('폴백 로케일 사용 (현재 로케일에 없는 경우)', () => {
      const koOnlyContext: TranslationContext = {
        templateId: 'template-1',
        locale: 'ja', // 존재하지 않는 로케일
      };

      const result = engine.translate('dashboard.title', koOnlyContext);
      // fallback인 en의 번역이 반환되어야 함
      expect(result).toBe('Dashboard');
    });
  });

  describe('resolveTranslations', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        common: {
          save: '저장',
          cancel: '취소',
        },
        messages: {
          greeting: '안녕하세요, {name}님',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('문자열 내 단일 번역 표현식 처리', () => {
      const result = engine.resolveTranslations('버튼: $t:common.save', context);
      expect(result).toBe('버튼: 저장');
    });

    it('문자열 내 여러 번역 표현식 처리', () => {
      const result = engine.resolveTranslations(
        '$t:common.save / $t:common.cancel',
        context
      );
      expect(result).toBe('저장 / 취소');
    });

    it('파라미터가 포함된 번역 표현식 처리', () => {
      const result = engine.resolveTranslations(
        '메시지: $t:messages.greeting|name=홍길동',
        context
      );
      expect(result).toBe('메시지: 안녕하세요, 홍길동님');
    });

    it('데이터 컨텍스트를 사용한 파라미터 바인딩', () => {
      const dataContext = { userName: '이순신', user: { name: '홍길동' } };

      // 단순 변수 바인딩
      const result1 = engine.resolveTranslations(
        '$t:messages.greeting|name=이순신',
        context,
        dataContext
      );
      expect(result1).toBe('안녕하세요, 이순신님');
    });

    it('번역 표현식이 없는 문자열은 그대로 반환', () => {
      const result = engine.resolveTranslations('일반 텍스트', context);
      expect(result).toBe('일반 텍스트');
    });
  });

  describe('캐싱', () => {
    it('동일한 번역 파일은 캐시에서 로드', async () => {
      const mockTranslations: TranslationDictionary = {
        test: { key: 'value' },
      };

      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockTranslations,
      });

      // 첫 번째 호출
      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(1);

      // 두 번째 호출 (캐시에서 로드되어야 함)
      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(1); // 여전히 1번만 호출
    });

    it('캐시 초기화', async () => {
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');
      engine.clearCache();

      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('캐시 만료 후 재로드', async () => {
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(1);

      // 캐시 TTL 대기 (1초)
      await new Promise((resolve) => setTimeout(resolve, 1100));

      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('캐시 통계 조회', async () => {
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');
      await engine.loadTranslations('template-2', 'en');

      const stats = engine.getCacheStats();
      expect(stats.size).toBe(2);
    });

    it('만료된 캐시 정리', async () => {
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');

      // 캐시 만료 대기
      await new Promise((resolve) => setTimeout(resolve, 1100));

      engine.pruneCache();

      const stats = engine.getCacheStats();
      expect(stats.size).toBe(0);
    });
  });

  describe('폴백 체인', () => {
    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        korean_only: '한국어만',
        both: '둘 다 (한국어)',
      };

      const enTranslations: TranslationDictionary = {
        english_only: 'English only',
        both: 'Both (English)',
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/lang/ko')) {
          return Promise.resolve({
            ok: true,
            json: async () => koTranslations,
          });
        } else if (url.includes('/lang/en')) {
          return Promise.resolve({
            ok: true,
            json: async () => enTranslations,
          });
        }
        return Promise.resolve({ ok: false });
      });

      await engine.loadTranslations('template-1', 'ko');
      await engine.loadTranslations('template-1', 'en');
    });

    it('현재 로케일에서 찾기', () => {
      const context: TranslationContext = {
        templateId: 'template-1',
        locale: 'ko',
      };

      const result = engine.translate('korean_only', context);
      expect(result).toBe('한국어만');
    });

    it('현재 로케일에 없으면 폴백 로케일에서 찾기', () => {
      const context: TranslationContext = {
        templateId: 'template-1',
        locale: 'ko',
      };

      const result = engine.translate('english_only', context);
      expect(result).toBe('English only');
    });

    it('둘 다 있으면 현재 로케일 우선', () => {
      const context: TranslationContext = {
        templateId: 'template-1',
        locale: 'ko',
      };

      const result = engine.translate('both', context);
      expect(result).toBe('둘 다 (한국어)');
    });

    it('어디에도 없으면 키 자체 반환', () => {
      const context: TranslationContext = {
        templateId: 'template-1',
        locale: 'ko',
      };

      const result = engine.translate('non.existent', context);
      expect(result).toBe('non.existent');
    });
  });

  describe('에러 처리', () => {
    it('TranslationError는 key와 locale 정보 포함', () => {
      const error = new TranslationError(
        'Translation failed',
        'dashboard.title',
        'ko'
      );

      expect(error.name).toBe('TranslationError');
      expect(error.message).toBe('Translation failed');
      expect(error.key).toBe('dashboard.title');
      expect(error.locale).toBe('ko');
    });
  });

  describe('엣지 케이스', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        test: {
          value: '테스트',
          empty: '',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('빈 문자열 번역 키 처리', () => {
      const result = engine.translate('', context);
      expect(result).toBe('');
    });

    it('빈 문자열 번역 값 처리', () => {
      const result = engine.translate('test.empty', context);
      expect(result).toBe('');
    });

    it('null 파라미터 처리', () => {
      const result = engine.translate('test.value', context, null as any);
      expect(result).toBe('테스트');
    });

    it('undefined 파라미터 처리', () => {
      const result = engine.translate('test.value', context, undefined);
      expect(result).toBe('테스트');
    });

    it('빈 데이터 컨텍스트 처리', () => {
      const result = engine.resolveTranslations('$t:test.value', context, {});
      expect(result).toBe('테스트');
    });

    it('null 데이터 컨텍스트 처리', () => {
      const result = engine.resolveTranslations('$t:test.value', context, null);
      expect(result).toBe('테스트');
    });

    it('undefined 데이터 컨텍스트 처리', () => {
      const result = engine.resolveTranslations('$t:test.value', context, undefined);
      expect(result).toBe('테스트');
    });

    it('잘못된 형식의 파라미터 문자열', () => {
      const result = engine.translate('test.value', context, '|invalid');
      expect(result).toBe('테스트');
    });

    it('존재하지 않는 데이터 경로 바인딩', () => {
      const dataContext = { user: { name: '홍길동' } };
      const result = engine.translate(
        'test.value',
        context,
        '|param={{non.existent.path}}',
        dataContext
      );
      expect(result).toBe('테스트'); // 파라미터는 빈 문자열로 치환됨
    });

    it('특수 문자가 포함된 번역 키', () => {
      const result = engine.translate('test.value-with-dash', context);
      expect(result).toBe('test.value-with-dash'); // 존재하지 않으므로 키 자체 반환
    });

    it('매우 긴 번역 키', () => {
      const longKey = 'a'.repeat(1000);
      const result = engine.translate(longKey, context);
      expect(result).toBe(longKey);
    });

    it('중첩 깊이가 깊은 키', () => {
      const result = engine.translate('a.b.c.d.e.f.g.h', context);
      expect(result).toBe('a.b.c.d.e.f.g.h');
    });
  });

  describe('파이프 구분 다중 파라미터', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        pagination: {
          info: '총 {{total}}명 중 {{from}}-{{to}}명 표시',
          info_single: '총 {total}명 중 {from}-{to}명 표시',
        },
        complex: {
          message: '{{user}}님이 {{action}}을 수행했습니다 ({{count}}건)',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('파이프로 구분된 여러 파라미터 처리', () => {
      const result = engine.translate(
        'pagination.info',
        context,
        '|total=100|from=1|to=10'
      );
      expect(result).toBe('총 100명 중 1-10명 표시');
    });

    it('단일 중괄호 형식 파라미터 치환', () => {
      const result = engine.translate(
        'pagination.info_single',
        context,
        '|total=50|from=11|to=20'
      );
      expect(result).toBe('총 50명 중 11-20명 표시');
    });

    it('데이터 컨텍스트와 파이프 구분 파라미터 조합', () => {
      const dataContext = {
        users: { data: { total: 200, from: 21, to: 30 } },
      };
      const result = engine.translate(
        'pagination.info',
        context,
        '|total={{users.data.total}}|from={{users.data.from}}|to={{users.data.to}}',
        dataContext
      );
      expect(result).toBe('총 200명 중 21-30명 표시');
    });

    it('3개 이상의 파라미터 처리', () => {
      const result = engine.translate(
        'complex.message',
        context,
        '|user=홍길동|action=로그인|count=5'
      );
      expect(result).toBe('홍길동님이 로그인을 수행했습니다 (5건)');
    });

    it('resolveTranslations에서 파이프 구분 다중 파라미터', () => {
      const result = engine.resolveTranslations(
        '페이지 정보: $t:pagination.info|total=500|from=1|to=25',
        context
      );
      expect(result).toBe('페이지 정보: 총 500명 중 1-25명 표시');
    });

    it('앰퍼샌드(&)로 구분된 파라미터도 처리', () => {
      const result = engine.translate(
        'pagination.info',
        context,
        '|total=100&from=1&to=10'
      );
      expect(result).toBe('총 100명 중 1-10명 표시');
    });

    it('파이프와 앰퍼샌드 혼합 사용', () => {
      const result = engine.translate(
        'pagination.info',
        context,
        '|total=100|from=1&to=10'
      );
      expect(result).toBe('총 100명 중 1-10명 표시');
    });
  });

  describe('resolveTranslations 재귀 처리', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        nested: {
          level1: '{value1}',
          level2: '{value2}',
        },
        multi: {
          first: '첫번째',
          second: '두번째',
          third: '세번째',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('중첩된 번역 표현식 처리', () => {
      const result = engine.resolveTranslations(
        '외부: $t:multi.first, 내부: $t:multi.second',
        context
      );
      expect(result).toBe('외부: 첫번째, 내부: 두번째');
    });

    it('연속된 번역 표현식 처리', () => {
      const result = engine.resolveTranslations(
        '$t:multi.first$t:multi.second$t:multi.third',
        context
      );
      expect(result).toBe('첫번째두번째세번째');
    });

    it('복잡한 문자열 내 다중 번역', () => {
      const text = `
        항목 1: $t:multi.first
        항목 2: $t:multi.second
        항목 3: $t:multi.third
      `;
      const result = engine.resolveTranslations(text, context);
      expect(result).toContain('항목 1: 첫번째');
      expect(result).toContain('항목 2: 두번째');
      expect(result).toContain('항목 3: 세번째');
    });

    it('파라미터가 있는 다중 번역 표현식', () => {
      const dataContext = { val1: 'A', val2: 'B' };
      const result = engine.resolveTranslations(
        '$t:nested.level1|value1={{val1}} / $t:nested.level2|value2={{val2}}',
        context,
        dataContext
      );
      expect(result).toBe('A / B');
    });

    it('번역 표현식과 일반 텍스트 혼합', () => {
      const result = engine.resolveTranslations(
        '시작 $t:multi.first 중간 $t:multi.second 끝 $t:multi.third 완료',
        context
      );
      expect(result).toBe('시작 첫번째 중간 두번째 끝 세번째 완료');
    });
  });

  describe('동적 파라미터 조합 (표현식 평가 결과)', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        admin: {
          users: {
            form: {
              page_description_edit: '{{name}}({{id}})의 정보를 수정합니다.',
              page_description_create: '새로운 사용자를 등록합니다.',
            },
          },
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('문자열 연결로 조합된 다국어 키와 파라미터 처리', () => {
      // 표현식 평가 결과를 시뮬레이션
      // {{route?.id ? '$t:...edit|name=' + name + '|id=' + id : '$t:...create'}}
      // 평가 후: $t:admin.users.form.page_description_edit|name=민서|id=212
      const evaluatedResult = '$t:admin.users.form.page_description_edit|name=민서|id=212';

      const result = engine.resolveTranslations(evaluatedResult, context);
      expect(result).toBe('민서(212)의 정보를 수정합니다.');
    });

    it('한글 이름과 숫자 ID 파라미터 조합', () => {
      const result = engine.translate(
        'admin.users.form.page_description_edit',
        context,
        '|name=홍길동|id=123'
      );
      expect(result).toBe('홍길동(123)의 정보를 수정합니다.');
    });

    it('영어 이름과 숫자 ID 파라미터 조합', () => {
      const result = engine.translate(
        'admin.users.form.page_description_edit',
        context,
        '|name=John|id=456'
      );
      expect(result).toBe('John(456)의 정보를 수정합니다.');
    });

    it('빈 파라미터 값 처리', () => {
      const result = engine.translate(
        'admin.users.form.page_description_edit',
        context,
        '|name=|id='
      );
      // 빈 값은 PARAM_PATTERN에서 매칭되지 않으므로 원본 유지
      // 이는 예상된 동작임 (빈 값을 전달하려면 명시적으로 공백 등을 사용해야 함)
      expect(result).toBe('{{name}}({{id}})의 정보를 수정합니다.');
    });

    it('특수문자가 포함된 이름 처리', () => {
      const result = engine.translate(
        'admin.users.form.page_description_edit',
        context,
        '|name=홍길동(관리자)|id=789'
      );
      expect(result).toBe('홍길동(관리자)(789)의 정보를 수정합니다.');
    });
  });

  describe('복잡한 표현식 내 JavaScript 연산자 처리', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        templates: {
          deactivate_confirm: '{{name}} 템플릿을 비활성화하시겠습니까?',
        },
        messages: {
          greeting: '안녕하세요, {{name}}님',
          with_fallback: '{{value}} 값이 설정되었습니다.',
        },
        order: {
          product: { more: '외 {{count}}건' },
        },
        pagination: {
          info: '{{from}}~{{to}} / {{total}}',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('|| 연산자가 포함된 표현식 처리 (첫 번째 값이 truthy)', () => {
      const dataContext = {
        _global: {
          selectedTemplate: {
            name: 'Admin Basic',
          },
        },
        $locale: 'ko',
      };

      const result = engine.translate(
        'templates.deactivate_confirm',
        context,
        '|name={{_global.selectedTemplate.name[$locale] || _global.selectedTemplate.name.ko || _global.selectedTemplate.name}}',
        dataContext
      );
      // name[$locale]이 undefined이고, name.ko도 undefined이므로 name이 반환됨
      expect(result).toBe('Admin Basic 템플릿을 비활성화하시겠습니까?');
    });

    it('|| 연산자가 포함된 표현식 처리 (다국어 객체)', () => {
      const dataContext = {
        _global: {
          selectedTemplate: {
            name: {
              ko: '관리자 기본',
              en: 'Admin Basic',
            },
          },
        },
        $locale: 'ko',
      };

      const result = engine.translate(
        'templates.deactivate_confirm',
        context,
        '|name={{_global.selectedTemplate.name[$locale] || _global.selectedTemplate.name.ko || _global.selectedTemplate.name}}',
        dataContext
      );
      expect(result).toBe('관리자 기본 템플릿을 비활성화하시겠습니까?');
    });

    it('|| 연산자 폴백 체인 (첫 번째가 falsy일 때)', () => {
      const dataContext = {
        _global: {
          selectedTemplate: {
            name: {
              en: 'Admin Basic',
            },
          },
        },
        $locale: 'ko',
      };

      const result = engine.translate(
        'templates.deactivate_confirm',
        context,
        '|name={{_global.selectedTemplate.name[$locale] || _global.selectedTemplate.name.ko || _global.selectedTemplate.name.en}}',
        dataContext
      );
      // name['ko']가 undefined이고, name.ko도 undefined이므로 name.en이 반환됨
      expect(result).toBe('Admin Basic 템플릿을 비활성화하시겠습니까?');
    });

    it('&& 연산자가 포함된 표현식 처리', () => {
      const dataContext = {
        user: {
          isAdmin: true,
          name: '홍길동',
        },
      };

      const result = engine.translate(
        'messages.greeting',
        context,
        '|name={{user.isAdmin && user.name}}',
        dataContext
      );
      expect(result).toBe('안녕하세요, 홍길동님');
    });

    it('삼항 연산자가 포함된 표현식 처리', () => {
      const dataContext = {
        value: 10,
      };

      const result = engine.translate(
        'messages.with_fallback',
        context,
        '|value={{value > 5 ? "높음" : "낮음"}}',
        dataContext
      );
      expect(result).toBe('높음 값이 설정되었습니다.');
    });

    it('resolveTranslations에서 || 연산자 처리', () => {
      const dataContext = {
        _global: {
          selectedTemplate: {
            name: 'Test Template',
          },
        },
        $locale: 'ko',
      };

      const result = engine.resolveTranslations(
        '$t:templates.deactivate_confirm|name={{_global.selectedTemplate.name[$locale] || _global.selectedTemplate.name.ko || _global.selectedTemplate.name}}',
        context,
        dataContext
      );
      expect(result).toBe('Test Template 템플릿을 비활성화하시겠습니까?');
    });

    it('공백이 포함된 값과 || 연산자 조합', () => {
      const dataContext = {
        template: {
          displayName: 'Admin Basic Template',
        },
      };

      const result = engine.translate(
        'templates.deactivate_confirm',
        context,
        '|name={{template.displayName || "기본 템플릿"}}',
        dataContext
      );
      expect(result).toBe('Admin Basic Template 템플릿을 비활성화하시겠습니까?');
    });

    it('한글 공백이 포함된 이름 처리 (바인딩 해결 후)', () => {
      // DynamicRenderer에서 바인딩이 먼저 해결된 후 번역 처리되는 시나리오
      // 예: "$t:key|name={{selectedModule.name}}" → "$t:key|name=샘플 모듈"
      const result = engine.resolveTranslations(
        '$t:templates.deactivate_confirm|name=샘플 모듈',
        context
      );
      expect(result).toBe('샘플 모듈 템플릿을 비활성화하시겠습니까?');
    });

    it('한글 공백과 영문 혼합 이름 처리', () => {
      const result = engine.resolveTranslations(
        '$t:templates.deactivate_confirm|name=게시판 Board',
        context
      );
      expect(result).toBe('게시판 Board 템플릿을 비활성화하시겠습니까?');
    });

    it('여러 한글 단어로 된 이름 처리', () => {
      const result = engine.resolveTranslations(
        '$t:templates.deactivate_confirm|name=관리자 기본 템플릿',
        context
      );
      expect(result).toBe('관리자 기본 템플릿 템플릿을 비활성화하시겠습니까?');
    });

    it('산술 연산자(-)가 포함된 표현식 처리', () => {
      // row.options_count - 1 같은 산술 표현식이 올바르게 평가되어야 함
      const dataContext = {
        row: { options_count: 8 },
      };
      const result = engine.translate(
        'order.product.more',
        context,
        '|count={{row.options_count - 1}}',
        dataContext
      );
      expect(result).toBe('외 7건');
    });

    it('산술 연산자(+, *, /)가 포함된 표현식 처리', () => {
      const dataContext = {
        page: 3,
        perPage: 10,
        total: 48,
      };
      const result = engine.translate(
        'pagination.info',
        context,
        '|from={{(page - 1) * perPage + 1}}|to={{page * perPage}}|total={{total}}',
        dataContext
      );
      expect(result).toBe('21~30 / 48');
    });
  });

  describe('파라미터 값 내 중첩 $t: 해석 (engine-v1.25.0)', () => {
    const context: TranslationContext = {
      templateId: 'template-1',
      locale: 'ko',
    };

    beforeEach(async () => {
      const koTranslations: TranslationDictionary = {
        promotion_coupon: {
          modal: {
            status_change: {
              message: '선택된 {{count}}개 쿠폰의 발급상태가 {{status}}(으)로 변경됩니다.',
            },
          },
        },
        enums: {
          coupon_issue_status: {
            issuing: '발급중',
            stopped: '발급중단',
          },
          order_status: {
            pending: '대기',
            confirmed: '확인',
            shipped: '배송중',
          },
        },
        common: {
          confirm_change: '{{item}}을(를) {{from}}에서 {{to}}(으)로 변경합니다.',
          status_label: '상태: {{name}}',
        },
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => koTranslations,
      });

      await engine.loadTranslations('template-1', 'ko');
    });

    it('파라미터 값에 $t: 토큰이 있으면 사전 해석 후 치환', () => {
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count=2|status=$t:enums.coupon_issue_status.issuing',
        context
      );
      expect(result).toBe('선택된 2개 쿠폰의 발급상태가 발급중(으)로 변경됩니다.');
    });

    it('다른 상태값에 대한 중첩 $t: 해석', () => {
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count=5|status=$t:enums.coupon_issue_status.stopped',
        context
      );
      expect(result).toBe('선택된 5개 쿠폰의 발급상태가 발급중단(으)로 변경됩니다.');
    });

    it('다중 파라미터에 각각 $t: 중첩 사용', () => {
      const result = engine.resolveTranslations(
        '$t:common.confirm_change|item=쿠폰|from=$t:enums.coupon_issue_status.issuing|to=$t:enums.coupon_issue_status.stopped',
        context
      );
      expect(result).toBe('쿠폰을(를) 발급중에서 발급중단(으)로 변경합니다.');
    });

    it('$t: 중첩과 {{}} 표현식 파라미터 혼합 사용', () => {
      const dataContext = {
        _global: { bulkSelectedItems: [1, 2, 3] },
      };
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count={{_global.bulkSelectedItems.length}}|status=$t:enums.coupon_issue_status.issuing',
        context,
        dataContext
      );
      expect(result).toBe('선택된 3개 쿠폰의 발급상태가 발급중(으)로 변경됩니다.');
    });

    it('=$t: 패턴이 없으면 사전 해석 단계를 건너뜀 (기존 동작 유지)', () => {
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count=10|status=발급중',
        context
      );
      expect(result).toBe('선택된 10개 쿠폰의 발급상태가 발급중(으)로 변경됩니다.');
    });

    it('존재하지 않는 중첩 $t: 키는 키 자체를 반환', () => {
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count=1|status=$t:enums.nonexistent_key',
        context
      );
      expect(result).toBe('선택된 1개 쿠폰의 발급상태가 enums.nonexistent_key(으)로 변경됩니다.');
    });

    it('중첩 해석 결과에 다시 =$t:가 포함되면 재귀 해석 (깊이 제한 내)', () => {
      // status_label 번역: "상태: {{name}}" — 해석 결과에 =$t: 없으므로 1회로 충분
      const result = engine.resolveTranslations(
        '$t:common.status_label|name=$t:enums.order_status.shipped',
        context
      );
      expect(result).toBe('상태: 배송중');
    });

    it('기존 파이프 구분 파라미터 패턴은 회귀 없음', () => {
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count=3|status=직접입력값',
        context
      );
      expect(result).toBe('선택된 3개 쿠폰의 발급상태가 직접입력값(으)로 변경됩니다.');
    });

    it('기존 || 연산자 파라미터는 회귀 없음', () => {
      const dataContext = {
        _global: { bulkSelectedItems: [1, 2] },
        status: null,
      };
      const result = engine.resolveTranslations(
        '$t:promotion_coupon.modal.status_change.message|count={{(_global.bulkSelectedItems ?? []).length}}|status={{status ?? "미설정"}}',
        context,
        dataContext
      );
      expect(result).toBe('선택된 2개 쿠폰의 발급상태가 미설정(으)로 변경됩니다.');
    });
  });

  describe('캐시 버전 관리', () => {
    it('초기 캐시 버전은 0이다', () => {
      expect(engine.getCacheVersion()).toBe(0);
    });

    it('setCacheVersion으로 캐시 버전을 설정할 수 있다', () => {
      engine.setCacheVersion(1735000000);
      expect(engine.getCacheVersion()).toBe(1735000000);
    });

    it('캐시 버전이 설정되면 API URL에 쿼리 파라미터가 추가된다', async () => {
      engine.setCacheVersion(1735000000);

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/templates/template-1/lang/ko.json?v=1735000000'
      );
    });

    it('캐시 버전이 0이면 쿼리 파라미터가 추가되지 않는다', async () => {
      engine.setCacheVersion(0);

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko');

      expect(global.fetch).toHaveBeenCalledWith('/api/templates/template-1/lang/ko.json');
    });

    it('캐시 버전과 bustCache가 함께 사용되면 두 파라미터가 모두 추가된다', async () => {
      engine.setCacheVersion(1735000000);

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => ({}),
      });

      await engine.loadTranslations('template-1', 'ko', '/api', true);

      // bustCache가 true이면 타임스탬프 쿼리 파라미터도 추가됨
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringMatching(/\/api\/templates\/template-1\/lang\/ko\.json\?v=1735000000&_=\d+/)
      );
    });

    it('캐시 버전 변경 시 기존 캐시가 클리어된다', async () => {
      const mockTranslations = { test: { key: '값' } };

      // 첫 번째 로드
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockTranslations,
      });
      await engine.loadTranslations('template-1', 'ko');

      // 캐시에서 로드 (fetch 호출 없음)
      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(1);

      // 캐시 버전 변경
      engine.setCacheVersion(1735000000);

      // 새 버전으로 다시 로드 (fetch 호출됨)
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockTranslations,
      });
      await engine.loadTranslations('template-1', 'ko');

      expect(global.fetch).toHaveBeenCalledTimes(2);
      expect(global.fetch).toHaveBeenLastCalledWith(
        '/api/templates/template-1/lang/ko.json?v=1735000000'
      );
    });

    it('동일한 캐시 버전으로 설정하면 캐시가 유지된다', async () => {
      engine.setCacheVersion(1735000000);

      const mockTranslations = { test: { key: '값' } };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockTranslations,
      });
      await engine.loadTranslations('template-1', 'ko');

      // 동일한 버전으로 다시 설정
      engine.setCacheVersion(1735000000);

      // 캐시에서 로드 (fetch 호출 없음)
      await engine.loadTranslations('template-1', 'ko');
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    // @since engine-v1.38.1
    // 회귀 방지: reloadExtensions 병렬 실행 중 setCacheVersion 이 활성 translations 맵을
    // 비워서 같은 parallel 블록 내의 toast($t:...) 가 raw key 를 반환하던 경합 조건
    it('setCacheVersion 호출 후에도 이전 번역이 즉시 조회 가능해야 한다 (loadTranslations 완료 전)', async () => {
      const mockTranslations = {
        admin: { modules: { activate_success: '모듈 활성화 성공' } },
      };

      // 1. 초기 번역 로드
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockTranslations,
      });
      await engine.loadTranslations('template-1', 'ko');

      const context: TranslationContext = { templateId: 'template-1', locale: 'ko' };

      // 2. 로드 직후 번역 조회 성공
      expect(engine.translate('admin.modules.activate_success', context)).toBe('모듈 활성화 성공');

      // 3. 캐시 버전 변경 (reloadExtensions 내부에서 일어나는 동작)
      engine.setCacheVersion(1735000000);

      // 4. loadTranslations 재호출 전에도 기존 번역은 유지되어야 함
      //    → 병렬 toast 가 raw key 가 아닌 실제 번역을 받음
      expect(engine.translate('admin.modules.activate_success', context)).toBe('모듈 활성화 성공');
    });
  });
});
