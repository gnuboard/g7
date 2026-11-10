/**
 * @file translate.test.ts
 * @description 부품 문구 번역 — 설정 화면 묶음의 이름공간을 잠근다 (#434).
 *
 * 설정 화면 묶음은 다른 관리자 템플릿 화면에 실려 이 템플릿 문구가 `{템플릿id}.{묶음}` 으로만 온다.
 * 기본 부품 묶음은 이름공간이 없어 종전과 같아야 한다.
 *
 * 시나리오: case=settings_bundle_load
 *
 * 효과: settings_strings_wrapped_for_admin
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { setTranslationNamespace, translate } from '../translate';

const dictionary: Record<string, string> = {
  'sirsoft-admin_basic.attachment.drop': '템플릿 문구',
  'core.template_settings.save': '코어 문구',
  'plain.key': '평평한 문구',
};

describe('translate (#434)', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => dictionary[key] ?? key };
  });

  afterEach(() => {
    setTranslationNamespace(null);
    delete (window as any).G7Core;
  });

  // @scenario case=settings_bundle_load
  // @effects settings_strings_wrapped_for_admin
  it('이름공간이 있으면 감싼 문구를 먼저 찾고, 없으면 그대로의 키로 찾는다', () => {
    setTranslationNamespace('sirsoft-admin_basic');

    expect(translate('attachment.drop')).toBe('템플릿 문구');
    expect(translate('core.template_settings.save')).toBe('코어 문구');
  });

  // @scenario case=settings_bundle_load
  // @effects settings_strings_wrapped_for_admin
  it('이름공간이 없으면(기본 부품 묶음) 감싼 문구를 보지 않는다', () => {
    expect(translate('attachment.drop')).toBe('attachment.drop');
    expect(translate('plain.key')).toBe('평평한 문구');
  });

  // @scenario case=settings_bundle_load
  // @effects settings_strings_wrapped_for_admin
  it('번역 함수가 없으면 undefined — 호출 쪽 대체 문구가 쓰인다', () => {
    delete (window as any).G7Core;

    expect(translate('plain.key')).toBeUndefined();
  });
});
