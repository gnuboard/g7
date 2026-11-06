/**
 * @file hero_extensions.test.tsx
 * @description 사용자 화면 주입 조각 — 홈·쇼핑 조각이 각자의 확장점을 겨냥하고, 공개 API 로 받은 배너가 있을 때만 슬라이더를
 * 그리며(0건·받기 실패면 숨김), data-source 이름은 플러그인 접두를 쓴다. (매니페스트 user-extension.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import home from '../../../extensions/home_hero.json';
import shop from '../../../extensions/shop_hero.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { pick, strings } from './helpers';

const fragments = [
  { doc: home as any, point: 'home_hero', source: 'heroSectionHome' },
  { doc: shop as any, point: 'shop_hero', source: 'heroSectionShop' },
];

describe('사용자 화면 주입 조각', () => {
  // @scenario surface=home_extension_targets_home_hero
  // @effects extension_injected_conditionally
  it('홈 조각은 home_hero 자리에 붙고 그 위치 배너를 받는다', () => {
    expect(home.extension_point).toBe('home_hero');
    expect(home.mode).toBe('append');
    const source = (home as any).data_sources[0];
    expect(source.endpoint).toBe('/api/plugins/sirsoft-hero_section/hero');
    expect(source.params).toEqual({ position: 'home_hero' });
    expect(source.auth_required).toBe(false);
    expect((home as any).components[0].props.position).toBe('home_hero');
  });

  // @scenario surface=shop_extension_targets_shop_hero
  // @effects extension_injected_conditionally
  it('쇼핑 조각은 shop_hero 자리에 붙고 그 위치 배너를 받는다', () => {
    expect(shop.extension_point).toBe('shop_hero');
    const source = (shop as any).data_sources[0];
    expect(source.params).toEqual({ position: 'shop_hero' });
    expect((shop as any).components[0].props.position).toBe('shop_hero');
  });

  // @scenario surface=hidden_when_no_banners
  // @scenario surface=loading_skeleton_until_response
  it('슬라이더는 늘 붙고, 응답 전(값 없음)이면 받는 중으로 알려 스켈레톤을 그리게 하며 0건이면 슬라이더가 스스로 숨는다', () => {
    for (const { doc, source } of fragments) {
      const slider = doc.components[0];
      expect(slider.name).toBe('sirsoft-hero_section.HeroSlider');
      // 0건 숨김은 슬라이더가 맡는다 — 조각에 if 를 두면 받는 동안 자리를 잡지 못한다
      expect(slider.if).toBeUndefined();
      expect(slider.props.banners).toBe(`{{${source}?.data?.banners ?? []}}`);
      expect(slider.props.loading).toBe(`{{${source} == null}}`);
      // 받는 동안의 폭은 첫 로드 때 받은 플러그인 설정 힌트로 고른다
      expect(slider.props.skeletonWidth).toBe(`{{_global.plugins?.['sirsoft-hero_section']?.slot_widths?.${slider.props.position} ?? ''}}`);
      // 배너가 없는 위치는 첫 접속(브라우저 기억 없음)에도 자리를 잡지 않는다 — 첫 로드 힌트로 고른다
      expect(slider.props.skeletonBanners).toBe(`{{_global.plugins?.['sirsoft-hero_section']?.slot_banners?.${slider.props.position}}}`);
    }
  });

  // @scenario surface=hidden_when_fetch_fails
  it('받기에 실패하면 빈 값으로 떨어져 화면 오류 없이 숨는다', () => {
    for (const { doc } of fragments) {
      const source = doc.data_sources[0];
      expect(source.fallback).toEqual({ data: null });
      // 오류 화면·알림으로 바꾸지 않는다
      expect(JSON.stringify(source)).not.toContain('showErrorPage');
      expect(JSON.stringify(source)).not.toContain('toast');
    }
  });

  // @scenario surface=data_source_keys_prefixed
  it('조각의 data-source·전역 키는 플러그인 접두(heroSection)를 쓴다', () => {
    for (const { doc, source } of fragments) {
      expect(doc.data_sources.map((s: any) => s.id)).toEqual([source]);
      expect(source.startsWith('heroSection')).toBe(true);
      // 조각이 쓰는 전역 키는 플러그인 접두다. 코어가 첫 로드 때 주는 플러그인 설정(`_global.plugins`)만 읽기로 예외다
      const globals = strings(doc).flatMap((t) => [...t.matchAll(/_global\.([A-Za-z_]\w*)/g)].map((m) => m[1]));
      expect(globals.filter((k) => k !== 'plugins').every((k) => k.startsWith('heroSection'))).toBe(true);
      expect(JSON.stringify(doc)).not.toMatch(/"global"[^}]*"plugins"|plugins=/);
      const key = (doc.data_sources[0].label_key as string).replace('$t:sirsoft-hero_section.', '');
      expect(pick(ko, key)).toBeTruthy();
      expect(pick(en, key)).toBeTruthy();
    }
  });

  // @scenario surface=extension_passes_content_class
  it('템플릿이 확장점으로 넘긴 본문 상자 클래스를 슬라이더에 넘기고, 데스크톱은 따로 넘긴 값을 쓴다(없으면 기본 값)', () => {
    for (const { doc } of fragments) {
      const slider = doc.components[0];
      expect(slider.props.contentClass).toBe("{{extensionPointProps?.contentClass ?? ''}}");
      expect(slider.responsive.desktop.props.contentClass).toBe("{{extensionPointProps?.contentClassDesktop ?? extensionPointProps?.contentClass ?? ''}}");
      // 표시 폭은 공개 API 설정(settings.width)으로 받는다
      expect(slider.props.settings).toMatch(/\?\.data\?\.settings}}$/);
    }
  });
});
