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
    it('표준 Modal 컴포넌트 구조', () => {
      expect(modalPartial.type).toBe('composite');
      expect(modalPartial.name).toBe('Modal');
      expect(modalPartial.id).toBe('modal_notification_template_form');
    });

    it('lifecycle.onMount 미사용', () => {
      expect(modalPartial.lifecycle).toBeUndefined();
    });

    it('dataKey 미사용', () => {
      expect(JSON.stringify(modalPartial)).not.toContain('"dataKey"');
    });
  });

  describe('list 측 setState — _global namespace 통째 set', () => {
    it('edit click setState 가 _global.notification_template_form_modal 객체 통째 set', () => {
      const tabStr = JSON.stringify(tabPartial);
      expect(tabStr).toContain('"target":"global"');
      expect(tabStr).toContain('"notification_template_form_modal":{');
    });

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
    it('change/onChange/onSearch 액션은 모두 target:"global"', () => {
      const targets = collectChangeTargets(modalPartial);
      expect(targets.length).toBeGreaterThan(0);
      expect(targets.every(t => t === 'global')).toBe(true);
    });

    it('모달 본문에 target:"$parent._local" / "local" 사용 없음', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).not.toContain('"target":"$parent._local"');
      expect(modalStr).not.toContain('"target":"local"');
    });
  });

  describe('옵션 K — _global namespace 단일 진실', () => {
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

    it('subject/body 다국어 객체 — Object.assign 으로 통째 set', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.template.subject":');
      expect(modalStr).toContain('"notification_template_form_modal.template.body":');
      expect(modalStr).toContain('Object.assign({}, _global.notification_template_form_modal?.template?.subject');
      expect(modalStr).toContain('Object.assign({}, _global.notification_template_form_modal?.template?.body');
    });

    it('recipients 배열 — .map/.filter 로 통째 교체', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.recipients":');
      expect(modalStr).toContain('(_global.notification_template_form_modal?.recipients ?? [])');
    });

    it('editLang 탭 클릭 — dot path 로 변경', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.editLang":');
    });
  });

  describe('미리보기 / 저장 흐름', () => {
    it('미리보기 onSuccess 가 _global.notification_template_form_modal.preview 에 저장', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"notification_template_form_modal.preview":');
      expect(modalStr).toContain('modal_notification_template_preview');
    });

    it('preview 모달이 _global.notification_template_form_modal?.preview 참조', () => {
      const previewStr = JSON.stringify(previewPartial);
      expect(previewStr).toContain('_global.notification_template_form_modal?.preview?.subject');
      expect(previewStr).toContain('_global.notification_template_form_modal?.preview?.body');
    });

    it('저장 onSuccess 에 closeModal + refetchDataSource + namespace null 정리', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"handler":"closeModal"');
      expect(modalStr).toContain('"dataSourceId":"notificationDefinitions"');
      expect(modalStr).toContain('"notification_template_form_modal":null');
    });

    it('apiCall body 가 _global.notification_template_form_modal?.template/recipients 참조', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.subject');
      expect(modalStr).toContain('_global.notification_template_form_modal?.template?.body');
      expect(modalStr).toContain('_global.notification_template_form_modal?.recipients');
    });
  });
});
