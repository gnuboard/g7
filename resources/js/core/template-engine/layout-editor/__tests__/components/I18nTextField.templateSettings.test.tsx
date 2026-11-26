// e2e:allow 편집기 속성 창 단위(RTL) — 라이브 확인은 이력 문서 Chrome MCP 매트릭스 T5-b(편집기 `_user_base` 푸터·헤더)
/**
 * I18nTextField.templateSettings.test.tsx — 템플릿 설정에 연결된 다국어 글자 칸
 *
 * 템플릿 설정(`_global.templateSettings`)은 그 템플릿의 「설정」 화면이 유일한 주인이다. 편집기 글자 칸이
 * 일반 「바인딩됨(코드 편집)」 배지를 보이거나(복잡식) [데이터 바꾸기] 피커를 열면(단일 경로식) 운영자가
 * 편집기에서 값을 바꾸려 해 두 주인 상태가 된다(실측: 푸터 저작권 칸). 이미지·숫자 칸의 BoundValueNotice 와
 * 같은 안내만 보이는지 실물 컴포넌트로 잠근다. 모킹은 prop-i18n-text-field.test.tsx 와 같다(네트워크·번역만).
 *
 * 시나리오: case=editor_bound_value
 *
 * 효과: template_settings_bound_text_shows_owner_notice
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

// useLayoutEditor — layoutName/locale/templateIdentifier 공급 모킹(공통 hook 이 읽음).
const editorContext = {
  state: {
    templateIdentifier: 'sirsoft-basic',
    locale: 'ko',
    selectedRoute: { path: '/login', layoutName: 'login' },
  },
};
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => editorContext,
  useOptionalLayoutEditor: () => editorContext,
}));

// 커스텀 키 CRUD — 네트워크 없이 결과 주입.
const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const findCustomKeyRow = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  // 펼침=통합 TranslationField. 그 동기화 구독이 읽는 이벤트명 export.
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));

// TranslationEngine — `$t:custom.login.1` 를 현재 로케일 값으로 해석(미리보기 시작값).
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      translate: (key: string) =>
        key === 'custom.login.1'
          ? '이메일'
          : key === 'auth.login.email'
            ? '이메일(코어)'
            : key === 'board.edit_post'
              ? '게시글 수정'
              : key === 'board.new_post'
                ? '게시글 작성'
                : key,
    }),
  },
}));

// 활성 로케일 — readSupportedLocales 모킹(펼침 폼 행).
vi.mock('../../components/LocaleSwitcher', async (orig) => {
  const actual = (await orig()) as Record<string, unknown>;
  return { ...actual, readSupportedLocales: () => ['ko', 'en', 'ja'] };
});

// 칸자리 칩/+데이터 키화 API (inlineBindingApi). 네트워크 없이 결과 주입.
const apiFindCustomKeyRow = vi.fn();
const putSingleLocaleKeyValue = vi.fn().mockResolvedValue({ kind: 'ok' });
const keyifyWithNewBinding = vi.fn();
const insertBindingIntoParamKey = vi.fn();
vi.mock('../../components/property-controls/inlineBindingApi', () => ({
  findCustomKeyRow: (...a: unknown[]) => apiFindCustomKeyRow(...a),
  putSingleLocaleKeyValue: (...a: unknown[]) => putSingleLocaleKeyValue(...a),
  keyifyWithNewBinding: (...a: unknown[]) => keyifyWithNewBinding(...a),
  insertBindingIntoParamKey: (...a: unknown[]) => insertBindingIntoParamKey(...a),
}));

import { I18nTextField } from '../../components/property-controls/I18nTextField';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

afterEach(() => cleanup());

describe('I18nTextField — 템플릿 설정 연결', () => {
  // 템플릿 설정에 연결된 글자 칸 — 복잡식(푸터 저작권)·단일 경로식 모두 트리·[데이터 바꾸기] 없이 주인 안내만.
  // @scenario case=editor_bound_value
  // @effects template_settings_bound_text_shows_owner_notice
  it('C.템플릿 설정 바인딩 → 주인 안내(코드 편집 배지·트리·데이터 피커 없음)', () => {
    const candidates = [{ path: '_global.templateSettings.footer.copyright', label: '저작권', type: 'string' }] as any;
    for (const value of [
      "{{$localized(_global.templateSettings?.footer?.copyright ?? {}) ?? ''}}",
      "{{_global.templateSettings?.header?.title ?? ''}}",
    ]) {
      cleanup();
      render(<I18nTextField value={value} onChange={vi.fn()} t={t} enableExpressionTree expressionTreeCollapsible candidates={candidates} />);
      expect(screen.getByTestId('g7le-i18n-text-field-template-settings-notice').textContent).toContain('layout_editor.control.bound_value.template_settings_notice');
      expect(screen.getByTestId('g7le-i18n-text-field-binding').textContent).toContain(value);
      expect(screen.queryByTestId('g7le-i18n-text-field-tree')).toBeNull();
      expect(screen.queryByTestId('g7le-i18n-text-field-data')).toBeNull();
      expect(screen.getByTestId('g7le-i18n-text-field-binding').textContent).not.toContain('layout_editor.prop_i18n.bound_code_only');
      // [설정 열기] — 편집 중인 템플릿의 설정 화면을 새 창으로(S30)
      const open = screen.getByTestId('g7le-i18n-text-field-template-settings-open') as HTMLAnchorElement;
      expect(open.getAttribute('href')).toBe('/admin/templates/sirsoft-basic/settings');
      expect(open.getAttribute('target')).toBe('_blank');
      expect(open.getAttribute('rel')).toBe('noopener noreferrer');
      expect(open.textContent).toBe('layout_editor.control.bound_value.open_template_settings');
    }
  });

});
