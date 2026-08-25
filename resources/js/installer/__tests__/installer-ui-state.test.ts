/**
 * 진행 화면 상태 전이 회귀 테스트 (12차 #B25 · #B26 · #B28 · #B35).
 *
 * 접힘·비활성 같은 상태는 **두 경로**로 바뀐다 — 사람이 누르는 경로와 진행에 따라 자동으로
 * 바뀌는 경로다. 종전에는 수동 경로만 `aria-expanded` 를 갱신했고, 자동으로 접힌 그룹·카드는
 * 보조기술에 계속 「펼쳐짐」으로 남았다. 그 안의 단계는 화면에서 이미 사라진 뒤다.
 *
 * 카드 헤더는 그 반대 방향의 사각이었다 — `role="button" tabindex="0"` 인데 설치가 끝나기
 * 전에는 핸들러가 첫 줄에서 되돌아간다. Tab 순서에 남아 있으면서 눌러도 아무 일이 없고,
 * 비활성이라는 사실은 어디에도 실려 있지 않았다.
 *
 * installer.js 는 전역을 노출하지 않는 닫힌 IIFE 라 파일 전체를 평가할 수 없다. 그래서 소스의
 * `region:ui-state` 구간만 잘라 **그대로 실행**한다 — 사본이 아니라 프로덕션 소스 그 자체다.
 *
 * 커버 축: collapse_path manual·automatic / collapse_target task_group·installation_card /
 *          card_interactivity running·finished / task_progress labelled·partial·reset
 *
 * @effects installer_group_collapse_syncs_aria_on_both_paths, installer_card_collapse_syncs_aria_on_both_paths, installer_card_header_is_inert_while_running, installer_current_task_shows_intra_task_progress
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeEach, describe, expect, it } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type UiState = {
  setTaskGroupCollapsed: (group: Element | null, collapsed: boolean) => void;
  setInstallationCardCollapsed: (collapsed: boolean) => void;
  setInstallationCardInteractive: (enabled: boolean) => void;
  setCurrentTaskLabel: (label: string) => void;
  setCurrentTaskProgress: (done: unknown, total: unknown) => void;
};

/** `region:ui-state` ~ `endregion:ui-state` 사이를 잘라 평가한다. */
function loadUiState(): UiState {
  const src = readFileSync(INSTALLER_SOURCE, 'utf8');

  const openMarker = src.indexOf('region:ui-state');
  const closeMarker = src.indexOf('endregion:ui-state');

  expect(openMarker, '상태 전이 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '상태 전이 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  // 두 표식은 주석 안에 있다 — 여는 주석이 **끝난 뒤**부터, 닫는 주석이 **시작하기 전**까지.
  const bodyStart = src.indexOf('*/', openMarker) + 2;
  const bodyEnd = src.lastIndexOf('/*', closeMarker);

  const body = src.slice(bodyStart, bodyEnd);

  // eslint-disable-next-line no-new-func
  return new Function(`
    ${body}
    return {
      setTaskGroupCollapsed,
      setInstallationCardCollapsed,
      setInstallationCardInteractive,
      setCurrentTaskLabel,
      setCurrentTaskProgress,
    };
  `)() as UiState;
}

let ui: UiState;

beforeEach(() => {
  document.body.innerHTML = `
    <div class="requirement-card" id="installation-progress-card">
      <div class="requirement-card-header" id="installation-card-header"
           role="button" tabindex="-1" aria-expanded="true" aria-disabled="true"
           aria-controls="installation-card-body"></div>
      <div id="installation-card-body">
        <p id="current-task">준비 중</p>
        <div id="task-list">
          <div class="task-group" data-group-id="environment">
            <div class="task-group-header" role="button" tabindex="0" aria-expanded="true"></div>
            <div class="task-group-items" id="task-group-items-environment">
              <div id="task-composer_check" class="task-item task-pending"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;

  // 매 테스트가 소스를 새로 평가한다 — 모듈 수준 상태(현재 작업 줄)가 테스트 사이로 새면
  // 「단계가 바뀌면 부분 진행이 지워진다」를 검사할 수 없다.
  ui = loadUiState();
});

describe('작업 그룹 접힘 (#B25)', () => {
  const group = () => document.querySelector('.task-group');
  const header = () => document.querySelector('.task-group-header');

  it('자동으로 접힐 때도 트리거의 aria-expanded 가 따라온다', () => {
    ui.setTaskGroupCollapsed(group(), true);

    expect(group()!.classList.contains('collapsed')).toBe(true);
    expect(
      header()!.getAttribute('aria-expanded'),
      '그룹이 완료돼 자동으로 접혔는데 보조기술에는 「펼쳐짐」으로 남았다 — 그 안의 단계는 화면에서 이미 사라진 뒤다',
    ).toBe('false');
  });

  it('다시 펼칠 때도 aria-expanded 가 따라온다', () => {
    ui.setTaskGroupCollapsed(group(), true);
    ui.setTaskGroupCollapsed(group(), false);

    expect(group()!.classList.contains('collapsed')).toBe(false);
    expect(header()!.getAttribute('aria-expanded')).toBe('true');
  });

  it('그룹이 없으면 아무 일도 하지 않는다', () => {
    expect(() => ui.setTaskGroupCollapsed(null, true)).not.toThrow();
  });
});

describe('진행 카드 접힘 (#B26)', () => {
  const header = () => document.getElementById('installation-card-header')!;
  const body = () => document.getElementById('installation-card-body')!;

  it('완료 화면이 카드를 접을 때 aria-expanded 가 따라온다', () => {
    ui.setInstallationCardCollapsed(true);

    expect(body().style.display).toBe('none');
    expect(
      header().getAttribute('aria-expanded'),
      '완료 화면이 카드를 접었는데 헤더는 「펼쳐짐」으로 남았다',
    ).toBe('false');
  });

  it('실패·중단 화면이 카드를 펼칠 때도 aria-expanded 가 따라온다', () => {
    ui.setInstallationCardCollapsed(true);
    ui.setInstallationCardCollapsed(false);

    expect(body().style.display).toBe('block');
    expect(header().getAttribute('aria-expanded')).toBe('true');
  });
});

describe('진행 카드 헤더의 조작 가능 여부 (#B28)', () => {
  const header = () => document.getElementById('installation-card-header')!;

  it('설치 중에는 비활성이라는 사실이 키보드·보조기술에 전달된다', () => {
    ui.setInstallationCardInteractive(false);

    expect(header().getAttribute('aria-disabled')).toBe('true');
    expect(
      header().getAttribute('tabindex'),
      'Tab 순서에 남으면 눌러도 아무 일이 없는 「죽은 버튼」이 된다',
    ).toBe('-1');
  });

  it('완료·실패·중단 뒤에는 다시 누를 수 있는 상태가 된다', () => {
    ui.setInstallationCardInteractive(true);

    expect(header().getAttribute('aria-disabled')).toBe('false');
    expect(header().getAttribute('tabindex')).toBe('0');
  });
});

describe('현재 작업 줄의 부분 진행 (#B35)', () => {
  const label = () => document.getElementById('current-task')!.textContent;

  it('한 단계 안의 진행을 단계 이름 뒤에 덧붙인다', () => {
    ui.setCurrentTaskLabel('필수 패키지 설치');
    ui.setCurrentTaskProgress(7, 25);

    expect(
      label(),
      'heartbeat 의 진행이 화면에 도달하지 않는다 — 최장 단계가 정지한 스피너로 남는다',
    ).toBe('필수 패키지 설치 (7/25)');
  });

  it('단계가 바뀌면 앞 단계의 부분 진행을 버린다', () => {
    ui.setCurrentTaskLabel('필수 패키지 설치');
    ui.setCurrentTaskProgress(7, 25);
    ui.setCurrentTaskLabel('데이터베이스 마이그레이션');

    expect(label(), '앞 단계의 묶음 수가 다음 단계 이름 뒤에 그대로 남았다').toBe('데이터베이스 마이그레이션');
  });

  it('진행 값이 없으면 표시를 덮지 않는다', () => {
    ui.setCurrentTaskLabel('필수 패키지 설치');
    ui.setCurrentTaskProgress(undefined, undefined);
    ui.setCurrentTaskProgress(0, 0);

    expect(label(), '값 없는 heartbeat 가 표시를 0/0 으로 덮었다').toBe('필수 패키지 설치');
  });
});
