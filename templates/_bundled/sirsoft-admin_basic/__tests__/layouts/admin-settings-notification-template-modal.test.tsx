/**
 * @file admin-settings-notification-template-modal.test.tsx
 * @description 환경설정 > 알림 템플릿 편집 모달 회귀 테스트 (옵션 K — _global 단일 진실)
 *
 * 패턴:
 * - list 측 edit click → setState target:"global", notification_template_form_modal: { template, definition, recipients, editLang, errors, isSaving, ... }
 * - 모달 표시 — _global.notification_template_form_modal?.X 직접 참조
 * - input change — setState target:"global", "notification_template_form_modal.template.X" / .recipients / .editLang
 * - 다국어 객체 (subject/body) — Object.assign 으로 통째 set
 * - 미리보기 onSuccess — preview namespace 안에 저장 (notification_template_form_modal.preview)
 * - 저장 onSuccess — closeModal + refetchDataSource + null 정리
 */

import { describe, it, expect } from 'vitest';

const tabPartial = require('../../layouts/partials/admin_settings/_tab_notification_definitions.json');
const modalPartial = require('../../layouts/partials/admin_settings/_modal_notification_template_form.json');
const previewPartial = require('../../layouts/partials/admin_settings/_modal_notification_template_preview.json');

interface AnyJson { [k: string]: any }

const collectChangeTargets = (node: AnyJson, acc: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) { node.forEach(item => collectChangeTargets(item, acc)); return acc; }
  if (Array.isArray(node.actions)) {
    for (const a of node.actions) {
      const isChange = a?.type === 'change' || a?.event === 'onChange' || a?.event === 'onSearch';
      if (isChange && a?.handler === 'setState' && a?.params?.target) {
        acc.push(a.params.target);
      }
    }
  }
  for (const k of Object.keys(node)) collectChangeTargets(node[k], acc);
  return acc;
};

