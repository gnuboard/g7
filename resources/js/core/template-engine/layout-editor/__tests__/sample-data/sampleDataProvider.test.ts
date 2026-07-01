/**
 * sampleDataProvider.test.ts — (engine-v1.50.0 갱신)
 *
 * 우선순위 매트릭스 전수 검증:
 * 0. bySource(출처 네임스페이스, 방안 B) > 1. byDataSourceId > 2. byEndpointPattern >
 * 3. fallback > 4. inferred (도메인-중립 빈 응답 `{}`)
 *
 * 코어 키워드 프리셋(coreSamplePresets)은 단계적 폐기로 제거됨 — 미매칭
 * 데이터소스는 빈 응답으로 디그레이드. 두 단계 동시 매칭 시 상위 우선 (회귀 가드).
 */

import { describe, it, expect, vi } from 'vitest';
import type { DataSource } from '../../../DataSourceManager';
import {
  resolveSampleData,
  createSampleDataProvider,
  type SampleMatchSource,
} from '../../sample-data/sampleDataProvider';

const makeSource = (overrides: Partial<DataSource>): DataSource => ({
  id: 'src-test',
  type: 'api',
  endpoint: '/api/sample',
  method: 'GET',
  ...overrides,
} as DataSource);

describe('sampleDataProvider — 우선순위 매트릭스 ', () => {
  describe('1. byDataSourceId — 최우선', () => {
    it('명시 매핑된 id 응답을 그대로 반환', () => {
      const customResponse = { data: [{ id: 7, title: '템플릿 명시 응답' }] };
      const { value, match } = resolveSampleData(
        makeSource({ id: 'recent-posts' }),
        {
          byDataSourceId: { 'recent-posts': customResponse },
        },
      );

      expect(value).toBe(customResponse);
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byId');
    });

    it('byEndpointPattern 동시 매칭 시에도 byDataSourceId 우선', () => {
      const idResponse = { data: ['id-source'] };
      const endpointResponse = { data: ['endpoint-source'] };
      const { value, match } = resolveSampleData(
        makeSource({ id: 'posts-feed', endpoint: '/api/posts' }),
        {
          byDataSourceId: { 'posts-feed': idResponse },
          byEndpointPattern: { '/api/posts': endpointResponse },
        },
      );

      expect(value).toBe(idResponse);
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byId');
    });
  });

  describe('2. byEndpointPattern — 두 번째', () => {
    it('정확한 endpoint 매칭', () => {
      const customResponse = { data: ['endpoint-match'] };
      const { value, match } = resolveSampleData(
        makeSource({ id: 'unknown-id', endpoint: '/api/boards' }),
        {
          byEndpointPattern: { '/api/boards': customResponse },
        },
      );

      expect(value).toBe(customResponse);
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byEndpoint');
    });

    it('와일드카드 패턴 매칭 (`*`)', () => {
      const customResponse = { data: ['wildcard'] };
      const { value, match } = resolveSampleData(
        makeSource({ id: 'unknown-id', endpoint: '/api/posts/42/comments' }),
        {
          byEndpointPattern: { '/api/posts/*': customResponse },
        },
      );

      expect(value).toBe(customResponse);
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byEndpoint');
    });
  });

  describe('3. 코어 프리셋 폐기 — 키워드 매칭 데이터소스는 더 이상 도메인 프리셋으로 해소되지 않음 (engine-v1.50.0)', () => {
    //  단계적 폐기: coreSamplePresets 제거. spec 미작성·미매칭 데이터소스는
    // 도메인-중립 빈 응답(`{}`)으로 디그레이드. (회귀 가드 — 키워드 프리셋 부활 방지)
    const keywordSources = ['recent-posts', 'main-menu', 'post-comments', 'user-list'];
    for (const id of keywordSources) {
      it(`"${id}" 는 spec 없으면 빈 응답으로 디그레이드 (도메인 프리셋 미부활)`, () => {
        const { value, match } = resolveSampleData(makeSource({ id }));
        expect(value).toEqual({});
        expect(match.resolvedBy).toBe<SampleMatchSource>('inferred');
        // 제거된 필드 — 타입에서 사라졌으므로 런타임에도 부재여야 한다
        expect((match as { presetKey?: unknown }).presetKey).toBeUndefined();
      });
    }

    it('endpoint 키워드(board 등) 도 spec 없으면 빈 응답', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'list-items', endpoint: '/api/boards' }),
      );
      expect(value).toEqual({});
      expect(match.resolvedBy).toBe<SampleMatchSource>('inferred');
    });
  });

  describe('4. fallback — 데이터소스 자체의 fallback.data', () => {
    it('fallback.data 가 있으면 그 값을 data 배열로 감싸 반환', () => {
      const fallbackArr = [{ id: 1, label: 'fallback item' }];
      const { value, match } = resolveSampleData(
        makeSource({
          id: 'no-keyword-match',
          endpoint: '/api/nothing-recognizable',
          // @ts-expect-error — 런타임에 fallback 필드 존재
          fallback: { data: fallbackArr },
        }),
      );

      expect(match.resolvedBy).toBe<SampleMatchSource>('fallback');
      expect((value as any).data).toEqual(fallbackArr);
    });
  });

  describe('5. inferred — 마지막 도메인-중립 빈 응답', () => {
    it('아무 것도 매칭되지 않으면 빈 객체(`{}`) 반환', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'completely-unknown', endpoint: '/api/xyz' }),
      );

      expect(value).toEqual({});
      expect(match.resolvedBy).toBe<SampleMatchSource>('inferred');
    });
  });

  describe('0. 방안 B — 출처 네임스페이스 분리 해소 (bySource)', () => {
    // 같은 id `settings` 를 세 출처가 서로 다른 shape 로 정의한 스펙
    const spec = {
      bySource: {
        'plugin:sirsoft-gdpr': { settings: { data: { gdpr_enabled: true } } },
        'module:sirsoft-ecommerce': { settings: { data: { currency: 'KRW' } } },
        template: { settings: { data: { site_name: '코어' } } },
      },
      byDataSourceId: { settings: { data: { flat: 'fallback' } } },
    };

    it('__source(plugin) 데이터소스는 자기 출처 샘플로 해소', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'settings', __source: { kind: 'plugin', identifier: 'sirsoft-gdpr' } } as Partial<DataSource>),
        spec,
      );
      expect(value).toEqual({ data: { gdpr_enabled: true } });
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byId');
      expect(match.sourceKey).toBe('plugin:sirsoft-gdpr');
    });

    it('__source(module) 데이터소스는 자기 출처 샘플로 해소', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'settings', __source: { kind: 'module', identifier: 'sirsoft-ecommerce' } } as Partial<DataSource>),
        spec,
      );
      expect(value).toEqual({ data: { currency: 'KRW' } });
      expect(match.sourceKey).toBe('module:sirsoft-ecommerce');
    });

    it('__source(template) 은 template 키로 해소', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'settings', __source: { kind: 'template', identifier: 'sirsoft-admin_basic' } } as Partial<DataSource>),
        spec,
      );
      expect(value).toEqual({ data: { site_name: '코어' } });
      expect(match.sourceKey).toBe('template');
    });

    it('__source 부재 시 template 키로 귀속 → bySource.template 해소', () => {
      const { value, match } = resolveSampleData(makeSource({ id: 'settings' }), spec);
      expect(match.sourceKey).toBe('template');
      expect(value).toEqual({ data: { site_name: '코어' } });
    });

    it('bySource 에 해당 출처/id 가 없으면 평탄 byDataSourceId 로 폴백 (sourceKey 미설정)', () => {
      const { value, match } = resolveSampleData(
        makeSource({ id: 'settings', __source: { kind: 'plugin', identifier: 'unknown-plugin' } } as Partial<DataSource>),
        spec,
      );
      expect(value).toEqual({ data: { flat: 'fallback' } });
      expect(match.resolvedBy).toBe<SampleMatchSource>('spec_byId');
      expect(match.sourceKey).toBeUndefined();
    });
  });

  describe('createSampleDataProvider — DataSourceManager 인터페이스 호환', () => {
    it('has() 는 항상 true 반환 (빈 응답 폴백 보장)', () => {
      const provider = createSampleDataProvider({});
      expect(provider.has('any-id')).toBe(true);
      expect(provider.has('')).toBe(true);
    });

    it('resolve() 호출 시 onMatch 콜백이 매칭 메타와 함께 호출됨', () => {
      const onMatch = vi.fn();
      const provider = createSampleDataProvider({ onMatch });
      provider.resolve(makeSource({ id: 'recent-posts' }));

      expect(onMatch).toHaveBeenCalledTimes(1);
      expect(onMatch.mock.calls[0][0]).toMatchObject({
        dataSourceId: 'recent-posts',
        resolvedBy: 'inferred',
      });
    });

    it('onMatch 가 throw 해도 resolve 는 정상 값을 반환 (degrade safety)', () => {
      const provider = createSampleDataProvider({
        onMatch: () => {
          throw new Error('devtools failure');
        },
      });

      const result = provider.resolve(makeSource({ id: 'recent-posts' }));
      expect(result).toEqual({});
    });
  });

  describe('페이지 상태 sampleData 오버라이드 ', () => {
    it('overrideSpec 매칭 시 base spec 보다 우선해 통째 교체한다', () => {
      const provider = createSampleDataProvider({
        spec: { byDataSourceId: { me: { data: { name: '기본 사용자' } } } },
        overrideSpec: { byDataSourceId: { me: { data: null } } },
      });
      expect(provider.resolve(makeSource({ id: 'me' }))).toEqual({ data: null });
    });

    it('overrideSpec 미매칭 데이터소스는 base spec 으로 폴백한다', () => {
      const provider = createSampleDataProvider({
        spec: { byDataSourceId: { me: { data: { name: '기본' } }, posts: { data: [1, 2] } } },
        overrideSpec: { byDataSourceId: { me: { data: null } } },
      });
      expect(provider.resolve(makeSource({ id: 'me' }))).toEqual({ data: null });
      expect(provider.resolve(makeSource({ id: 'posts' }))).toEqual({ data: [1, 2] });
    });

    it('overrideSpec 은 명시 단계(0/1/2)만 — 미매칭이면 base 의 inferred 빈 응답으로 폴백', () => {
      const provider = createSampleDataProvider({
        spec: undefined,
        overrideSpec: { byDataSourceId: { me: { data: null } } },
      });
      expect(provider.resolve(makeSource({ id: 'other' }))).toEqual({});
    });

    it('overrideSpec 미전달 시 기존 동작과 동일 (회귀 가드)', () => {
      const provider = createSampleDataProvider({
        spec: { byDataSourceId: { me: { data: { name: '기본' } } } },
      });
      expect(provider.resolve(makeSource({ id: 'me' }))).toEqual({ data: { name: '기본' } });
    });
  });
});
