import React from 'react';
import { basic, translate } from '../../support/editor';

export interface HeroPreviewLanguageProps {
  /** 사이트 활성 언어 */
  locales?: string[];
  /** 미리 볼 콘텐츠 언어 */
  locale?: string;
  /** 언어 선택 콜백 */
  onLocaleChange?: (locale: string) => void;
}

/** 관리자 UI 언어를 바꾸지 않고 콘텐츠 언어만 고릅니다. */
export const HeroPreviewLanguage: React.FC<HeroPreviewLanguageProps> = ({ locales = [], locale, onLocaleChange }) => {
  const Label = basic('Label', 'label');
  // Select 집합 부품의 options 계약과 HTML select의 자식 계약이 다르므로 기본 요소를 사용한다.
  const NativeSelect = 'select';
  return <Label className="hs-editor-language">{translate('admin.form.preview.language')}
    <NativeSelect aria-label={translate('admin.form.preview.language')} value={locale ?? locales[0] ?? ''} onChange={(event) => onLocaleChange?.(event.target.value)}>
      {(locales ?? []).map((code) => React.createElement('option', { key: code, value: code }, code.toUpperCase()))}
    </NativeSelect>
  </Label>;
};
