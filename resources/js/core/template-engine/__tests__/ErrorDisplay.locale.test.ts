/**
 * ErrorDisplay — 엔진 공통 오류 화면 문구의 화면 언어 (engine-v1.78.5, 7.1.0 영역 J)
 *
 * 권한 없는 화면을 열면 엔진 공통 오류 화면이 뜨는데, 제목 「페이지 로딩 실패」·버튼 「새로고침」 이 영어 화면에서도
 * 한국어로 박혀 있었고, 본문은 개발자용 원문 「Failed to fetch layout: 403 Forbidden」 이었다.
 * 번역 엔진이 키를 풀면 그 값을, 못 풀면(번역 파일 실패가 원인일 수도 있다) 내장 문구를 `<html lang>` 으로 고른다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { ErrorDisplay } from '../ErrorDisplay';
import { TemplateEngineError } from '../TemplateEngineError';

describe('ErrorDisplay 화면 언어', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
  });
  afterEach(() => {
    delete (window as any).G7Core;
    document.documentElement.lang = '';
  });

  /**
   * @scenario case=error_en_builtin
   * @effects error_screen_text_follows_locale
   */
  it('번역 엔진이 없으면 <html lang> 에 맞는 내장 문구를 쓴다(en)', () => {
    document.documentElement.lang = 'en';
    const err = new TemplateEngineError('LAYOUT_LOAD_ERROR', ErrorDisplay.text('core.errors.layout_forbidden'), { icon: 'fa-lock' });
    ErrorDisplay.renderFromError('app', err, ErrorDisplay.text('core.errors.page_load_failed'));
    const text = document.getElementById('app')!.textContent!;
    expect(text).toContain('Page failed to load');
    expect(text).toContain('You do not have permission to view this page.');
    expect(text).toContain('Reload');
    expect(text).not.toMatch(/[가-힣]/);
  });

  /**
   * @scenario case=error_ko_builtin
   * @effects error_screen_text_follows_locale
   */
  it('한국어 화면은 종전 문구 그대로다', () => {
    document.documentElement.lang = 'ko';
    expect(ErrorDisplay.text('core.errors.page_load_failed')).toBe('페이지 로딩 실패');
    expect(ErrorDisplay.text('core.errors.reload')).toBe('새로고침');
  });

  /**
   * @scenario case=error_ja_translator
   * @effects error_screen_text_follows_locale
   */
  it('번역 엔진이 키를 풀면 그 값을 먼저 쓴다', () => {
    document.documentElement.lang = 'ja';
    (window as any).G7Core = { t: (key: string) => (key === 'core.errors.reload' ? '再読み込み(翻訳)' : key) };
    expect(ErrorDisplay.text('core.errors.reload')).toBe('再読み込み(翻訳)');
    // 못 푼 키는 내장 ja 문구
    expect(ErrorDisplay.text('core.errors.page_load_failed')).toBe('ページの読み込みに失敗しました');
  });

  /**
   * @scenario case=error_icons_hidden
   * @effects error_screen_text_follows_locale
   */
  it('큰 아이콘과 버튼 아이콘은 꾸밈으로 숨긴다', () => {
    const err = new TemplateEngineError('X', 'msg', { icon: 'fa-lock' });
    ErrorDisplay.renderFromError('app', err, 'title');
    const icons = document.querySelectorAll('#app i');
    expect(icons.length).toBeGreaterThan(0);
    icons.forEach((i) => expect(i.getAttribute('aria-hidden')).toBe('true'));
  });

  /**
   * 레이아웃 편집기 번들 실패 화면의 제목·안내 세 문구가 ko·en·ja 내장 문구를 갖는다(7.1.0 잔여 관측 R-15).
   *
   * @effects layout_editor_failure_text_has_builtin_ko_en_ja
   */
  it('레이아웃 편집기 로드 실패 문구 세 개가 ko·en·ja 내장 문구를 갖는다', () => {
    const keys = [
      'core.errors.layout_editor_load_failed',
      'core.errors.layout_editor_bundle_failed',
      'core.errors.layout_editor_init_failed',
    ];
    for (const key of keys) {
      for (const lang of ['ko', 'en', 'ja']) {
        const text = ErrorDisplay.text(key, lang);
        expect(text, `${key} (${lang})`).not.toBe(key);
      }
      expect(ErrorDisplay.text(key, 'en')).not.toMatch(/[가-힣]/);
    }
  });
});
