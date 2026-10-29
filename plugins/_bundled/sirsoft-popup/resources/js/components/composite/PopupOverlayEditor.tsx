import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { basic, translate } from '../../support/editor';
import { ImageFrame } from '../../lib/ImageFrame';
import {
  MAX_BOXES, MAX_OPACITY, MAX_SIZE, MAX_TEXT, MIN_SIZE, MIN_WIDTH,
  clampPct, newBox, overlayStyle, type OverlayAlign, type OverlayBox, type Overlays,
} from '../../lib/overlays';

/** 폼의 이미지 한 장 (업로드 응답 모양) */
interface FormImage {
  url?: string;
  width?: number | null;
  height?: number | null;
}

type Device = 'pc' | 'mobile';

/** 편집기 props */
export interface PopupOverlayEditorProps {
  /** 지금 폼의 글 상자 (`_local.form.overlays`) */
  value?: Overlays<OverlayBox> | null;

  /** 폼의 PC·모바일 이미지 (`_local.form.images`) */
  images?: { pc?: FormImage | null; mobile?: FormImage | null } | null;

  /** 사이트 활성 언어 — 글 언어 탭 */
  locales?: string[];

  /** 사이트 기본 언어 — 빈 언어는 이 언어 글로 보인다 */
  defaultLocale?: string;

  /** 언어 코드 → 이름 (`_global.appConfig.localeNames`) */
  localeNames?: Record<string, string> | null;

  /** 저장 중이면 고칠 수 없다 */
  disabled?: boolean;

  /** 저장 실패 검증 오류 (`_local.errors`) — `overlays.{기기}.{순번}…` 키의 상자를 표시한다 */
  errors?: Record<string, unknown> | null;

  /** 바뀐 글 상자 전체를 넘긴다(상자가 하나도 없으면 null). */
  onChange?: (value: Overlays<OverlayBox> | null) => void;
}

/** 끌기 중 상태 — 놓을 때 한 번만 폼에 돌려준다(움직일 때마다 폼 전체를 다시 그리지 않게) */
interface DragState {
  id: string;
  mode: 'move' | 'resize';
  startX: number;
  startY: number;
  origin: OverlayBox;
  width: number;
  height: number;
  boxHeight: number;
  moved: boolean;
}

const t = (key: string, params?: Record<string, string | number>) => translate('sirsoft-popup.admin.form.overlay.' + key, params);

/** 작은 휴대폰에서 이미지가 보이는 폭(px) — 글자 크기 예상치의 기준 */
export const PHONE_IMAGE_WIDTH = 320;

/** 이 크기(px)보다 작으면 휴대폰에서 읽기 어렵다고 알린다 */
export const PHONE_MIN_TEXT_PX = 12;

/** 이미지 비율이 이만큼(2%) 넘게 바뀌면 상자 위치를 다시 확인하라고 알린다 — 업로드 칸의 여백 경고와 같은 기준 */
const RATIO_TOLERANCE = 0.02;

/**
 * 요소의 아래 끝이 프레임 높이의 몇 % 에 있는지, 요소 높이가 몇 % 인지 잽니다.
 *
 * @param el 상자 요소
 * @param frame 이미지 프레임
 * @returns 높이·아래 끝 (%) — 프레임 크기를 모르면 0
 */
function measurePct(el: Element, frame: Element): { height: number; bottom: number } {
  const f = frame.getBoundingClientRect();
  const r = el.getBoundingClientRect();
  if (f.height <= 0) return { height: 0, bottom: 0 };

  return { height: (r.height / f.height) * 100, bottom: ((r.bottom - f.top) / f.height) * 100 };
}

/**
 * 이미지 위 글 상자 편집기 — 사용자 레이어와 같은 프레임(`ImageFrame`)에 상자를 같은 % 로 겹쳐 그리고,
 * 끌어 옮기기·오른쪽 손잡이로 폭 조절·화살표 이동·옆 패널 서식·추가/삭제/앞뒤 순서를 한다.
 *
 * 위치·서식은 언어 공통이고 글만 언어별이다. 모바일 이미지가 있으면 모바일 배치를 따로 둘 수 있고(처음엔 PC 배치 복사),
 * 없으면 휴대폰도 PC 배치를 쓴다. 값은 `onChange` 로 레이아웃 폼에 돌려준다 — 캔버스는 레이어와 같은 HTML 프레임이라
 * 템플릿 기본 컴포넌트를 쓰지 않고, 옆 패널 입력만 기본 컴포넌트를 쓴다.
 *
 * @param props 편집기 props
 * @returns 편집기
 */
