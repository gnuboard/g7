import React from 'react';
import { ProviderSymbol, type ProviderBrand } from '../../support/ProviderSymbol';

export interface ProviderMarkProps {
  /** 버튼 모양 계열 — 서버가 `brand` 로 내려준다(모의 제공자도 원 제공자 계열). 내장 4종 밖은 custom */
  brand?: string;

  /** 커스텀 제공자 첫 글자 원형에 쓸 이름 */
  label?: string;

  /** 사용자 정의 클래스 */
  className?: string;

  /** DOM id */
  id?: string;
}

/** 내장 버튼 모양 계열 */
const BRANDS: ProviderBrand[] = ['kakao', 'naver', 'google', 'facebook'];

/**
 * 연결 목록 한 줄 앞에 붙는 제공자 표시.
 *
 * 로그인 버튼과 같은 공식 심볼을 제공자 버튼 색 원 안에 그린다(흰 심볼인 네이버·페이스북도 보이게). 이름은 옆 텍스트가
 * 맡으므로 장식으로 숨긴다.
 */
export const ProviderMark: React.FC<ProviderMarkProps> = ({ brand, label = '', className = '', id }) => {
  const resolved: ProviderBrand = BRANDS.includes(brand as ProviderBrand) ? (brand as ProviderBrand) : 'custom';

  return (
    <span id={id} className={`sl-mark sl-mark--${resolved} ${className}`.trim()} data-social-provider-mark={resolved} aria-hidden="true">
      <ProviderSymbol brand={resolved} label={label} />
    </span>
  );
};

ProviderMark.displayName = 'ProviderMark';
