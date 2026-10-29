import React, { useEffect, useMemo, useState } from 'react';
import { basic, translate } from '../../support/editor';

type Detail = Record<string, any>;
type Change = 'theirs' | 'mine' | 'both';
type Choice = 'mine' | 'latest';

export interface PopupConflictResolverProps {
  /** 편집을 시작한 기준 — 처음 불러온 상세(재적용 뒤에는 그때의 최신본) */
  base?: Detail | null;
  /** 지금 폼의 입력값 */
  mine?: Detail | null;
  /** 충돌 뒤 다시 받은 최신 상세(revision 포함) */
  latest?: Detail | null;
  /** 사이트 활성 언어 — 다국어 값 표시 순서 */
  locales?: string[];
  /** 고른 값으로 만든 폼(최신 revision)과, 다음 비교의 기준이 될 최신본을 넘긴다. */
  onApply?: (merged: Detail, nextBase: Detail) => void;
}

interface Group {
  field: string;
  keys: string[];
  kind: 'text' | 'enum' | 'bool' | 'period' | 'pages' | 'ml' | 'html' | 'link' | 'image' | 'overlays';
  device?: 'pc' | 'mobile';
}

/** 비교 단위 — 함께 저장되는 칸은 한 항목으로 묶는다(기간·노출 페이지·링크). */
const GROUPS: Group[] = [
  { field: 'title', keys: ['title'], kind: 'text' },
  { field: 'is_active', keys: ['is_active'], kind: 'bool' },
  { field: 'content_type', keys: ['content_type'], kind: 'enum' },
  { field: 'period', keys: ['display_period', 'starts_at_local', 'ends_at_local'], kind: 'period' },
  { field: 'pages', keys: ['page_scope', 'routes'], kind: 'pages' },
  { field: 'audience', keys: ['audience'], kind: 'enum' },
  { field: 'device', keys: ['device'], kind: 'enum' },
  { field: 'image_alt', keys: ['image_alt'], kind: 'ml' },
  { field: 'body_html', keys: ['body_html'], kind: 'html' },
  { field: 'link', keys: ['link_url', 'link_new_window'], kind: 'link' },
  // 글 상자는 위치·서식·글이 서로 맞물려 상자 하나만 골라 섞으면 겹치거나 비어 보인다 — 기기별 배치 전체를 한 항목으로
  { field: 'overlays', keys: ['overlays'], kind: 'overlays' },
  { field: 'pc_image', keys: [], kind: 'image', device: 'pc' },
  { field: 'mobile_image', keys: [], kind: 'image', device: 'mobile' },
];

