/**
 * GDPR 알림 템플릿 편집 창 — 서식 편집기 슬롯이 편집 언어마다 따로 서는지
 *
 * 메일 본문은 `html_editor` 확장 슬롯(CKEditor)으로 열리고, 편집기는 마운트 때 본문과 쓰기 경로(활성 언어 포함)를
 * 한 번만 받는다. 예전에는 슬롯 하나를 언어 사이에 돌려 쓰면서 id 에 언어를 넣어 두면 다시 선다고 여겼지만, 엔진은
 * 자식 key 를 바인딩 전 원문 id 로 잡아 같은 인스턴스가 남았다 — 영어 탭을 눌러도 한국어 본문이 보이고 입력이
 * 한국어 칸에 저장됐다(실측 2026-10-03). 이제 설치 언어마다 슬롯을 두고 편집 중인 언어의 것만 세운다.
 */

import { describe, it, expect } from 'vitest';
import modal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

const NS = 'gdpr_notification_template_form_modal';

/** 조건에 맞는 첫 노드를 찾는다. */
function findNode(node: unknown, pred: (n: Json) => boolean): Json | undefined {
  if (Array.isArray(node)) {
    for (const item of node) {
      const hit = findNode(item, pred);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) return obj;
    for (const v of Object.values(obj)) {
      const hit = findNode(v, pred);
      if (hit) return hit;
    }
  }
  return undefined;
}

/** `{{ … }}` 한 덩어리 식을 값으로 평가한다. */
function evaluate(raw: string, scope: Record<string, unknown>): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 문자열 안의 `{{ … }}` 를 평가해 치환한다. */
function interpolate(raw: string, scope: Record<string, unknown>): string {
  return String(raw).replace(/\{\{([\s\S]+?)\}\}/g, (_m, expr) => String(evaluate(`{{${expr}}}`, scope) ?? ''));
}

const isSlot = (n: Json) => n.type === 'extension_point' && n.name === 'html_editor';
const slotWrapper = findNode(modal, (n) => Array.isArray(n.children) && n.children.some(isSlot))!;
const slot = (slotWrapper?.children ?? []).find(isSlot)!;
const itemVar: string = slotWrapper?.iteration?.item_var;

/** 편집 창 문맥 — 채널 선언·편집 언어·본문과 반복 변수(그 슬롯이 맡은 언어). */
function ctx(editor: string, editLang: string, slotLang: string): Record<string, unknown> {
  return {
    gdprNotificationChannels: { data: { channels: [{ id: 'mail', template_editor: editor }] } },
    _global: { [NS]: { editLang, template: { channel: 'mail', body: { ko: '<p>한국어</p>', en: '<p>English</p>' } } } },
    $locale: 'ko',
    [itemVar]: slotLang,
  };
}

describe('GDPR 알림 템플릿 편집 창 — 언어별 서식 편집기 슬롯', () => {
  /**
   * 설치 언어마다 슬롯을 하나씩 둔다 — 탭을 바꾸면 이전 언어의 슬롯이 내려가고 새 언어의 슬롯이 선다.
   *
   * @scenario case=slot_per_language
   * @effects gdpr_rich_editor_slot_per_language
   */
  it('서식 편집기 슬롯은 설치 언어($locales)를 도는 반복 안에 있다', () => {
    expect(slot, 'html_editor 슬롯').toBeTruthy();
    expect(String(slotWrapper.iteration?.source)).toContain('$locales');
    expect(itemVar).toBeTruthy();
  });

  /**
   * 편집 중인 언어의 슬롯만 서고, 서식 편집기를 쓰지 않는 채널에서는 하나도 서지 않는다.
   *
   * @scenario case=lang_switch
   * @effects gdpr_only_active_language_rich_editor_mounts
   */
  it('편집 언어가 en 이면 en 슬롯만 선다', () => {
    expect(evaluate(slot.if, ctx('rich', 'en', 'en'))).toBe(true);
    expect(evaluate(slot.if, ctx('rich', 'en', 'ko'))).toBe(false);
    expect(evaluate(slot.if, ctx('plain', 'en', 'en'))).toBe(false);
  });

  /**
   * 각 슬롯은 자기 언어의 본문을 받고 자기 언어 칸에 쓴다 — 편집 언어 값이 아니라 반복 변수를 본다.
   *
   * @scenario case=lang_switch
   * @effects gdpr_rich_editor_reads_and_writes_its_language
   */
  it('en 슬롯은 en 본문을 받고 en 칸에 쓴다', () => {
    const scope = ctx('rich', 'en', 'en');

    expect(interpolate(slot.props.content, scope)).toBe('<p>English</p>');
    expect(slot.props.writeScope).toBe('global');
    expect(interpolate(slot.props.writePath, scope)).toBe(`${NS}.template.body.en`);
  });

  /**
   * 편집기 이름(= 컨테이너 id)이 언어마다 달라, 내려가는 편집기와 서는 편집기가 같은 자리를 두고 다투지 않는다.
   *
   * @scenario case=slot_per_language
   * @effects gdpr_rich_editor_name_differs_per_language
   */
  it('편집기 이름은 언어마다 다르다', () => {
    const ko = interpolate(slot.props.name, ctx('rich', 'ko', 'ko'));
    const en = interpolate(slot.props.name, ctx('rich', 'en', 'en'));

    expect(ko).not.toBe(en);
    expect(ko).not.toContain('{{');
  });
});