describe('환경설정 > 알림 템플릿 편집 모달 회귀 (옵션 K)', () => {
  describe('모달 등록 / 구조', () => {
    /**
     * @effects core_template_modal_uses_the_standard_modal_component
     */
    it('표준 Modal 컴포넌트 구조', () => {
      expect(modalPartial.type).toBe('composite');
      expect(modalPartial.name).toBe('Modal');
      expect(modalPartial.id).toBe('modal_notification_template_form');
    });

    /**
     * @effects core_template_modal_has_no_onmount_lifecycle
     */
    it('lifecycle.onMount 미사용', () => {
      expect(modalPartial.lifecycle).toBeUndefined();
    });

    /**
     * @effects core_template_modal_has_no_datakey
     */
    it('dataKey 미사용', () => {
      expect(JSON.stringify(modalPartial)).not.toContain('"dataKey"');
    });
  });

  describe('list 측 setState — _global namespace 통째 set', () => {
    /**
     * @effects core_template_list_sets_the_whole_namespace
     */
    it('edit click setState 가 _global.notification_template_form_modal 객체 통째 set', () => {
      const tabStr = JSON.stringify(tabPartial);
      expect(tabStr).toContain('"target":"global"');
      expect(tabStr).toContain('"notification_template_form_modal":{');
    });

    /**
     * @effects core_template_namespace_carries_every_editing_key
     */
    it('통째 set 객체에 template/definition/recipients/editLang/errors/isSaving 키 포함', () => {
      const tabStr = JSON.stringify(tabPartial);
      expect(tabStr).toContain('"template":');
      expect(tabStr).toContain('"definition":');
      expect(tabStr).toContain('"recipients":');
      expect(tabStr).toContain('"editLang":');
      expect(tabStr).toContain('"errors":null');
      expect(tabStr).toContain('"isSaving":false');
    });
  });

  describe('사례 13 — 키스트로크당 부모 _local 변경 0회', () => {
    /**
     * @effects core_template_modal_actions_target_global
     */
    it('change/onChange/onSearch 액션은 모두 target:"global"', () => {
      const targets = collectChangeTargets(modalPartial);
      expect(targets.length).toBeGreaterThan(0);
      expect(targets.every(t => t === 'global')).toBe(true);
    });

    /**
     * @effects core_template_modal_never_touches_parent_local
     */
    it('모달 본문에 target:"$parent._local" / "local" 사용 없음', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).not.toContain('"target":"$parent._local"');
      expect(modalStr).not.toContain('"target":"local"');
    });
  });

  describe('옵션 K — _global namespace 단일 진실', () => {
    /**
     * @effects core_template_modal_reads_one_namespace
     */
    it('표시 표현식이 _global.notification_template_form_modal 경로 참조', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.subject');
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.body');
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.click_url');
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.channel');
      expect(modalStr).toContain('_global.notification_template_form_modal?.recipients');
      expect(modalStr).toContain('_global.notification_template_form_modal?.editLang');
      expect(modalStr).toContain('_global.notification_template_form_modal?.errors');
      expect(modalStr).toContain('_global.notification_template_form_modal?.isSaving');
      expect(modalStr).toContain('_global.notification_template_form_modal?.definition');
    });

    /**
     * @effects core_template_subject_and_body_set_whole_objects
     */
    it('subject/body 다국어 객체 — Object.assign 으로 통째 set', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.template.subject":');
      expect(modalStr).toContain('"notification_template_form_modal.template.body":');
      expect(modalStr).toContain('Object.assign({}, _global.notification_template_form_modal?.template?.subject');
      expect(modalStr).toContain('Object.assign({}, _global.notification_template_form_modal?.template?.body');
    });

    /**
     * @effects core_template_recipients_replace_the_whole_array
     */
    it('recipients 배열 — .map/.filter 로 통째 교체', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.recipients":');
      expect(modalStr).toContain('(_global.notification_template_form_modal?.recipients ?? [])');
    });

    /**
     * @effects core_template_lang_tab_writes_the_namespace_path
     */
    it('editLang 탭 클릭 — dot path 로 변경', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.editLang":');
    });
  });

  // 회귀: 역할/사용자 드롭다운이 저장된 선택값을 표시하지 못하던 버그.
  // SearchableDropdown 은 value 를 options 에서 찾아 라벨을 표시하므로,
  // 검색 전(roleSearchResults/userSearchResults 빈 배열) 에도 현재 선택값을
  // options 에 시드해야 한다(백엔드가 내려준 rcpt.display_name / display_names 사용).
  describe('수신자 드롭다운 — 현재 선택값 options 시드', () => {
    /**
     * @effects core_template_role_dropdown_seeds_selected_values
     */
    it('role 드롭다운 options 가 rcpt.value + rcpt.display_names 로 시드 (다중 선택)', () => {
      const modalStr = JSON.stringify(modalPartial);
      // 검색 결과만 바인딩하던 옛 형태가 남아있지 않아야 한다
      expect(modalStr).not.toContain('"options":"{{_global.notification_template_form_modal?.roleSearchResults ?? []}}"');
      // 역할은 여러 개를 고를 수 있다 (E3) — 사용자 칸과 같은 모양이다.
      expect(modalStr).toContain('(rcpt.display_names ?? [])[i] ?? rid');
      // 스칼라 전제(단수 비교·단수 라벨)가 남아 있으면 배열이 들어온 순간 값이 조용히 덮인다.
      expect(modalStr).not.toContain('rcpt.display_name ?? rcpt.value');
      expect(modalStr).not.toContain('String(o.value) !== String(rcpt.value)');
    });

    /**
     * @effects core_template_users_dropdown_seeds_selected_values
     */
    it('specific_users 드롭다운 options 가 rcpt.value + rcpt.display_names 로 시드', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).not.toContain('"options":"{{_global.notification_template_form_modal?.userSearchResults ?? []}}"');
      expect(modalStr).toContain('(rcpt.display_names ?? [])[i] ?? uid');
    });
  });

  describe('미리보기 / 저장 흐름', () => {
    /**
     * @effects core_template_preview_stores_into_the_namespace
     */
    it('미리보기 onSuccess 가 _global.notification_template_form_modal.preview 에 저장', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.preview":');
      expect(modalStr).toContain('modal_notification_template_preview');
    });

    /**
     * @effects core_template_preview_modal_reads_the_namespace
     */
    it('preview 모달이 _global.notification_template_form_modal?.preview 참조', () => {
      const previewStr = JSON.stringify(previewPartial);
      expect(previewStr).toContain('_global.notification_template_form_modal?.preview?.subject');
      expect(previewStr).toContain('_global.notification_template_form_modal?.preview?.body');
    });

    /**
     * 미리보기가 채널의 편집기 종류를 따른다.
     *
     * 사이트내 알림은 알림센터가 본문을 **평문으로** 그린다. 미리보기만 HTML 로 해석하면
     * 운영자는 서식이 적용된 화면을 보고 사용자는 태그가 글자로 보이는 화면을 받는다 —
     * 그 차이는 둘 중 누구도 알아채지 못한 채 남는다.
     *
     * @effects core_template_preview_follows_the_channel_editor
     */
    it('미리보기 본문이 isHtml 을 채널 편집기 종류로 판정한다', () => {
      const previewStr = JSON.stringify(previewPartial);

      expect(previewStr).not.toContain('"isHtml":true');
      expect(previewStr).toContain('template_editor');
      expect(previewStr).toContain("=== 'rich'");
    });

    /**
     * @effects core_template_save_closes_refetches_and_clears
     */
    it('저장 onSuccess 에 closeModal + refetchDataSource + namespace null 정리', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"handler":"closeModal"');
      expect(modalStr).toContain('"dataSourceId":"notificationDefinitions"');
      expect(modalStr).toContain('"notification_template_form_modal":null');
    });

    /**
     * @effects core_template_save_body_reads_the_namespace
     */
    it('apiCall body 가 _global.notification_template_form_modal?.template/recipients 참조', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.subject');
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.body');
      expect(modalStr).toContain('_global.notification_template_form_modal?.recipients');
    });

    /**
     * 옛 본인인증 메시지 편집 창의 계약(#727 W13)을 이식한 것 — 7.1.0(#699)부터 본인인증 문구도 이 창으로 고친다.
     *
     * @effects core_template_save_omits_is_active
     */
    it('저장 본문에 is_active 를 싣지 않는다 — 꺼 둔 템플릿을 편집·저장해도 다시 켜지지 않는다', () => {
      const saves: any[] = [];
      const walk = (n: any): void => {
        if (Array.isArray(n)) return n.forEach(walk);
        if (n && typeof n === 'object') {
          if (n.handler === 'apiCall' && n.params?.method === 'PUT' && String(n.target ?? '').includes('/api/admin/notification-templates/')) {
            saves.push(n);
          }
          Object.values(n).forEach(walk);
        }
      };
      walk(modalPartial);
      expect(saves.length, '템플릿 저장 요청이 없습니다.').toBe(1);
      expect(Object.keys(saves[0].params.body)).not.toContain('is_active');
    });
  });
});