export const PopupOverlayEditor: React.FC<PopupOverlayEditorProps> = ({ value, images, locales = [], defaultLocale = '', localeNames, disabled = false, errors, onChange }) => {
  const pcImage = images?.pc?.url ? images.pc : null;
  const mobileImage = images?.mobile?.url ? images.mobile : null;
  const languages = locales.length ? locales : [defaultLocale].filter(Boolean);
  const baseLocale = defaultLocale || languages[0] || '';
  const [deviceChoice, setDeviceChoice] = useState<Device>('pc');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [locale, setLocale] = useState(baseLocale);
  const [drag, setDrag] = useState<DragState | null>(null);
  const [draft, setDraft] = useState<OverlayBox | null>(null);
  const dragRef = useRef<DragState | null>(null);
  const draftRef = useRef<OverlayBox | null>(null);
  const canvasRef = useRef<HTMLDivElement>(null);
  // 상자 id → 이미지 아래로 넘치는 언어 (언어마다 글 길이가 달라 높이가 다르다)
  const [overflow, setOverflow] = useState<Record<string, string[]>>({});
  // 기기별로 상자를 맞춘 이미지 비율 — 이미지를 다른 비율로 바꾸면 알린다
  const ratioBase = useRef<Partial<Record<Device, number>>>({});
  const [ratioChange, setRatioChange] = useState<{ device: Device; from: number; to: number } | null>(null);

  const device: Device = deviceChoice === 'mobile' && mobileImage ? 'mobile' : 'pc';
  const pc = value?.pc ?? [];
  const mobile = value?.mobile ?? null;
  const separate = device === 'mobile' && mobile !== null;
  // 모바일 탭인데 따로 놓지 않았으면 PC 배치를 흐리게 보여 준다(고칠 수 없음)
  const editable = !disabled && (device === 'pc' || separate);
  const boxes = device === 'pc' ? pc : (mobile ?? pc);
  const image = device === 'mobile' ? mobileImage : pcImage;
  const selected = editable ? boxes.find((box) => box.id === selectedId) ?? null : null;
  // 이 배치가 휴대폰에서 보이는가 — 모바일 배치, 또는 모바일 이미지·모바일 배치가 없어 휴대폰도 PC 배치를 쓸 때
  const shownOnPhone = device === 'mobile' || !mobileImage || mobile === null;
  // 저장 실패한 상자(서버 오류 키의 순번)
  const errorIndexes = new Set(Object.keys(errors ?? {}).flatMap((key) => {
    const m = key.match(/^overlays\.(pc|mobile)\.(\d+)(\.|$)/);
    return m && m[1] === device ? [Number(m[2])] : [];
  }));
  const nameOf = (code: string) => localeNames?.[code] ?? code.toUpperCase();

  // 언어마다 보이지 않는 복제본으로 높이를 재 이미지 아래로 넘치는 상자를 찾는다(사용자 화면에서는 잘린다)
  useLayoutEffect(() => {
    const frame = canvasRef.current?.querySelector('.g7pp-media__frame');
    if (!frame) return;
    const found: Record<string, string[]> = {};
    frame.querySelectorAll<HTMLElement>('[data-measure-box]').forEach((el) => {
      if (measurePct(el, frame).bottom > 100.5) {
        const id = el.dataset.measureBox!;
        (found[id] ??= []).push(el.dataset.measureLocale!);
      }
    });
    if (JSON.stringify(found) !== JSON.stringify(overflow)) setOverflow(found);
  });

  // 캔버스 크기가 바뀌면(창 크기·글꼴 늦게 받음) 다시 잰다
  const [, remeasure] = useState(0);
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || typeof ResizeObserver === 'undefined') return undefined;
    const observer = new ResizeObserver(() => remeasure((n) => n + 1));
    observer.observe(canvas);
    (document as { fonts?: { ready?: Promise<unknown> } }).fonts?.ready?.then(() => remeasure((n) => n + 1));

    return () => observer.disconnect();
  }, []);

  const onRatio = (ratio: number) => {
    const base = ratioBase.current[device];
    // 상자가 없거나 처음 본 비율이면 기준으로 삼기만 한다
    if (base === undefined || boxes.length === 0) {
      ratioBase.current[device] = ratio;
      return;
    }
    if (Math.abs(ratio / base - 1) > RATIO_TOLERANCE) setRatioChange({ device, from: base, to: ratio });
  };
  const ratioText = (r: number) => (r >= 1 ? `${Math.round(r * 100) / 100}:1` : `1:${Math.round((1 / r) * 100) / 100}`);

  useEffect(() => {
    if (!languages.includes(locale) && baseLocale) setLocale(baseLocale);
  }, [languages.join(','), baseLocale]); // eslint-disable-line react-hooks/exhaustive-deps

  const emit = (next: Overlays<OverlayBox>) => {
    const empty = next.pc.length === 0 && (next.mobile === null || next.mobile.length === 0);
    onChange?.(empty ? null : next);
  };
  const setBoxes = (list: OverlayBox[]) => emit(device === 'pc' ? { pc: list, mobile } : { pc, mobile: list });
  const patch = (id: string, change: Partial<OverlayBox>) => setBoxes(boxes.map((box) => (box.id === id ? { ...box, ...change } : box)));

  const add = () => {
    if (!editable || boxes.length >= MAX_BOXES) return;
    // 기본 언어 글이 있어야 저장된다 — 빈 상자로 두지 않는다
    const box = { ...newBox(boxes.map((b) => b.id)), text: { [baseLocale]: t('new_text') } };
    setBoxes([...boxes, box]);
    setSelectedId(box.id);
  };
  const remove = (id: string) => {
    setBoxes(boxes.filter((box) => box.id !== id));
    setSelectedId(null);
  };
  const reorder = (id: string, step: 1 | -1) => {
    const from = boxes.findIndex((box) => box.id === id);
    const to = from + step;
    if (from < 0 || to < 0 || to >= boxes.length) return;
    const list = [...boxes];
    [list[from], list[to]] = [list[to], list[from]];
    setBoxes(list);
  };

  // 끌기 — 누른 뒤 움직임·놓음은 window 에서 받는다(빠르게 끌어 포인터가 상자 밖으로 나가도 따라온다)
  useEffect(() => {
    if (!drag) return undefined;
    const onMove = (event: PointerEvent) => {
      const state = dragRef.current;
      if (!state || state.width <= 0 || state.height <= 0) return;
      const dx = ((event.clientX - state.startX) / state.width) * 100;
      const dy = ((event.clientY - state.startY) / state.height) * 100;
      const o = state.origin;
      const next = state.mode === 'move'
        ? { ...o, x: clampPct(o.x + dx, 0, 100 - o.w), y: clampPct(o.y + dy, 0, 100 - state.boxHeight) }
        : { ...o, w: clampPct(o.w + dx, MIN_WIDTH, 100 - o.x) };
      state.moved = true;
      draftRef.current = next;
      setDraft(next);
    };
    const onUp = () => {
      const state = dragRef.current;
      const next = draftRef.current;
      dragRef.current = null;
      draftRef.current = null;
      setDrag(null);
      setDraft(null);
      if (state?.moved && next) patch(next.id, { x: next.x, y: next.y, w: next.w });
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onUp);

    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      window.removeEventListener('pointercancel', onUp);
    };
  }); // 매 렌더 최신 boxes 로 patch 하도록 다시 붙인다

  const startDrag = (box: OverlayBox, mode: DragState['mode']) => (event: React.PointerEvent<HTMLElement>) => {
    if (!editable || event.button !== 0) return;
    // 글자 선택·스크롤을 막되, 상자에 포커스는 준다(누른 뒤 화살표로 이어서 옮길 수 있게)
    event.preventDefault();
    event.stopPropagation();
    setSelectedId(box.id);
    const frame = (event.currentTarget as HTMLElement).closest('.g7pp-media__frame') as HTMLElement | null;
    const boxEl = (event.currentTarget as HTMLElement).closest('.g7pp-overlay') as HTMLElement | null;
    boxEl?.focus();
    const rect = frame?.getBoundingClientRect();
    if (!rect) return;
    const state: DragState = {
      id: box.id, mode, startX: event.clientX, startY: event.clientY, origin: box,
      width: rect.width, height: rect.height,
      boxHeight: rect.height > 0 && boxEl ? Math.min(100, (boxEl.getBoundingClientRect().height / rect.height) * 100) : 0,
      moved: false,
    };
    dragRef.current = state;
    setDrag(state);
  };

  const onBoxKey = (box: OverlayBox) => (event: React.KeyboardEvent<HTMLElement>) => {
    if (!editable || event.target !== event.currentTarget) return;
    const step = event.shiftKey ? 5 : 1;
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[event.key]) {
      event.preventDefault();
      const [dx, dy] = moves[event.key];
      // 끌기와 같이 상자 높이만큼 위에서 멈춘다 — 이미지 아래로 내려가면 사용자 화면에서 잘린다
      const frame = event.currentTarget.closest('.g7pp-media__frame');
      const height = frame ? measurePct(event.currentTarget, frame).height : 0;
      patch(box.id, { x: clampPct(box.x + dx, 0, 100 - box.w), y: clampPct(box.y + dy, 0, 100 - Math.min(100, height)) });
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
      event.preventDefault();
      remove(box.id);
    }
  };

  const Div = basic('Div', 'div');
  const P = basic('P', 'p');
  const Span = basic('Span', 'span');
  const Button = basic('Button', 'button');
  const Label = basic('Label', 'label');
  const Input = basic('Input', 'input');
  const Textarea = basic('Textarea', 'textarea');
  const tab = (active: boolean) => `px-3 py-1 text-xs rounded border ${active ? 'bg-gray-900 text-white border-gray-900 dark:bg-gray-100 dark:text-gray-900' : 'bg-white text-gray-700 border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600'}`;

  if (!pcImage) {
    return <Div className="g7pp-ov-editor" data-overlay-editor=""><P className="form-hint">{t('need_pc_image')}</P></Div>;
  }

  const textOf = (box: OverlayBox) => box.text?.[locale]?.trim() ? box.text[locale] : (box.text?.[baseLocale] ?? '');
  const isFallback = (box: OverlayBox) => locale !== baseLocale && !box.text?.[locale]?.trim();

  return <Div className="g7pp-ov-editor flex flex-col gap-3" data-overlay-editor="">
    <Div className="flex-between flex-wrap gap-2">
      {mobileImage ? <Div role="group" aria-label={t('device_label')} className="flex gap-1">
        {(['pc', 'mobile'] as Device[]).map((d) => <Button key={d} type="button" className={tab(device === d)} aria-pressed={device === d} data-overlay-device={d}
          onClick={() => { setDeviceChoice(d); setSelectedId(null); }}>{t('device.' + d)}</Button>)}
      </Div> : <Span />}
      <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="add" disabled={!editable || boxes.length >= MAX_BOXES} onClick={add}>{t('add')}</Button>
    </Div>
    {editable && boxes.length >= MAX_BOXES && <P className="form-hint" role="status">{t('limit', { max: MAX_BOXES })}</P>}
    {device === 'mobile' && <Div className="flex flex-wrap items-center gap-2">
      {separate
        ? <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="use-pc" disabled={disabled} onClick={() => { emit({ pc, mobile: null }); setSelectedId(null); }}>{t('use_pc')}</Button>
        : <>
          <P className="form-hint">{t('uses_pc')}</P>
          <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="copy-pc" disabled={disabled} onClick={() => emit({ pc, mobile: pc.map((box) => ({ ...box, text: { ...box.text }, bg: box.bg ? { ...box.bg } : null })) })}>{t('copy_pc')}</Button>
        </>}
    </Div>}
    <P className="form-hint" id="g7pp-ov-canvas-hint">{t('canvas_hint')}</P>
    {ratioChange && ratioChange.device === device && <Div role="status" data-overlay-warning="ratio" className="flex flex-wrap items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
      <Span>{t('ratio_changed', { from: ratioText(ratioChange.from), to: ratioText(ratioChange.to) })}</Span>
      <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="ratio-ack" onClick={() => { ratioBase.current[device] = ratioChange.to; setRatioChange(null); }}>{t('ratio_ack')}</Button>
    </Div>}
    {boxes.some((box) => overflow[box.id]?.length) && <Div role="status" data-overlay-warning="overflow" className="text-sm text-amber-700 dark:text-amber-300">
      {boxes.map((box, i) => (overflow[box.id]?.length ? <P key={box.id}>{t('overflow', { n: i + 1, languages: overflow[box.id].map(nameOf).join(', ') })}</P> : null))}
    </Div>}
    {/* 캔버스 — 사용자 레이어와 같은 프레임. 상자만 누르고 끌 수 있다(레이어에서는 누름을 받지 않음) */}
    <div ref={canvasRef} className="g7pp-ov-editor__canvas" role="group" aria-label={t('canvas_label')} aria-describedby="g7pp-ov-canvas-hint" data-overlay-canvas="" onPointerDown={() => setSelectedId(null)}>
      {image && <ImageFrame image={{ url: image.url!, width: image.width ?? null, height: image.height ?? null }} alt="" eager onRatio={onRatio}>
        {boxes.flatMap((box) => languages.filter((code) => box.text?.[code]?.trim()).map((code) => <div
          key={`${box.id}-${code}`}
          className="g7pp-overlay g7pp-overlay--measure"
          style={overlayStyle(draft && draft.id === box.id ? draft : box)}
          aria-hidden="true"
          data-measure-box={box.id}
          data-measure-locale={code}
        >{box.text[code]}</div>))}
        {boxes.map((stored, i) => {
          const box = draft && draft.id === stored.id ? draft : stored;
          const text = textOf(box);
          const active = selected?.id === box.id;
          return <div
            key={box.id}
            className={`g7pp-overlay${active ? ' g7pp-overlay--selected' : ''}${isFallback(box) ? ' g7pp-overlay--fallback' : ''}${overflow[box.id]?.length ? ' g7pp-overlay--overflow' : ''}${errorIndexes.has(i) ? ' g7pp-overlay--error' : ''}`}
            style={overlayStyle(box)}
            data-overlay-id={box.id}
            role="button"
            tabIndex={editable ? 0 : -1}
            aria-pressed={active}
            aria-disabled={!editable || undefined}
            aria-invalid={errorIndexes.has(i) || undefined}
            aria-label={t('box_label', { n: i + 1, text: text || t('empty_box') })}
            onPointerDown={startDrag(stored, 'move')}
            onFocus={() => editable && setSelectedId(box.id)}
            onKeyDown={onBoxKey(stored)}
          >
            {text || t('empty_box')}
            {active && <span className="g7pp-ov-editor__resize" aria-hidden="true" data-overlay-resize="" onPointerDown={startDrag(stored, 'resize')} />}
          </div>;
        })}
      </ImageFrame>}
    </div>
    {!selected
      ? <P className="form-hint">{t('no_selection')}</P>
      : <Div className="g7pp-ov-editor__panel flex flex-col gap-3" data-overlay-panel={selected.id}>
        {languages.length > 1 && <Div role="tablist" aria-label={t('locale_label')} className="flex flex-wrap gap-1">
          {languages.map((code) => <Button key={code} type="button" role="tab" aria-selected={locale === code} className={tab(locale === code)} data-overlay-locale={code} onClick={() => setLocale(code)}>
            {localeNames?.[code] ?? code.toUpperCase()}
          </Button>)}
        </Div>}
        <Div>
          <Label className="form-label" htmlFor="g7pp-ov-text">{t('text')}{isFallback(selected) ? <Span className="ml-2 text-xs text-gray-500 dark:text-gray-400" data-overlay-fallback="">{t('fallback')}</Span> : null}</Label>
          <Textarea id="g7pp-ov-text" className="input w-full" rows={3} maxLength={MAX_TEXT} value={selected.text?.[locale] ?? ''} placeholder={locale !== baseLocale ? (selected.text?.[baseLocale] ?? '') : ''}
            onChange={(event: React.ChangeEvent<HTMLTextAreaElement>) => {
              const text = { ...selected.text };
              if (event.target.value === '') delete text[locale]; else text[locale] = event.target.value;
              patch(selected.id, { text });
            }} />
          <P className="form-hint">{t('text_hint')}</P>
        </Div>
        <Div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Label className="flex items-center gap-2 text-sm">
            <Span className="w-24 shrink-0">{t('size')}</Span>
            <Input type="range" min={MIN_SIZE} max={MAX_SIZE} step={0.5} value={selected.size} aria-label={t('size')} data-overlay-field="size"
              onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { size: Number(event.target.value) })} />
            <Span className="tabular-nums text-xs">{selected.size}</Span>
          </Label>
          {shownOnPhone && (() => {
            // 작은 휴대폰에서 이미지가 보이는 폭 기준 예상 글자 크기 — 너무 작으면 알린다
            const px = Math.round(selected.size * PHONE_IMAGE_WIDTH) / 100;
            const small = px < PHONE_MIN_TEXT_PX;
            return <P className={`text-xs sm:col-span-2 ${small ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400'}`} data-overlay-phone-size={px} data-small={small || undefined}>
              {t('phone_size', { width: PHONE_IMAGE_WIDTH, px })}{small ? ` ${t('phone_small')}` : ''}
            </P>;
          })()}
          <Label className="flex items-center gap-2 text-sm">
            <Span className="w-24 shrink-0">{t('color')}</Span>
            <Input type="color" value={selected.color.toLowerCase()} aria-label={t('color')} data-overlay-field="color"
              onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { color: event.target.value.toUpperCase() })} />
            {/* 흰색처럼 바탕과 같은 색이면 색 칸만으로는 알아보기 어려워 값을 함께 보인다 */}
            <Span className="tabular-nums text-xs">{selected.color}</Span>
          </Label>
          <Label className="flex items-center gap-2 text-sm">
            <Input type="checkbox" checked={selected.bold} data-overlay-field="bold" onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { bold: event.target.checked })} />
            <Span>{t('bold')}</Span>
          </Label>
          <Label className="flex items-center gap-2 text-sm">
            <Input type="checkbox" checked={selected.shadow} data-overlay-field="shadow" onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { shadow: event.target.checked })} />
            <Span>{t('shadow')}</Span>
          </Label>
        </Div>
        <Div role="group" aria-label={t('align_label')} className="flex gap-1">
          {(['left', 'center', 'right'] as OverlayAlign[]).map((align) => <Button key={align} type="button" className={tab(selected.align === align)} aria-pressed={selected.align === align} data-overlay-align={align}
            onClick={() => patch(selected.id, { align })}>{t('align.' + align)}</Button>)}
        </Div>
        <Div className="flex flex-wrap items-center gap-3">
          <Label className="flex items-center gap-2 text-sm">
            <Input type="checkbox" checked={!!selected.bg} data-overlay-field="bg" onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { bg: event.target.checked ? { color: '#000000', opacity: 40 } : null })} />
            <Span>{t('bg')}</Span>
          </Label>
          {selected.bg && <>
            <Label className="flex items-center gap-2 text-sm">
              <Span>{t('bg_color')}</Span>
              <Input type="color" value={selected.bg.color.toLowerCase()} aria-label={t('bg_color')} data-overlay-field="bg_color"
                onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { bg: { ...selected.bg!, color: event.target.value.toUpperCase() } })} />
            </Label>
            <Label className="flex items-center gap-2 text-sm">
              <Span>{t('bg_opacity')}</Span>
              <Input type="range" min={0} max={MAX_OPACITY} step={5} value={selected.bg.opacity} aria-label={t('bg_opacity')} data-overlay-field="bg_opacity"
                onChange={(event: React.ChangeEvent<HTMLInputElement>) => patch(selected.id, { bg: { ...selected.bg!, opacity: Number(event.target.value) } })} />
              <Span className="tabular-nums text-xs">{selected.bg.opacity}%</Span>
            </Label>
          </>}
        </Div>
        <Div className="flex flex-wrap gap-2">
          <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="forward" disabled={boxes[boxes.length - 1]?.id === selected.id} onClick={() => reorder(selected.id, 1)}>{t('forward')}</Button>
          <Button type="button" className="btn btn-secondary btn-sm" data-overlay-action="backward" disabled={boxes[0]?.id === selected.id} onClick={() => reorder(selected.id, -1)}>{t('backward')}</Button>
          <Button type="button" className="btn btn-danger btn-sm" data-overlay-action="delete" onClick={() => remove(selected.id)}>{t('delete')}</Button>
        </Div>
      </Div>}
  </Div>;
};

export default PopupOverlayEditor;