/** 저장 의미가 같으면 같은 값이 되도록 정규화합니다(빈 언어 칸·경로 끝 슬래시·빈 링크). */
function normalize(group: Group, value: Detail | null | undefined): unknown {
  const v = value ?? {};
  switch (group.kind) {
    case 'ml':
    case 'html': {
      const raw = v[group.keys[0]];
      const entries = raw && typeof raw === 'object' && !Array.isArray(raw)
        ? Object.entries(raw).filter(([, text]) => typeof text === 'string' && text.trim() !== '').map(([k, text]) => [k, (text as string).trim()])
        : [];
      return Object.fromEntries(entries.sort(([a], [b]) => String(a).localeCompare(String(b))));
    }
    case 'bool':
      return v[group.keys[0]] !== false;
    case 'period':
      return v.display_period === 'range'
        ? { range: true, start: String(v.starts_at_local ?? '').slice(0, 16), end: String(v.ends_at_local ?? '').slice(0, 16) }
        : { range: false };
    case 'pages':
      return v.page_scope === 'routes'
        ? { scope: 'routes', routes: (Array.isArray(v.routes) ? v.routes : []).map((r: string) => String(r).trim().replace(/(.)\/+$/, '$1')) }
        : { scope: 'all' };
    case 'text':
      return String(v[group.keys[0]] ?? '').trim();
    case 'link':
      return { url: v.link_url || null, newWindow: v.link_new_window === true };
    case 'image':
      return v.images?.[group.device!]?.id ?? null;
    case 'overlays': {
      // 빈 배치는 없음과 같다. 언어 칸의 빈 값·키 순서는 저장 의미가 같다
      const o = v.overlays;
      const boxes = (list: any[] | null | undefined) => (Array.isArray(list) ? list : []).map((box) => ({
        ...box,
        text: Object.fromEntries(Object.entries(box?.text ?? {}).filter(([, text]) => typeof text === 'string' && text.trim() !== '').sort(([a], [b]) => a.localeCompare(b))),
      }));
      const pc = boxes(o?.pc);
      const mobile = Array.isArray(o?.mobile) ? boxes(o.mobile) : null;
      return pc.length === 0 && (mobile === null || mobile.length === 0) ? null : { pc, mobile };
    }
    default:
      return v[group.keys[0]] ?? null;
  }
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/** 한 쪽 값을 운영자가 읽을 글자로 바꿉니다. */
function display(group: Group, value: Detail | null | undefined, locales: string[]): string {
  const n = normalize(group, value) as any;
  const t = (key: string, params?: Record<string, string | number>) => translate('sirsoft-popup.admin.form.conflict_merge.' + key, params);
  switch (group.kind) {
    case 'ml': {
      const codes = [...new Set([...locales, ...Object.keys(n)])].filter((code) => n[code]);
      return codes.length ? codes.map((code) => `${code.toUpperCase()}: ${n[code]}`).join(' / ') : t('empty');
    }
    case 'html': {
      // 본문은 태그를 빼고 앞부분만 — 무엇이 다른지 알아볼 만큼
      const codes = [...new Set([...locales, ...Object.keys(n)])].filter((code) => n[code]);
      const plain = (html: string) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60);
      return codes.length ? codes.map((code) => `${code.toUpperCase()}: ${plain(n[code])}`).join(' / ') : t('empty');
    }
    case 'text':
      return n || t('empty');
    case 'pages':
      return n.scope === 'routes' ? n.routes.join(', ') : translate('sirsoft-popup.admin.page_scope.all');
    case 'bool':
      return n ? t('yes') : t('no');
    case 'period':
      return n.range ? `${n.start || '—'} ~ ${n.end || '—'}` : translate('sirsoft-popup.admin.period.always');
    case 'link':
      return `${n.url ?? t('empty')}${n.newWindow ? ` · ${t('new_window')}` : ''}`;
    case 'image': {
      const img = value?.images?.[group.device!];
      return img ? `${img.original_filename ?? `#${img.id}`}${img.width ? ` (${img.width}×${img.height})` : ''}` : t('empty');
    }
    case 'overlays': {
      if (n === null) return t('empty');
      // 상자 수와 첫 상자 글 몇 자 — 어느 쪽 배치인지 알아볼 만큼
      const first = n.pc[0]?.text ? String(Object.values(n.pc[0].text)[0] ?? '').replace(/\s+/g, ' ').slice(0, 30) : '';
      const summary = t('overlays_summary', { pc: n.pc.length, mobile: n.mobile === null ? t('overlays_mobile_pc') : n.mobile.length });
      return first ? `${summary} · ${first}` : summary;
    }
    default:
      return n === null ? t('empty') : translate(`sirsoft-popup.admin.${group.field}.${n}`);
  }
}

/**
 * 동시 수정 충돌 뒤 비교·재적용 — 기준·내 입력·최신본을 항목별로 대조한다. 다른 운영자만 바꾼 항목은 최신 값,
 * 나만 바꾼 항목은 내 값을 기본으로 고르고, 둘 다 바꾼 항목은 직접 고르기 전에는 적용할 수 없다. 이미 바뀐·지워진
 * 이미지의 옛 ID 는 고를 수 없다(서버가 거절한다). 적용 결과는 최신본 위에 고른 내 값을 얹은 폼이라 revision 이 최신이다 —
 * revision 만 바꿔 내 폼 전체를 밀어 넣지 않는다.
 */
