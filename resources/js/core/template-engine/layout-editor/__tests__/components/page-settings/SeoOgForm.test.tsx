// e2e:allow 레이아웃 편집기 SEO og/twitter 3계층 폼 — I18nTextField/데이터칩 합성 의존, Chrome MCP 매트릭스(세션 D) + 단위로 검증
/**
 * SeoOgForm.test.tsx — og/twitter 3계층 + 출처 + override RTL
 *
 * 검증:
 *  ① 빈 칸 placeholder=서버 미리보기 기본값 + 출처 배지〔코어/이커머스/필터〕
 *  ② 코어 값 있는 키(site_name)=core 출처 배지
 *  ③ 입력 시 레이아웃 override 패치
 *  ④ og.title/description/image_alt 키화(I18nTextField mock)
 *  ⑤ og.image=데이터칩 값(DataChipValueInput)
 *  ⑥ filter_og_data 가 덮는 키만 🔒 읽기전용(lockedByFilter), 안 덮는 키 편집 가능
 *  ⑦ og.extra[]/twitter.extra[]={property/name,content} 배열
 *  ⑩ 미리보기 응답 없음(defaultsAvailable=false) → 기본값 placeholder/배지 미표시
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';

vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ value, onChange, placeholder, testidPrefix }: { value: string; onChange: (v: string | undefined) => void; placeholder?: string; testidPrefix: string }) => (
    <input data-testid={`${testidPrefix}-mock`} value={value ?? ''} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
  ),
}));

import { SeoOgForm, type SeoPreviewRow } from '../../../components/page-settings/SeoOgForm';

const t = (k: string) => k;
afterEach(() => cleanup());

const ogPreview: SeoPreviewRow[] = [
  { key: 'title', effectiveValue: '신선한 원두', source: 'module', overriddenByLayout: false, lockedByFilter: false },
  { key: 'site_name', effectiveValue: '우리 사이트', source: 'core', overriddenByLayout: false, lockedByFilter: false },
  { key: 'image_width', effectiveValue: 1200, source: 'filter', overriddenByLayout: false, lockedByFilter: true },
];

describe('SeoOgForm — 3계층 + 출처 + override', () => {
  it('① 빈 칸 placeholder=기본값 + ② 코어 출처 배지', () => {
    render(
      <SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />,
    );
    // 모듈 기본값(title) placeholder.
    expect(screen.getByTestId('g7le-seo-og-title-field-mock')).toHaveAttribute('placeholder', '신선한 원두');
    // 코어 출처 칩 — #4 로 칸 아래 별도 줄 → 칸 안 어포던스로 이관(site_name=textRow).
    expect(screen.getByTestId('g7le-seo-og-site_name-overlay-source')).toBeInTheDocument();
  });

  it('#4 칸 안 어포던스 — 빈 칸=출처 칩, 값 입력(서버가 overriddenByLayout) 시 되돌리기로 전환', () => {
    const onChangeOg = vi.fn();
    const { rerender } = render(
      <SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />,
    );
    // 빈 칸 → 출처 칩 노출, 되돌리기 부재.
    expect(screen.getByTestId('g7le-seo-og-site_name-overlay-source')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-og-site_name-overlay-revert')).not.toBeInTheDocument();
    // 값 채움 → 미리보기 재계산(SEO-A)으로 그 키가 overriddenByLayout=true 로 반환됨 →
    // 출처 칩 사라지고 되돌리기 노출. "내가 채움" 판정은 서버 overriddenByLayout 에 종속(SEO-B).
    const overriddenPreview: SeoPreviewRow[] = ogPreview.map((r) =>
      r.key === 'site_name' ? { ...r, source: 'layout', overriddenByLayout: true } : r,
    );
    rerender(
      <SeoOgForm og={{ site_name: '나의 쇼핑몰' }} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={overriddenPreview} defaultsAvailable t={t} />,
    );
    expect(screen.queryByTestId('g7le-seo-og-site_name-overlay-source')).not.toBeInTheDocument();
    const revert = screen.getByTestId('g7le-seo-og-site_name-overlay-revert');
    expect(revert).toBeInTheDocument();
    // 되돌리기 클릭 → 그 키 제거(기본값 복귀).
    fireEvent.click(revert);
    expect(onChangeOg).toHaveBeenLastCalledWith({});
  });

  // SEO-B — base(공통 레이아웃) 상속 키는 〔공통 레이아웃〕 출처 칩 노출.
  // 서버 미리보기가 source='inherited' + overriddenByLayout=false 로 분류한 키는 이 화면이
  // 직접 채우지 않은 상속값 → 칩 노출 + 입력칸은 비움(effectiveValue 는 placeholder).
  it('SEO-B: 상속(inherited) 출처 키 → 〔공통 레이아웃〕 칩 + 입력칸 비움', () => {
    const inheritedPreview: SeoPreviewRow[] = [
      { key: 'site_name', effectiveValue: '공통 사이트명', source: 'inherited', overriddenByLayout: false, inheritedFromBase: true, lockedByFilter: false },
    ];
    // og 병합본엔 base 상속값이 들어와 있으나(site_name='공통 사이트명'), 서버가 inherited 로 판정.
    render(
      <SeoOgForm og={{ site_name: '공통 사이트명' }} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={inheritedPreview} defaultsAvailable t={t} />,
    );
    // 상속 출처 칩 노출(코어가 아니라 공통 레이아웃 라벨).
    const chip = screen.getByTestId('g7le-seo-og-site_name-overlay-source');
    expect(chip).toHaveTextContent('layout_editor.page_settings.seo.og_source_inherited');
    // 되돌리기 부재(상속은 내가 안 채움 → 되돌릴 것 없음).
    expect(screen.queryByTestId('g7le-seo-og-site_name-overlay-revert')).not.toBeInTheDocument();
    // 입력칸은 비움(병합값을 "내가 채움"으로 박지 않음) + effectiveValue 는 placeholder.
    // site_name 텍스트 칸이 DataChipValueInput(데이터 칩)으로 승격. 실제 입력은
    // DataChipValueInput 평문 분기의 `-chip-input`(상속/출처칩/되돌리기 래핑·placeholder 유지).
    const input = screen.getByTestId('g7le-seo-og-site_name-chip-input');
    expect(input).toHaveValue('');
    expect(input).toHaveAttribute('placeholder', '공통 사이트명');
  });

  it('SEO-B: og.type 상속 → select 라벨 옆 출처 + select 빈값(기본값 사용)', () => {
    const inheritedPreview: SeoPreviewRow[] = [
      { key: 'type', effectiveValue: 'website', source: 'inherited', overriddenByLayout: false, inheritedFromBase: true, lockedByFilter: false },
    ];
    render(
      <SeoOgForm og={{ type: 'website' }} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={inheritedPreview} defaultsAvailable t={t} />,
    );
    expect(screen.getByTestId('g7le-seo-og-type-source-inline')).toHaveTextContent('layout_editor.page_settings.seo.og_source_inherited');
    // 상속이므로 select 는 "기본값 사용"(빈값) — base 값을 직접 고른 것처럼 박지 않음.
    expect(screen.getByTestId('g7le-seo-og-type-select')).toHaveValue('');
  });

  it('고급 이미지 옵션 접이식(▸) — 펼친 뒤 세로·형식·보안 URL 노출 + 패치 (W1 L1179)', () => {
    const onChangeOg = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    // 접이식 — 기본 닫힘(고급값 없음). 토글 전엔 고급 필드 DOM 부재.
    expect(screen.queryByTestId('g7le-seo-og-image-adv')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-seo-og-image-adv-toggle'));
    // 펼친 뒤 3개 필드(세로·형식·보안 URL).
    expect(screen.getByTestId('g7le-seo-og-image-adv')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-og-image_height')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-og-image_type')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-og-image_secure_url')).toBeInTheDocument();
    // 형식 입력 → og.image_type 패치.textRow 7칸 데이터칩 승격(공용 헬퍼).
    // 실제 입력은 DataChipValueInput 평문 분기 `-chip-input`(평문/숫자/MIME 그대로 입력, 키화 0).
    fireEvent.change(screen.getByTestId('g7le-seo-og-image_type-chip-input'), { target: { value: 'image/png' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ image_type: 'image/png' });
  });

  it('고급 이미지 옵션 — 기존 값 있으면 기본 펼침 (W1)', () => {
    render(<SeoOgForm og={{ image_secure_url: 'https://x/y.png' }} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    // 값 보유 → 접이식 기본 펼침(놓치지 않게).
    expect(screen.getByTestId('g7le-seo-og-image-adv')).toBeInTheDocument();
    // image_secure_url(URL) 데이터칩 승격. 평문 URL 은 DataChipValueInput 평문 분기에 그대로.
    expect(screen.getByTestId('g7le-seo-og-image_secure_url-chip-input')).toHaveValue('https://x/y.png');
  });

  it('③④ og.title 입력 → 레이아웃 override 패치(I18nTextField)', () => {
    const onChangeOg = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    fireEvent.change(screen.getByTestId('g7le-seo-og-title-field-mock'), { target: { value: '[상품이름] 추천' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ title: '[상품이름] 추천' });
  });

  it('⑤ og.image = 데이터칩 값(DataChipValueInput) 입력 → 패치', () => {
    const onChangeOg = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    const imageInput = within(screen.getByTestId('g7le-seo-og-image')).getByTestId('g7le-seo-og-image-field-input');
    fireEvent.change(imageInput, { target: { value: '{{product.thumbnail}}' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ image: '{{product.thumbnail}}' });
  });

  // site_name/locale 등 보조 텍스트 칸도 데이터 칩(단순 데이터 연동).
  // 평문은 종전처럼 그대로 패치(키화 0), 데이터(`{{...}}`)는 칩/표현식. 상속/출처 래핑은 유지.
  it('og.site_name = 데이터칩 입력 → 평문/데이터 모두 패치(키화 0)', () => {
    const onChangeOg = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    const input = screen.getByTestId('g7le-seo-og-site_name-chip-input');
    fireEvent.change(input, { target: { value: '내 사이트' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ site_name: '내 사이트' });
    // 데이터 연동 값도 그대로(키화 없음).
    fireEvent.change(input, { target: { value: '{{site.name}}' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ site_name: '{{site.name}}' });
  });

  it('⑥ filter 가 덮는 키(lockedByFilter)만 🔒 읽기전용, 안 덮는 키 편집 가능', () => {
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    // title 은 잠김 아님 → 입력칸 존재.
    expect(screen.getByTestId('g7le-seo-og-title-field-mock')).toBeInTheDocument();
    // image_width 는 텍스트/i18n 행이 아니므로 별도; site_name 잠김 아님 → 데이터칩 input 존재.
    expect(screen.getByTestId('g7le-seo-og-site_name-chip-input')).toBeInTheDocument();
  });

  it('⑥ 잠긴 키는 읽기전용 값 표시(서버 effectiveValue)', () => {
    const lockedPreview: SeoPreviewRow[] = [
      { key: 'title', effectiveValue: '잠긴 제목', source: 'filter', overriddenByLayout: false, lockedByFilter: true },
    ];
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={lockedPreview} defaultsAvailable t={t} />);
    expect(screen.getByTestId('g7le-seo-og-locked-value-title')).toHaveTextContent('잠긴 제목');
    // 잠긴 키는 편집 입력칸 없음.
    expect(screen.queryByTestId('g7le-seo-og-title-field-mock')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-og-locked-title')).toBeInTheDocument();
  });

  it('⑦ og.extra[] {property,content} 배열 직렬화', () => {
    const onChangeOg = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable t={t} />);
    const extra = screen.getByTestId('g7le-seo-og-extra-editor');
    fireEvent.click(within(extra).getByTestId('g7le-seo-og-extra-editor-add'));
    const keyInput = within(extra).getByTestId(/g7le-seo-og-extra-editor-key-/);
    fireEvent.change(keyInput, { target: { value: 'og:price' } });
    expect(onChangeOg).toHaveBeenLastCalledWith({ extra: [{ property: 'og:price', content: '' }] });
  });

  it('⑦ twitter.extra[] {name,content} 배열', () => {
    const onChangeTwitter = vi.fn();
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={onChangeTwitter} ogPreview={ogPreview} twitterPreview={[]} defaultsAvailable t={t} />);
    const extra = screen.getByTestId('g7le-seo-tw-extra-editor');
    fireEvent.click(within(extra).getByTestId('g7le-seo-tw-extra-editor-add'));
    const keyInput = within(extra).getByTestId(/g7le-seo-tw-extra-editor-key-/);
    fireEvent.change(keyInput, { target: { value: 'twitter:label1' } });
    expect(onChangeTwitter).toHaveBeenLastCalledWith({ extra: [{ name: 'twitter:label1', content: '' }] });
  });

  it('⑩ defaultsAvailable=false → 기본값 placeholder/배지 미표시', () => {
    render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={ogPreview} defaultsAvailable={false} t={t} />);
    // placeholder 없음(기본값 미표시).
    expect(screen.getByTestId('g7le-seo-og-title-field-mock')).not.toHaveAttribute('placeholder');
    // 출처 배지 미표시.
    expect(screen.queryByTestId('g7le-seo-og-source-site_name')).not.toBeInTheDocument();
  });

  // ── 묶음③ 모듈 자동값 → 연결 칩(역산출) ──
  describe('연결 칩', () => {
    // 서버가 module 출처 + sourceExpr/label 동반한 og.image(chipRow) / og.image_alt(i18nRow).
    const autoPreview: SeoPreviewRow[] = [
      { key: 'image', effectiveValue: 'https://cdn/p.jpg', source: 'module:sirsoft-ecommerce', overriddenByLayout: false, lockedByFilter: false, sourceExpr: '{{product.data.thumbnail_url}}', label: '상품 대표 이미지' },
      { key: 'image_alt', effectiveValue: '베이직 티셔츠', source: 'module:sirsoft-ecommerce', overriddenByLayout: false, lockedByFilter: false, sourceExpr: '{{product.data.name}}', label: '상품 이름' },
    ];

    it('자동값(chipRow=image) → 입력칸 대신 연결 칩(라벨) + 출처 배지 + 바꾸기 버튼', () => {
      render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={autoPreview} defaultsAvailable t={t} />);
      // 연결 칩 — 라벨("상품 대표 이미지") 표시, raw 표현식은 title 속성으로만.
      const chip = screen.getByTestId('g7le-seo-og-image-auto-chip');
      expect(chip).toHaveTextContent('상품 대표 이미지');
      expect(chip).toHaveAttribute('title', '{{product.data.thumbnail_url}}');
      expect(screen.getByTestId('g7le-seo-og-image-auto-badge')).toBeInTheDocument();
      // 자동값일 땐 평문 입력칸 미노출(연결 칩으로 대체).
      expect(screen.queryByTestId('g7le-seo-og-image-field-input')).not.toBeInTheDocument();
    });

    it('자동값(i18nRow=image_alt) → 연결 칩 + I18nTextField 입력칸 미노출', () => {
      render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={autoPreview} defaultsAvailable t={t} />);
      expect(screen.getByTestId('g7le-seo-og-image_alt-auto-chip')).toHaveTextContent('상품 이름');
      expect(screen.queryByTestId('g7le-seo-og-image_alt-field-mock')).not.toBeInTheDocument();
    });

    it('"다른 데이터로 바꾸기" 클릭 → sourceExpr 을 레이아웃 값으로 채워 override 진입', () => {
      const onChangeOg = vi.fn();
      render(<SeoOgForm og={{}} twitter={{}} onChangeOg={onChangeOg} onChangeTwitter={vi.fn()} ogPreview={autoPreview} defaultsAvailable t={t} />);
      fireEvent.click(screen.getByTestId('g7le-seo-og-image-auto-replace'));
      // 그 표현식이 레이아웃 og.image 값으로 채워짐(다음 미리보기에서 source=layout → 편집칸 전환).
      expect(onChangeOg).toHaveBeenLastCalledWith({ image: '{{product.data.thumbnail_url}}' });
    });

    it('override 후(서버 overriddenByLayout=true) → 연결 칩 대신 DataChipValueInput(회귀: 자동 칩 고착 안 됨)', () => {
      const overridden: SeoPreviewRow[] = autoPreview.map((r) =>
        r.key === 'image' ? { ...r, source: 'layout', overriddenByLayout: true } : r,
      );
      render(<SeoOgForm og={{ image: '{{product.data.thumbnail_url}}' }} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={overridden} defaultsAvailable t={t} />);
      // 내가 덮은 키는 자동 칩 미노출 → DataChipValueInput 으로 전환(값이 단일 바인딩이라
      // 그 안에서 친화 칩 + ✎ 편집 버튼을 그린다 — auto-chip 이 아닌 일반 데이터칩 경로).
      expect(screen.queryByTestId('g7le-seo-og-image-auto-chip')).not.toBeInTheDocument();
      expect(within(screen.getByTestId('g7le-seo-og-image')).getByTestId('g7le-seo-og-image-field-edit-raw')).toBeInTheDocument();
    });

    it('회귀: 메타 미동반(sourceExpr 없음) 모듈 출처는 종전 평문 placeholder 경로 유지', () => {
      const plain: SeoPreviewRow[] = [
        { key: 'image', effectiveValue: 'https://cdn/p.jpg', source: 'module:foo', overriddenByLayout: false, lockedByFilter: false },
      ];
      render(<SeoOgForm og={{}} twitter={{}} onChangeOg={vi.fn()} onChangeTwitter={vi.fn()} ogPreview={plain} defaultsAvailable t={t} />);
      // 연결 칩 미노출 + 종전 데이터칩 입력칸 placeholder=평문.
      expect(screen.queryByTestId('g7le-seo-og-image-auto-chip')).not.toBeInTheDocument();
      expect(within(screen.getByTestId('g7le-seo-og-image')).getByTestId('g7le-seo-og-image-field-input')).toHaveAttribute('placeholder', 'https://cdn/p.jpg');
    });
  });
});
