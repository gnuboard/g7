/**
 * 이커머스 SEO 설정 탭 레이아웃 구조 검증 테스트
 *
 * @description
 * - _tab_seo.json 구조 검증 (Phase 1 변경사항 반영)
 * - 메인화면 SEO / UA 섹션 제거 확인 (코어로 이관됨)
 * - 메타 설정 카드 — 카테고리/검색/상품 아코디언 존재
 * - SEO Friendly 카드 — 페이지 제공 체크박스, 캐시 관리 섹션
 * - 폼 바인딩 (seo.* name 속성) 검증
 * - 다국어 키 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import tabSeo from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_seo.json';

/**
 * 재귀적으로 컴포넌트 트리에서 id로 검색
 */
function findById(node: any, id: string): any | null {
  if (!node) return null;
  if (node.id === id) return node;
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      const found = findById(child, id);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 재귀적으로 컴포넌트 트리에서 name(컴포넌트명)으로 모든 항목 검색
 */
function findAllByComponentName(node: any, name: string): any[] {
  const results: any[] = [];
  if (!node) return results;
  if (node.name === name) results.push(node);
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      results.push(...findAllByComponentName(child, name));
    }
  }
  return results;
}

/**
 * 재귀적으로 props.name 속성으로 Input/Textarea/Select 검색
 */
function findByPropName(node: any, propName: string): any | null {
  if (!node) return null;
  if (node.props?.name === propName) return node;
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      const found = findByPropName(child, propName);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 재귀적으로 $t: 다국어 키 수집
 */
function collectI18nKeys(node: any): string[] {
  const keys: string[] = [];
  if (!node) return keys;

  if (typeof node.text === 'string' && node.text.includes('$t:')) {
    // "$t:key" 또는 "$t:key: suffix" 형태에서 키 추출
    const match = node.text.match(/\$t:([^\s:]+)/);
    if (match) keys.push(match[1]);
  }
  if (node.props) {
    for (const val of Object.values(node.props)) {
      if (typeof val === 'string' && val.includes('$t:')) {
        const match = (val as string).match(/\$t:([^\s:]+)/);
        if (match) keys.push(match[1]);
      }
    }
  }
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      keys.push(...collectI18nKeys(child));
    }
  }
  return keys;
}