export const PopupConflictResolver: React.FC<PopupConflictResolverProps> = ({ base, mine, latest, locales = [], onApply }) => {
  const rows = useMemo(() => GROUPS.flatMap((group) => {
    const b = normalize(group, base);
    const m = normalize(group, mine);
    const l = normalize(group, latest);
    if (same(m, l)) return [];
    const mineChanged = !same(m, b);
    const theirsChanged = !same(l, b);
    const change: Change = mineChanged && theirsChanged ? 'both' : mineChanged ? 'mine' : 'theirs';
    return [{ group, change, staleMine: group.kind === 'image' && change === 'theirs' }];
  }), [base, mine, latest]);
  const defaults = useMemo(() => Object.fromEntries(rows.filter((r) => r.change !== 'both').map((r) => [r.group.field, r.change === 'mine' ? 'mine' : 'latest'])) as Record<string, Choice>, [rows]);
  const [picked, setPicked] = useState<Record<string, Choice>>({});
  // 다시 충돌해 새 최신본이 오면 앞서 고른 값은 다른 비교에 대한 선택이라 버린다.
  useEffect(() => { setPicked({}); }, [latest?.revision, base?.revision]);
  const choices = { ...defaults, ...picked };
  const ready = rows.every((r) => !!choices[r.group.field]);

  const apply = () => {
    if (!latest || !ready) return;
    const merged: Detail = JSON.parse(JSON.stringify(latest));
    for (const row of rows) {
      if (choices[row.group.field] !== 'mine') continue;
      if (row.group.kind === 'image') {
        merged.images = { ...(merged.images ?? {}), [row.group.device!]: mine?.images?.[row.group.device!] ?? null };
      } else {
        for (const key of row.group.keys) merged[key] = mine?.[key] ?? null;
      }
    }
    onApply?.(merged, latest);
  };

  const Div = basic('Div', 'div');
  const P = basic('P', 'p');
  const Span = basic('Span', 'span');
  const Button = basic('Button', 'button');
  const Label = basic('Label', 'label');
  const Input = basic('Input', 'input');
  const t = (key: string) => translate('sirsoft-popup.admin.form.conflict_merge.' + key);
  return <Div className="g7pp-conflict" role="group" aria-label={t('title')}>
    <P className="g7pp-conflict__title">{t('title')}</P>
    <P className="form-hint">{rows.length ? t('hint') : t('no_differences')}</P>
    {rows.map(({ group, change, staleMine }) => {
      const label = translate('sirsoft-popup.admin.form.conflict_merge.fields.' + group.field);
      const name = `g7pp-conflict-${group.field}`;
      return <Div key={group.field} className={`g7pp-conflict__row g7pp-conflict__row--${change}`} data-conflict-field={group.field} data-change={change} role="radiogroup" aria-label={label}>
        <Div className="g7pp-conflict__head"><Span className="g7pp-conflict__label">{label}</Span><Span className="g7pp-conflict__badge">{t(`change.${change}`)}</Span></Div>
        {(['mine', 'latest'] as Choice[]).map((side) => <Label key={side} className="g7pp-conflict__option">
          <Input
            type="radio" name={name} value={side} checked={choices[group.field] === side}
            disabled={side === 'mine' && staleMine}
            onChange={() => setPicked((prev) => ({ ...prev, [group.field]: side }))}
          />
          <Span className="g7pp-conflict__side">{t(side)}</Span>
          <Span className="g7pp-conflict__value">{display(group, side === 'mine' ? mine : latest, locales)}</Span>
          {side === 'mine' && staleMine ? <Span className="form-hint g7pp-conflict__stale">{t('stale_image')}</Span> : null}
        </Label>)}
      </Div>;
    })}
    {!ready && <P className="form-error" role="status">{t('choose_all')}</P>}
    <Button type="button" className="btn btn-primary g7pp-conflict__apply" disabled={!ready || !latest} onClick={apply}>{t('apply')}</Button>
  </Div>;
};

export default PopupConflictResolver;
