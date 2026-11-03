// audit:allow extension-dist-source-literal-sync 중첩 템플릿 리터럴 안 객체(text_spot 의 pc·mobile·width)를 압축기가 공백 없이 `,mobile:` 로 내보내 소스의 ", mobile: " 와 글자가 달라질 뿐이다 — --production 재빌드 두 번 동일로 실측, stale 아님
import React, { useEffect, useMemo, useState } from 'react';
import { basic, translate } from '../../support/editor';
import { youtubeIdFromUrl } from '../../support/media';

type Detail = Record<string, any>;
type Change = 'theirs' | 'mine' | 'both';
type Choice = 'mine' | 'latest';

export interface HeroConflictResolverProps {
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
  kind: 'enum' | 'bool' | 'period' | 'ml' | 'style' | 'presentation' | 'link' | 'media' | 'image';
  device?: 'pc' | 'mobile';
}

/** 비교 단위 — 함께 저장되는 칸은 한 항목으로 묶는다(기간·문구 표시·링크·미디어 종류와 그 유튜브 주소·영상). */
const GROUPS: Group[] = [
  { field: 'position', keys: ['position'], kind: 'enum' },
  { field: 'is_active', keys: ['is_active'], kind: 'bool' },
  { field: 'period', keys: ['display_period', 'starts_at_local', 'ends_at_local'], kind: 'period' },
  { field: 'eyebrow', keys: ['eyebrow'], kind: 'ml' },
  { field: 'title', keys: ['title'], kind: 'ml' },
  { field: 'description', keys: ['description'], kind: 'ml' },
  { field: 'button_text', keys: ['button_text'], kind: 'ml' },
  { field: 'image_alt', keys: ['image_alt'], kind: 'ml' },
  { field: 'eyebrow_style', keys: ['eyebrow_visible', 'eyebrow_color'], kind: 'style' },
  { field: 'title_style', keys: ['title_visible', 'title_color', 'title_align'], kind: 'style' },
  { field: 'description_style', keys: ['description_visible', 'description_color', 'description_align'], kind: 'style' },
  { field: 'presentation', keys: ['presentation'], kind: 'presentation' },
  { field: 'link', keys: ['link_url', 'link_new_window'], kind: 'link' },
  { field: 'media', keys: ['media_type', 'youtube_url', 'youtube_id', 'videos'], kind: 'media' },
  { field: 'pc_image', keys: [], kind: 'image', device: 'pc' },
  { field: 'mobile_image', keys: [], kind: 'image', device: 'mobile' },
];

/** 서버 `Banner::DEFAULT_PRESENTATION` 과 같다 — 이미지 초점·문구 배경과 문구 자리·등장 효과·바탕 밝기 */
const PRESENTATION_DEFAULT = {
  pc_x: 50, pc_y: 50, mobile_x: 50, mobile_y: 50, overlay: 'none',
  text_pc_x: 0, text_pc_y: 100, text_mobile_x: 0, text_mobile_y: 100, text_width: 100,
  animation: 'none', animation_speed: 'normal', animation_delay: 0, tone: 'dark',
};

