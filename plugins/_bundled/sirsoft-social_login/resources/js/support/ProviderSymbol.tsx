import React from 'react';

/** 버튼 모양 계열 — 서버가 `brand` 로 내려준다(모의 제공자도 원 제공자 계열). */
export type ProviderBrand = 'kakao' | 'naver' | 'google' | 'facebook' | 'custom';

export interface ProviderSymbolProps {
  /** 버튼 모양 계열 */
  brand: ProviderBrand;

  /** 커스텀 제공자 첫 글자 원형에 쓸 이름 */
  label?: string;
}

/**
 * 제공자 로그인 버튼의 심볼.
 *
 * 각 제공자가 로그인 버튼용으로 배포하는 공식 심볼을 인라인 SVG 로 그린다(외부 이미지 요청 없음). 커스텀 OIDC 제공자는
 * 이름 첫 글자를 원 안에 넣는다. 심볼은 장식이라 스크린리더에는 숨기고, 버튼 이름은 텍스트가 맡는다.
 */
export const ProviderSymbol: React.FC<ProviderSymbolProps> = ({ brand, label = '' }) => {
  switch (brand) {
    case 'kakao':
      return (
        <svg className="sl-symbol" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
          <path
            fill="#000000"
            d="M9 1.5C4.58 1.5 1 4.36 1 7.88c0 2.28 1.5 4.28 3.75 5.41l-.76 2.78c-.07.25.21.45.43.31l3.32-2.2c.41.05.83.08 1.26.08 4.42 0 8-2.86 8-6.38S13.42 1.5 9 1.5z"
          />
        </svg>
      );
    case 'naver':
      return (
        <svg className="sl-symbol" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
          <path fill="#FFFFFF" d="M13.56 10.7L6.17 0H0v20h6.44V9.3L13.83 20H20V0h-6.44z" transform="translate(3 3) scale(0.7)" />
        </svg>
      );
    case 'google':
      return (
        <svg className="sl-symbol" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
        </svg>
      );
    case 'facebook':
      return (
        <svg className="sl-symbol" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path
            fill="#FFFFFF"
            d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"
          />
        </svg>
      );
    default:
      return (
        <span className="sl-symbol sl-symbol--initial" aria-hidden="true">
          {(label.trim().charAt(0) || '?').toUpperCase()}
        </span>
      );
  }
};

ProviderSymbol.displayName = 'ProviderSymbol';
