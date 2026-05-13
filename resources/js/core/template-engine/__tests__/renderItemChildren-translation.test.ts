/**
 * renderItemChildren 표현식 결과 $t: 번역 후처리 테스트
 *
 * cellChildren text에서 표현식 평가 결과가 $t: 접두사 문자열인 경우
 * translationEngine.resolveTranslations()가 호출되어 번역되는지 검증합니다.
 *
 * @since engine-v1.28.1
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { renderItemChildren } from '../helpers/RenderHelpers';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';
import { RAW_MARKER_START, RAW_MARKER_END } from '../rawMarkers';

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

// 테스트용 간단 컴포넌트
const TestSpan: React.FC<any> = ({ children, ...props }) =>
  React.createElement('span', props, children);

describe('renderItemChildren - 표현식 결과 $t: 번역 후처리', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  const componentMap: Record<string, React.ComponentType<any>> = {
    Span: TestSpan,
  };

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();

    // TranslationEngine 싱글톤에 번역 데이터 직접 주입
    // loadTranslations는 API fetch 기반이므로, 내부 translations Map에 직접 설정
    // 캐시 키 형식: `${templateId}:${locale}`
    translationEngine = TranslationEngine.getInstance();
    const mockDict = {
      'sirsoft-page': {
        admin: {
          page: {
            published_status: {
              published: '발행',
              unpublished: '미발행',
            },
          },
        },
      },
      common: {
        save: '저장',
        saving: '저장 중...',
        loading: '로딩 중...',
        delete: '삭제',
      },
    };
    (translationEngine as any).translations.set(':ko', mockDict);
  });

  it('삼항 연산자 결과가 $t: 문자열이면 번역 처리 (하이픈 포함 모듈키)', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: "{{row.published ? '$t:sirsoft-page.admin.page.published_status.published' : '$t:sirsoft-page.admin.page.published_status.unpublished'}}",
      },
    ];

    const context = {
      row: { published: true },
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    // React element의 children이 번역된 텍스트여야 함
    expect(result).toHaveLength(1);
    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('발행');
  });

  it('삼항 연산자 false 경로 번역 처리', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: "{{row.published ? '$t:sirsoft-page.admin.page.published_status.published' : '$t:sirsoft-page.admin.page.published_status.unpublished'}}",
      },
    ];

    const context = {
      row: { published: false },
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('미발행');
  });

  it('하이픈 없는 키도 정상 번역 (기존 동작 보존)', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: "{{isLoading ? '$t:common.saving' : '$t:common.save'}}",
      },
    ];

    const context = {
      isLoading: false,
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('저장');
  });

  it('정적 $t: 텍스트는 기존과 동일하게 번역 (회귀 없음)', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: '$t:common.delete',
      },
    ];

    const context = {
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('삭제');
  });

  it('raw: 바인딩은 번역 면제 (회귀 없음)', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: '{{raw:row.rawValue}}',
      },
    ];

    const context = {
      row: { rawValue: '$t:common.save' },
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    const element = result[0] as React.ReactElement;
    // raw: 바인딩이므로 번역되지 않고 원본 유지 (raw 마커로 감싸져 반환)
    // DynamicRenderer 상위에서 마커 스트리핑 처리됨
    expect(element.props.children).toBe(`${RAW_MARKER_START}$t:common.save${RAW_MARKER_END}`);
  });

  it('표현식 결과가 $t: 문자열이 아니면 번역 시도 안 함', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: '{{row.published ? "활성" : "비활성"}}',
      },
    ];

    const context = {
      row: { published: true },
      $templateId: '',
      $locale: 'ko',
    };

    const result = renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('활성');
  });
});