/** 저장 의미가 같으면 같은 값이 되도록 정규화합니다(빈 언어 칸·null 표시 설정·대소문자 색). */
function normalize(group: Group, value: Detail | null | undefined): unknown {
  const v = value ?? {};
  switch (group.kind) {
    case 'ml': {
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
    case 'style': {
      // 머리글은 정렬을 제목과 함께 쓰므로 보이기·색만 비교한다
      const [visible, color, align] = group.keys;
      return { visible: v[visible] !== false, color: String(v[color] ?? '').toUpperCase(), ...(align ? { align: v[align] ?? 'left' } : {}) };
    }
    case 'presentation': {
      const p = { ...PRESENTATION_DEFAULT, ...(v.presentation ?? {}) };
      return {
        pc_x: Number(p.pc_x), pc_y: Number(p.pc_y), mobile_x: Number(p.mobile_x), mobile_y: Number(p.mobile_y), overlay: p.overlay ?? 'none',
        text_pc_x: Number(p.text_pc_x), text_pc_y: Number(p.text_pc_y), text_mobile_x: Number(p.text_mobile_x), text_mobile_y: Number(p.text_mobile_y),
        text_width: Number(p.text_width), animation: p.animation ?? 'none', animation_speed: p.animation_speed ?? 'normal',
        animation_delay: Number(p.animation_delay), tone: p.tone ?? 'dark',
      };
    }
    case 'link':
      return { url: v.link_url || null, newWindow: v.link_new_window === true };
    case 'media': {
      // 저장되는 것은 고른 종류의 값뿐이다(이미지면 유튜브 주소·영상을 보내지 않는다). 유튜브는 주소 모양이 달라도 같은 영상이면 같다
      const type = v.media_type === 'youtube' || v.media_type === 'video' ? v.media_type : 'image';
      if (type === 'youtube') {
        const url = String(v.youtube_url ?? '').trim();
        return { type, youtube: youtubeIdFromUrl(url) ?? v.youtube_id ?? (url || null) };
      }
      return type === 'video' ? { type, pc: v.videos?.pc?.id ?? null, mobile: v.videos?.mobile?.id ?? null } : { type };
    }
    case 'image':
      return v.images?.[group.device!]?.id ?? null;
    default:
      return v[group.keys[0]] ?? null;
  }
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/** 내 쪽 영상이 최신본에 없는지 — 다른 운영자가 바꾸거나 뺀 영상의 옛 ID 는 서버가 이미 지웠을 수 있다(이미지와 같은 이유로 고를 수 없다). */
function staleVideo(mine: any, latest: any): boolean {
  const kept = latest?.type === 'video' ? [latest.pc, latest.mobile] : [];
  return mine?.type === 'video' && [mine.pc, mine.mobile].some((id) => id !== null && !kept.includes(id));
}

/** 한 쪽 값을 운영자가 읽을 글자로 바꿉니다. */
function display(group: Group, value: Detail | null | undefined, locales: string[]): string {
  const n = normalize(group, value) as any;
  const t = (key: string, params?: Record<string, string | number>) => translate(`admin.form.conflict_merge.${key}`, params);
  switch (group.kind) {
    case 'ml': {
      const codes = [...new Set([...locales, ...Object.keys(n)])].filter((code) => n[code]);
      return codes.length ? codes.map((code) => `${code.toUpperCase()}: ${n[code]}`).join(' / ') : t('empty');
    }
    case 'bool':
      return n ? t('yes') : t('no');
    case 'period':
      return n.range ? `${n.start || '—'} ~ ${n.end || '—'}` : translate('admin.form.period.always');
    case 'style':
      return `${n.visible ? t('shown') : t('hidden')} · ${n.color}${n.align ? ` · ${translate(`admin.form.align.${n.align}`)}` : ''}`;
    case 'presentation':
      return `PC ${n.pc_x}%·${n.pc_y}% / ${translate('admin.preview.mobile')} ${n.mobile_x}%·${n.mobile_y}% · ${translate(`admin.form.presentation.${n.overlay}`)}`
        + ` · ${t('text_spot', { pc: `${n.text_pc_x}%·${n.text_pc_y}%`, mobile: `${n.text_mobile_x}%·${n.text_mobile_y}%`, width: n.text_width })}`
        + ` · ${translate(`admin.form.text_layout.animations.${n.animation}`)}${n.animation !== 'none' ? ` (${translate(`admin.form.text_layout.speeds.${n.animation_speed}`)}, ${n.animation_delay}s)` : ''}`
        + ` · ${translate(`admin.form.text_layout.tones.${n.tone}`)}`;
    case 'link':
      return `${n.url ?? t('empty')}${n.newWindow ? ` · ${t('new_window')}` : ''}`;
    case 'media': {
      const type = translate(`admin.form.media.${n.type}`);
      if (n.type === 'youtube') {
        return `${type} · ${String(value?.youtube_url ?? '').trim() || n.youtube || t('empty')}`;
      }
      if (n.type === 'video') {
        const file = (video?: Detail | null) => (video ? video.original_filename ?? `#${video.id}` : t('empty'));
        return `${type} · PC ${file(value?.videos?.pc)} / ${translate('admin.preview.mobile')} ${file(value?.videos?.mobile)}`;
      }
      return type;
    }
    case 'image': {
      const img = value?.images?.[group.device!];
      return img ? `${img.original_filename ?? `#${img.id}`}${img.width ? ` (${img.width}×${img.height})` : ''}` : t('empty');
    }
    default:
      return n === null ? t('empty') : translate(`admin.position.${n}`);
  }
}

/**
 * 동시 수정 충돌 뒤 비교·재적용 — 기준·내 입력·최신본을 항목별로 대조한다. 다른 운영자만 바꾼 항목은 최신 값,
 * 나만 바꾼 항목은 내 값을 기본으로 고르고, 둘 다 바꾼 항목은 직접 고르기 전에는 적용할 수 없다. 이미 바뀐·지워진
 * 이미지의 옛 ID 는 고를 수 없다(서버가 거절한다). 적용 결과는 최신본 위에 고른 내 값을 얹은 폼이라 revision 이 최신이다 —
 * revision 만 바꿔 내 폼 전체를 밀어 넣지 않는다.
 */
export const HeroConflictResolver: React.FC<HeroConflictResolverProps> = ({ base, mine, latest, locales = [], onApply }) => {
  const rows = useMemo(() => GROUPS.flatMap((group) => {
    const b = normalize(group, base);
    const m = normalize(group, mine);
    const l = normalize(group, latest);
    if (same(m, l)) return [];
    const mineChanged = !same(m, b);
    const theirsChanged = !same(l, b);
    const change: Change = mineChanged && theirsChanged ? 'both' : mineChanged ? 'mine' : 'theirs';
    return [{ group, change, staleMine: change === 'theirs' && (group.kind === 'image' || (group.kind === 'media' && staleVideo(m, l))) }];
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
  const t = (key: string) => translate(`admin.form.conflict_merge.${key}`);
  return <Div className="hs-conflict" role="group" aria-label={t('title')}>
    <P className="hs-conflict__title">{t('title')}</P>
    <P className="hs-form-hint">{rows.length ? t('hint') : t('no_differences')}</P>
    {rows.map(({ group, change, staleMine }) => {
      const label = translate(`admin.form.conflict_merge.fields.${group.field}`);
      const name = `hs-conflict-${group.field}`;
      return <Div key={group.field} className={`hs-conflict__row hs-conflict__row--${change}`} data-conflict-field={group.field} data-change={change} role="radiogroup" aria-label={label}>
        <Div className="hs-conflict__head"><Span>{label}</Span><Span className="hs-conflict__badge">{t(`change.${change}`)}</Span></Div>
        {(['mine', 'latest'] as Choice[]).map((side) => <Label key={side} className="hs-conflict__option">
          <Input
            type="radio" name={name} value={side} checked={choices[group.field] === side}
            disabled={side === 'mine' && staleMine}
            onChange={() => setPicked((prev) => ({ ...prev, [group.field]: side }))}
          />
          <Span className="hs-conflict__side">{t(side)}</Span>
          <Span className="hs-conflict__value">{display(group, side === 'mine' ? mine : latest, locales)}</Span>
          {side === 'mine' && staleMine ? <Span className="hs-form-hint">{t(group.kind === 'media' ? 'stale_video' : 'stale_image')}</Span> : null}
        </Label>)}
      </Div>;
    })}
    {!ready && <P className="hs-form-error" role="status">{t('choose_all')}</P>}
    <Button type="button" className="hs-btn hs-btn-primary hs-conflict__apply" disabled={!ready || !latest} onClick={apply}>{t('apply')}</Button>
  </Div>;
};

export default HeroConflictResolver;
