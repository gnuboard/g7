import React from 'react';
import { basic, translate } from '../../support/editor';
import { textSpot, percent, type HeroDevice, type HeroTextPresentation } from '../../support/text';

export interface HeroTextPositionPadProps {
  /** 저장 전 표현값(`_local.form.presentation`) */
  presentation?: HeroTextPresentation | null;
  /** 위치를 정할 기기 — 편집 미리보기의 기기와 같게 둔다 */
  device?: HeroDevice;
  /** 잠금(저장 중) */
  disabled?: boolean;
  /** 고른 자리를 표현값 조각으로 알린다(`{ text_pc_x, text_pc_y }` 등) */
  onChange?: (patch: Record<string, number>) => void;
  /** 기기를 바꿨다 */
  onDeviceChange?: (device: HeroDevice) => void;
}

/** 9칸 빠른 선택의 자리 — 세로(위·가운데·아래) × 가로(왼쪽·가운데·오른쪽) */
const SPOTS: ReadonlyArray<{ key: string; x: number; y: number }> = [
  { key: 'top_left', x: 0, y: 0 }, { key: 'top_center', x: 50, y: 0 }, { key: 'top_right', x: 100, y: 0 },
  { key: 'middle_left', x: 0, y: 50 }, { key: 'middle_center', x: 50, y: 50 }, { key: 'middle_right', x: 100, y: 50 },
  { key: 'bottom_left', x: 0, y: 100 }, { key: 'bottom_center', x: 50, y: 100 }, { key: 'bottom_right', x: 100, y: 100 },
];

/**
 * 배너 문구 자리 고르기 — 기기(PC·휴대폰)마다 따로 정한다. 9칸으로 빠르게 고르거나 가로·세로 %를 직접 넣는다(0 왼쪽·위, 100 오른쪽·아래).
 * 미리보기에서 문구를 끌어 옮긴 값도 여기에 그대로 보인다. 9칸은 버튼 묶음(radiogroup 이 아닌 aria-pressed 단추)이라 Tab 으로 하나씩 간다.
 */
export const HeroTextPositionPad: React.FC<HeroTextPositionPadProps> = ({ presentation, device = 'pc', disabled = false, onChange, onDeviceChange }) => {
  const Div = basic('Div', 'div');
  const Button = basic('Button', 'button');
  const Label = basic('Label', 'label');
  const Span = basic('Span', 'span');
  const spot = textSpot(presentation, device);
  const set = (x: number, y: number) => onChange?.({ [`text_${device}_x`]: x, [`text_${device}_y`]: y });
  // 숫자 칸은 기본 요소 — 기본 Input 부품의 값 계약(이름 바인딩)과 달리 여기서는 지금 기기 키로 직접 알린다
  const NativeInput = 'input';
  const axis = (name: 'x' | 'y') => (
    <Label className="hs-pad__axis">
      <Span>{translate(`admin.form.text_layout.${name}`)}</Span>
      <NativeInput
        type="number"
        min={0}
        max={100}
        step={1}
        className="input w-24"
        value={spot[name]}
        disabled={disabled}
        data-axis={name}
        onChange={(event: React.ChangeEvent<HTMLInputElement>) => {
          const value = percent(event.target.value, spot[name]);
          set(name === 'x' ? value : spot.x, name === 'y' ? value : spot.y);
        }}
      />
      <Span aria-hidden="true">%</Span>
    </Label>
  );
  return <Div className="hs-pad" data-device={device}>
    <Div role="group" aria-label={translate('admin.preview.device_label')} className="hs-pad__devices">
      {(['pc', 'mobile'] as const).map((value) => (
        <Button key={value} type="button" aria-pressed={device === value} className={`hs-pad__device${device === value ? ' hs-pad__device--on' : ''}`} onClick={() => onDeviceChange?.(value)}>
          {translate(`admin.form.text_layout.device_${value}`)}
        </Button>
      ))}
    </Div>
    <Div className="hs-pad__body">
      <Div role="group" aria-label={translate('admin.form.text_layout.grid_label')} className="hs-pad__grid">
        {SPOTS.map((item) => {
          const on = spot.x === item.x && spot.y === item.y;
          return <Button
            key={item.key}
            type="button"
            className={`hs-pad__cell${on ? ' hs-pad__cell--on' : ''}`}
            aria-pressed={on}
            aria-label={translate(`admin.form.text_layout.spot.${item.key}`)}
            title={translate(`admin.form.text_layout.spot.${item.key}`)}
            disabled={disabled}
            data-spot={item.key}
            onClick={() => set(item.x, item.y)}
          ><Span aria-hidden="true" className="hs-pad__dot" /></Button>;
        })}
      </Div>
      <Div className="hs-pad__axes">{axis('x')}{axis('y')}</Div>
    </Div>
  </Div>;
};

export default HeroTextPositionPad;
