/**
 * Step 3 제출 게이트가 무CLI 서버에서 막다른 길을 만들지 않는다 (19차 S-1).
 *
 * `detect-php` 는 「php 를 못 찾았다」와 「probe 자체를 돌릴 수 없다」를 한 값
 * (`default_php_available=false`)으로 접는다. `exec` 이 막힌 서버 — 이 안이 지원하려는 바로
 * 그 서버 — 에서는 후자가 참인데, 종전 게이트는 그 구분 없이 `phpVerified` 를 요구했다.
 * [버전 확인] 도 같은 `validatePhpPath()` 를 거쳐 언제나 실패하므로 **통과할 방법이 없었다**:
 * Step 3 하단 카드가 「(필수)」로 잠기고 [다음] 은 경고창만 띄운다.
 *
 * 이 검사는 소스 리터럴을 보지 않는다 — 게이트 구간을 **그대로 잘라 실행**해서 「어떤 오류가
 * 쌓이는가」를 본다. 리터럴 존재만 보는 형태는 분기 하나가 빠져나가는 모습을 잡지 못한다는
 * 것을 19차 R1 이 실측으로 보여 주었다.
 *
 * 커버 축: execution_mode inprocess·subprocess / php_verified true·false / composer_required true·false
 *
 * @effects step3_gate_exempts_php_cli_when_inprocess, step3_gate_still_requires_php_cli_when_subprocess
 */
import { readFileSync } from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type GateError = { field: unknown; message: string };

type GateOptions = {
  effectiveMode: 'inprocess' | 'subprocess';
  phpVerified: boolean;
  composerVerified: boolean;
  vendorMode: string;
};

/**
 * `region:step3-cli-gate` ~ `endregion:step3-cli-gate` 구간을 잘라 그대로 평가한다.
 *
 * 프로덕션 소스 자체를 돌리므로, 게이트를 고치면 이 검사가 함께 움직인다. 사본을 두면
 * 사본만 옳은 상태가 생긴다.
 */
function runCliGate(options: GateOptions): GateError[] {
  const src = readFileSync(INSTALLER_SOURCE, 'utf8');

  const openMarker = src.indexOf('region:step3-cli-gate');
  const closeMarker = src.indexOf('endregion:step3-cli-gate');

  expect(openMarker, '게이트 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '게이트 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  // 두 표식은 주석 안에 있다 — 여는 주석이 **끝난 뒤**부터, 닫는 주석이 **시작하기 전**까지.
  const blockStart = src.indexOf('*/', openMarker) + 2;
  const blockEnd = src.lastIndexOf('/*', closeMarker);
  const block = src.slice(blockStart, blockEnd);

  // 프로덕션 구간이 참조하는 것만 최소로 세운다.
  const cliValidator = {
    cliRequired: true,
    phpVerified: options.phpVerified,
    composerVerified: options.composerVerified,
    getSelectedVendorMode: () => options.vendorMode,
    isComposerRequired(): boolean {
      if (options.effectiveMode === 'inprocess') return false;

      return options.vendorMode !== 'bundled';
    },
    isPhpCliRequired(): boolean {
      return options.effectiveMode !== 'inprocess';
    },
  };

  const fakeWindow = { CliValidator: cliValidator };
  const fakeDocument = {
    getElementById: (id: string) =>
      id === 'php-cli-section' ? { classList: { contains: () => false } } : { id },
  };
  const errors: GateError[] = [];

  // eslint-disable-next-line no-new-func
  const runner = new Function(
    'window',
    'document',
    'errors',
    'lang',
    `${block}\nreturn errors;`,
  );

  return runner(fakeWindow, fakeDocument, errors, (key: string) => key) as GateError[];
}

describe('Step 3 CLI 검증 게이트', () => {
  // @scenario execution_mode_choice=inprocess
  // @effects step3_gate_exempts_php_cli_when_inprocess
  it('inprocess 에서는 PHP CLI 검증을 요구하지 않는다 — 요구하면 통과할 방법이 없다', () => {
    const errors = runCliGate({
      effectiveMode: 'inprocess',
      phpVerified: false,
      composerVerified: false,
      vendorMode: 'bundled',
    });

    expect(
      errors.map((e) => e.message),
      'exec 이 막힌 서버에서 PHP 경로 검증을 요구하면 Step 3 이 막다른 길이 된다',
    ).not.toContain('error_php_cli_not_verified');
  });

  // @scenario execution_mode_choice=inprocess
  // @effects step3_gate_exempts_php_cli_when_inprocess
  it('inprocess 에서는 composer 검증도 함께 면제된다 (형제 축과 같은 규칙)', () => {
    const errors = runCliGate({
      effectiveMode: 'inprocess',
      phpVerified: false,
      composerVerified: false,
      vendorMode: 'composer',
    });

    expect(errors, '두 축이 같은 실행 방식에서 서로 다른 결론을 내면 안 된다').toHaveLength(0);
  });

  // @scenario execution_mode_choice=subprocess
  // @effects step3_gate_still_requires_php_cli_when_subprocess
  it('subprocess 에서는 PHP CLI 검증을 그대로 요구한다 — 면제가 넓어지면 안 된다', () => {
    const errors = runCliGate({
      effectiveMode: 'subprocess',
      phpVerified: false,
      composerVerified: true,
      vendorMode: 'bundled',
    });

    expect(
      errors.map((e) => e.message),
      '하위 프로세스를 띄우는 실행 방식에서는 그 바이너리가 실제로 도는지 확인해야 한다',
    ).toContain('error_php_cli_not_verified');
  });

  // @scenario execution_mode_choice=subprocess
  // @effects step3_gate_still_requires_php_cli_when_subprocess
  it('subprocess + 검증 완료면 오류가 없다', () => {
    const errors = runCliGate({
      effectiveMode: 'subprocess',
      phpVerified: true,
      composerVerified: true,
      vendorMode: 'composer',
    });

    expect(errors).toHaveLength(0);
  });
});
