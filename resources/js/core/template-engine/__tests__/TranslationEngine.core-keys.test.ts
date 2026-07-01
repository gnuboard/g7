import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  TranslationEngine,
  TranslationContext,
  TranslationDictionary,
} from '../TranslationEngine';

/**
 * 코어 프론트엔드 다국어 인프라 회귀 가드.
 *
 * `TemplateService::getLanguageDataWithModules` 가 코어 `lang/{locale}.json` 을
 * 베이스 레이어로 병합한 응답을 보낸다. 본 테스트는 응답에 포함된 `core.*` 키가
 * `translate()` 로 해석되는지 검증한다.
 *
 * 시나리오:
 *   - 응답에 `core.errors.*` 키 포함 → 해석 성공
 *   - 응답에 코어 키 부재 (구 버전 백엔드) → 키 자체 반환 (fallback 동작 보존)
 *   - 템플릿이 코어 키 덮어쓰기 → 템플릿 값 우선 (병합 순서 검증은 백엔드 책임이므로
 *     본 테스트는 dictionary 가 이미 병합된 상태로 들어옴을 가정)
 */
describe('TranslationEngine — 코어 프론트엔드 키 해석', () => {
  let engine: TranslationEngine;

  beforeEach(() => {
    engine = new TranslationEngine({
      defaultLocale: 'ko',
      fallbackLocale: 'en',
      cacheTTL: 1000,
    });
    global.fetch = vi.fn();
  });

  const koWithCore: TranslationDictionary = {
    core: {
      errors: {
        template_not_found: '활성화된 템플릿이 없습니다',
        layout_load_failed: '레이아웃을 불러오는데 실패했습니다',
        layout_load_failed_with_id: '레이아웃({layoutId})을 불러오는데 실패했습니다',
      },
    },
    auth: { login: '로그인' },
  };

  const enWithCore: TranslationDictionary = {
    core: {
      errors: {
        template_not_found: 'No active template found',
        layout_load_failed: 'Failed to load layout',
      },
    },
    auth: { login: 'Login' },
  };

  const setupFetchMock = (ko: TranslationDictionary, en: TranslationDictionary) => {
    (global.fetch as any).mockImplementation((url: string) => {
      if (url.includes('/lang/ko')) {
        return Promise.resolve({ ok: true, json: async () => ko });
      }
      if (url.includes('/lang/en')) {
        return Promise.resolve({ ok: true, json: async () => en });
      }
      return Promise.resolve({ ok: false, statusText: 'Not Found' });
    });
  };

  it('응답에 포함된 코어 키를 해석한다 (ko)', async () => {
    setupFetchMock(koWithCore, enWithCore);
    await engine.loadTranslations('template-1', 'ko');
    await engine.loadTranslations('template-1', 'en');

    const ctx: TranslationContext = { templateId: 'template-1', locale: 'ko' };
    expect(engine.translate('core.errors.template_not_found', ctx)).toBe('활성화된 템플릿이 없습니다');
    expect(engine.translate('core.errors.layout_load_failed', ctx)).toBe('레이아웃을 불러오는데 실패했습니다');
  });

  it('응답에 포함된 코어 키를 해석한다 (en)', async () => {
    setupFetchMock(koWithCore, enWithCore);
    await engine.loadTranslations('template-1', 'ko');
    await engine.loadTranslations('template-1', 'en');

    const ctx: TranslationContext = { templateId: 'template-1', locale: 'en' };
    expect(engine.translate('core.errors.template_not_found', ctx)).toBe('No active template found');
  });

  it('코어 키에서 파라미터 치환이 동작한다', async () => {
    setupFetchMock(koWithCore, enWithCore);
    await engine.loadTranslations('template-1', 'ko');
    await engine.loadTranslations('template-1', 'en');

    const ctx: TranslationContext = { templateId: 'template-1', locale: 'ko' };
    const result = engine.translate('core.errors.layout_load_failed_with_id', ctx, '|layoutId=admin_login');
    expect(result).toBe('레이아웃(admin_login)을 불러오는데 실패했습니다');
  });

  it('응답에 코어 키가 없으면 키 자체를 반환한다 (구버전 백엔드 fallback)', async () => {
    const koWithoutCore: TranslationDictionary = { auth: { login: '로그인' } };
    const enWithoutCore: TranslationDictionary = { auth: { login: 'Login' } };

    setupFetchMock(koWithoutCore, enWithoutCore);
    await engine.loadTranslations('template-1', 'ko');
    await engine.loadTranslations('template-1', 'en');

    const ctx: TranslationContext = { templateId: 'template-1', locale: 'ko' };
    // 키 부재 시 키 자체 반환 (TranslationEngine 의 표준 폴백)
    expect(engine.translate('core.errors.template_not_found', ctx)).toBe('core.errors.template_not_found');
  });
});