describe('이커머스 SEO 설정 탭 (_tab_seo.json)', () => {
  // ==============================
  // 1. Partial 메타 검증
  // ==============================
  describe('Partial 메타 정보', () => {
    it('is_partial이 true이다', () => {
      expect(tabSeo.meta.is_partial).toBe(true);
    });

    it('루트 ID는 tab_content_seo이다', () => {
      expect(tabSeo.id).toBe('tab_content_seo');
    });

    it('탭 활성화 조건(if)이 seo 탭을 체크한다', () => {
      expect(tabSeo.if).toContain("'seo'");
      expect(tabSeo.if).toContain('activeEcommerceSettingsTab');
    });
  });

  // ==============================
  // 2. Phase 1 변경사항 — 제거된 섹션 확인
  // ==============================
  describe('Phase 1 변경사항 — 코어 이관 섹션 제거', () => {
    it('메인화면 SEO 설정 섹션(main_seo_card)이 없다', () => {
      const mainSeoCard = findById(tabSeo, 'main_seo_card');
      expect(mainSeoCard).toBeNull();
    });

    it('User-Agent 관리 섹션(ua_card 또는 bot_ua)이 없다', () => {
      const uaCard = findById(tabSeo, 'ua_card');
      const botUa = findById(tabSeo, 'bot_ua_card');
      expect(uaCard).toBeNull();
      expect(botUa).toBeNull();
    });

    it('seo_user_agents 바인딩이 없다 (코어로 이관됨)', () => {
      const uaInput = findByPropName(tabSeo, 'seo.seo_user_agents');
      expect(uaInput).toBeNull();
    });

    it('seo_meta_title 바인딩이 없다 (코어로 이관됨)', () => {
      const metaTitleInput = findByPropName(tabSeo, 'seo.seo_meta_title');
      expect(metaTitleInput).toBeNull();
    });

    it('seo_meta_description 바인딩이 없다 (코어로 이관됨)', () => {
      const metaDescInput = findByPropName(tabSeo, 'seo.seo_meta_description');
      expect(metaDescInput).toBeNull();
    });
  });

  // ==============================
  // 3. 메타 설정 카드 구조
  // ==============================
  describe('메타 설정 카드 (meta_settings_card)', () => {
    it('meta_settings_card가 존재한다', () => {
      const card = findById(tabSeo, 'meta_settings_card');
      expect(card).not.toBeNull();
    });

    it('카드 제목이 다국어 키를 사용한다', () => {
      const title = findById(tabSeo, 'meta_settings_title');
      expect(title).not.toBeNull();
      expect(title.text).toContain('$t:sirsoft-ecommerce.admin.settings.seo.meta_settings.title');
    });

    it('카드 설명이 다국어 키를 사용한다', () => {
      const desc = findById(tabSeo, 'meta_settings_description');
      expect(desc).not.toBeNull();
      expect(desc.text).toContain('$t:sirsoft-ecommerce.admin.settings.seo.meta_settings.description');
    });

    describe('카테고리 페이지 아코디언', () => {
      it('accordion_category_page가 존재한다', () => {
        const accordion = findById(tabSeo, 'accordion_category_page');
        expect(accordion).not.toBeNull();
      });

      it('meta_category_title Input이 존재한다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_category_title');
        expect(input).not.toBeNull();
        expect(input.name).toBe('Input');
        expect(input.props.type).toBe('text');
      });

      it('meta_category_description Textarea가 존재한다', () => {
        const textarea = findByPropName(tabSeo, 'seo.meta_category_description');
        expect(textarea).not.toBeNull();
        expect(textarea.name).toBe('Textarea');
      });

      it('카테고리 title 에러 표시 조건이 올바르다', () => {
        const accordion = findById(tabSeo, 'accordion_category_page_body');
        expect(accordion).not.toBeNull();

        const titleField = findById(tabSeo, 'field_category_title');
        expect(titleField).not.toBeNull();

        // 에러 Span의 if 조건 확인
        const errorSpans = findAllByComponentName(titleField, 'Span')
          .filter((s: any) => s.if && s.if.includes('errors'));
        expect(errorSpans.length).toBeGreaterThan(0);
        expect(errorSpans[0].if).toContain("_local.errors?.['seo.meta_category_title']");
      });

      it('카테고리 title placeholder에 변수 패턴이 있다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_category_title');
        expect(input.props.placeholder).toContain('{commerce_name}');
        expect(input.props.placeholder).toContain('{category_name}');
      });
    });

    describe('검색 페이지 아코디언', () => {
      it('accordion_search_page가 존재한다', () => {
        const accordion = findById(tabSeo, 'accordion_search_page');
        expect(accordion).not.toBeNull();
      });

      it('meta_search_title Input이 존재한다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_search_title');
        expect(input).not.toBeNull();
        expect(input.name).toBe('Input');
      });

      it('meta_search_description Textarea가 존재한다', () => {
        const textarea = findByPropName(tabSeo, 'seo.meta_search_description');
        expect(textarea).not.toBeNull();
        expect(textarea.name).toBe('Textarea');
      });

      it('검색 title placeholder에 변수 패턴이 있다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_search_title');
        expect(input.props.placeholder).toContain('{commerce_name}');
        expect(input.props.placeholder).toContain('{keyword_name}');
      });
    });

    describe('상품 페이지 아코디언', () => {
      it('accordion_product_page가 존재한다', () => {
        const accordion = findById(tabSeo, 'accordion_product_page');
        expect(accordion).not.toBeNull();
      });

      it('meta_product_title Input이 존재한다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_product_title');
        expect(input).not.toBeNull();
        expect(input.name).toBe('Input');
      });

      it('meta_product_description Textarea가 존재한다', () => {
        const textarea = findByPropName(tabSeo, 'seo.meta_product_description');
        expect(textarea).not.toBeNull();
        expect(textarea.name).toBe('Textarea');
      });

      it('상품 title placeholder에 변수 패턴이 있다', () => {
        const input = findByPropName(tabSeo, 'seo.meta_product_title');
        expect(input.props.placeholder).toContain('{commerce_name}');
        expect(input.props.placeholder).toContain('{product_name}');
      });
    });

    it('meta_accordions에 3개의 아코디언이 있다', () => {
      const accordions = findById(tabSeo, 'meta_accordions');
      expect(accordions).not.toBeNull();
      expect(accordions.children).toHaveLength(3);
    });
  });

  // ==============================
  // 4. SEO Friendly 카드 구조
  // ==============================
  describe('SEO Friendly 카드 (seo_friendly_card)', () => {
    it('seo_friendly_card가 존재한다', () => {
      const card = findById(tabSeo, 'seo_friendly_card');
      expect(card).not.toBeNull();
    });

    it('카드 제목이 다국어 키를 사용한다', () => {
      const title = findById(tabSeo, 'seo_friendly_title');
      expect(title).not.toBeNull();
      expect(title.text).toContain('$t:sirsoft-ecommerce.admin.settings.seo.seo_friendly.title');
    });

    describe('페이지 제공 체크박스', () => {
      it('seo_category 체크박스가 존재한다', () => {
        const checkbox = findByPropName(tabSeo, 'seo.seo_category');
        expect(checkbox).not.toBeNull();
        expect(checkbox.props.type).toBe('checkbox');
        expect(checkbox.props.className).toBe('checkbox');
      });

      it('seo_search_result 체크박스가 존재한다', () => {
        const checkbox = findByPropName(tabSeo, 'seo.seo_search_result');
        expect(checkbox).not.toBeNull();
        expect(checkbox.props.type).toBe('checkbox');
      });

      it('seo_product_detail 체크박스가 존재한다', () => {
        const checkbox = findByPropName(tabSeo, 'seo.seo_product_detail');
        expect(checkbox).not.toBeNull();
        expect(checkbox.props.type).toBe('checkbox');
      });

      it('모든 체크박스의 기본값이 true이다', () => {
        const category = findByPropName(tabSeo, 'seo.seo_category');
        const search = findByPropName(tabSeo, 'seo.seo_search_result');
        const product = findByPropName(tabSeo, 'seo.seo_product_detail');

        expect(category.props.checked).toBe(true);
        expect(search.props.checked).toBe(true);
        expect(product.props.checked).toBe(true);
      });
    });

    describe('캐시 관리 섹션', () => {
      it('cache_section이 존재한다', () => {
        const section = findById(tabSeo, 'cache_section');
        expect(section).not.toBeNull();
      });

      it('캐시 관련 다국어 키가 사용된다', () => {
        const section = findById(tabSeo, 'cache_section');
        const keys = collectI18nKeys(section);
        expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.seo_friendly.cache_management');
        expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.seo_friendly.cache_description');
      });

      it('캐시 삭제 버튼이 존재한다', () => {
        const section = findById(tabSeo, 'cache_section');
        const buttons = findAllByComponentName(section, 'Button');
        expect(buttons.length).toBeGreaterThan(0);

        const clearBtn = buttons.find((b: any) =>
          b.text?.includes('$t:sirsoft-ecommerce.admin.settings.seo.seo_friendly.clear_cache')
        );
        expect(clearBtn).toBeDefined();
        expect(clearBtn.props.type).toBe('button');
        expect(clearBtn.props.className).toContain('btn-danger');
      });

      it('캐시 정보 행(cache_info_row)이 존재한다', () => {
        const row = findById(tabSeo, 'cache_info_row');
        expect(row).not.toBeNull();
      });
    });
  });

  // ==============================
  // 5. 전체 구조 검증
  // ==============================
  describe('전체 구조', () => {
    it('루트 children에 2개의 카드가 있다 (meta_settings + seo_friendly)', () => {
      expect(tabSeo.children).toHaveLength(2);
      expect(tabSeo.children[0].id).toBe('meta_settings_card');
      expect(tabSeo.children[1].id).toBe('seo_friendly_card');
    });

    it('모든 Input/Textarea의 name이 seo. 접두사를 사용한다', () => {
      const inputs = findAllByComponentName(tabSeo, 'Input')
        .filter((i: any) => i.props?.name && i.props.type !== 'checkbox');
      const textareas = findAllByComponentName(tabSeo, 'Textarea');

      for (const input of inputs) {
        expect(input.props.name).toMatch(/^seo\./);
      }
      for (const textarea of textareas) {
        expect(textarea.props.name).toMatch(/^seo\./);
      }
    });

    it('체크박스의 name도 seo. 접두사를 사용한다', () => {
      const checkboxes = findAllByComponentName(tabSeo, 'Input')
        .filter((i: any) => i.props?.type === 'checkbox');

      expect(checkboxes.length).toBe(3);
      for (const cb of checkboxes) {
        expect(cb.props.name).toMatch(/^seo\./);
      }
    });
  });

  // ==============================
  // 6. 다국어 키 검증
  // ==============================
  describe('다국어 키', () => {
    it('모든 다국어 키가 sirsoft-ecommerce 네임스페이스를 사용한다', () => {
      const keys = collectI18nKeys(tabSeo);
      expect(keys.length).toBeGreaterThan(0);

      for (const key of keys) {
        expect(key).toMatch(/^sirsoft-ecommerce\./);
      }
    });

    it('meta_settings 관련 키가 포함된다', () => {
      const keys = collectI18nKeys(tabSeo);
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.title');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.description');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.meta_title');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.meta_description');
    });

    it('seo_friendly 관련 키가 포함된다', () => {
      const keys = collectI18nKeys(tabSeo);
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.seo_friendly.title');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.seo_friendly.description');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.seo_friendly.provide_pages');
    });

    it('페이지 아코디언 타이틀 키가 포함된다', () => {
      const keys = collectI18nKeys(tabSeo);
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.category_page');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.search_page');
      expect(keys).toContain('sirsoft-ecommerce.admin.settings.seo.meta_settings.product_page');
    });
  });

  // ==============================
  // 7. 폼 필드 완전성
  // ==============================
  describe('폼 필드 완전성', () => {
    it('메타 설정 Input 6개 (title 3 + description 3)가 존재한다', () => {
      const metaCard = findById(tabSeo, 'meta_settings_card');
      const inputs = findAllByComponentName(metaCard, 'Input');
      const textareas = findAllByComponentName(metaCard, 'Textarea');

      expect(inputs).toHaveLength(3); // category_title, search_title, product_title
      expect(textareas).toHaveLength(3); // category_desc, search_desc, product_desc
    });

    it('SEO Friendly 체크박스 3개가 존재한다', () => {
      const friendlyCard = findById(tabSeo, 'seo_friendly_card');
      const checkboxes = findAllByComponentName(friendlyCard, 'Input')
        .filter((i: any) => i.props?.type === 'checkbox');

      expect(checkboxes).toHaveLength(3);
    });

    it('에러 표시 Span이 6개 존재한다 (각 Input/Textarea마다)', () => {
      const metaCard = findById(tabSeo, 'meta_settings_card');
      const errorSpans = findAllByComponentName(metaCard, 'Span')
        .filter((s: any) => s.if && s.if.includes('_local.errors'));

      expect(errorSpans).toHaveLength(6);
    });

    it('힌트(form-hint) P 태그가 카테고리/검색/상품에 각각 존재한다', () => {
      const metaCard = findById(tabSeo, 'meta_settings_card');
      const hints = findAllByComponentName(metaCard, 'P')
        .filter((p: any) => p.props?.className === 'form-hint');

      // 카테고리: title hint + desc hint, 검색: title hint, 상품: title hint + desc hint = 최소 4개
      expect(hints.length).toBeGreaterThanOrEqual(4);
    });
  });
});
