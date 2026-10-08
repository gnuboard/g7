import fs from 'node:fs';
import path from 'node:path';

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StatusBadge, StatusType } from '../StatusBadge';
import { IconName } from '../../basic/IconTypes';

describe('StatusBadge', () => {
  describe('기본 렌더링', () => {
    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('status와 기본 라벨을 렌더링해야 함', () => {
      render(<StatusBadge status="success" />);

      expect(screen.getByText('common.status_badge.success')).toBeInTheDocument();
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('커스텀 라벨을 렌더링해야 함', () => {
      render(<StatusBadge status="success" label="완료됨" />);

      expect(screen.getByText('완료됨')).toBeInTheDocument();
      expect(screen.queryByText('common.status_badge.success')).not.toBeInTheDocument();
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('기본적으로 아이콘을 표시해야 함', () => {
      const { container } = render(<StatusBadge status="success" />);

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).toBeInTheDocument();
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('showIcon=false일 때 아이콘을 숨겨야 함', () => {
      const { container } = render(<StatusBadge status="success" showIcon={false} />);

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).not.toBeInTheDocument();
    });
  });

  describe('상태별 스타일', () => {
    // 기본 라벨은 번역 키(common.status_badge.*)로 낸다 — 테스트 번역 맵에 없는 키라 키가 그대로 보인다.
    const statusTests: Array<{ status: StatusType; label: string; bgClass: string }> = [
      { status: 'success', label: 'common.status_badge.success', bgClass: 'bg-green-50' },
      { status: 'warning', label: 'common.status_badge.warning', bgClass: 'bg-yellow-50' },
      { status: 'error', label: 'common.status_badge.error', bgClass: 'bg-red-50' },
      { status: 'info', label: 'common.status_badge.info', bgClass: 'bg-blue-50' },
      { status: 'pending', label: 'common.status_badge.pending', bgClass: 'bg-gray-50' },
      { status: 'default', label: 'common.status_badge.default', bgClass: 'bg-gray-50' },
    ];

    statusTests.forEach(({ status, label, bgClass }) => {
      /**
       * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
       *
       * @scenario case=status_badge_regression
       * @effects status_badge_renders_translated_default_label_and_style
       */
      it(`status="${status}"일 때 올바른 스타일과 라벨을 적용해야 함`, () => {
        const { container } = render(<StatusBadge status={status} />);

        expect(screen.getByText(label)).toBeInTheDocument();

        const badge = container.querySelector('span');
        expect(badge).toHaveClass(bgClass);
      });
    });
  });

  describe('아이콘 커스터마이징', () => {
    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('커스텀 아이콘을 사용할 수 있어야 함', () => {
      const { container } = render(
        <StatusBadge status="success" iconName={IconName.Star} />
      );

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).toBeInTheDocument();
    });
  });

  describe('스타일 커스터마이징', () => {
    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <StatusBadge status="success" className="custom-class" />
      );

      const badge = container.querySelector('span');
      expect(badge).toHaveClass('custom-class');
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <StatusBadge status="success" style={{ fontSize: '20px' }} />
      );

      const badge = container.querySelector('span');
      expect(badge).toHaveStyle({ fontSize: '20px' });
    });
  });

  describe('접근성', () => {
    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('올바른 구조로 렌더링되어야 함', () => {
      const { container } = render(<StatusBadge status="success" label="완료" />);

      const badge = container.querySelector('span');
      expect(badge).toBeInTheDocument();

      const labelSpan = screen.getByText('완료');
      expect(labelSpan).toBeInTheDocument();
    });
  });

  describe('복합 시나리오', () => {
    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('모든 props를 함께 사용할 수 있어야 함', () => {
      const { container } = render(
        <StatusBadge
          status="warning"
          label="주의 필요"
          showIcon={true}
          iconName={IconName.ExclamationTriangle}
          className="my-badge"
          style={{ marginTop: '10px' }}
        />
      );

      expect(screen.getByText('주의 필요')).toBeInTheDocument();

      const badge = container.querySelector('span');
      expect(badge).toHaveClass('my-badge');
      expect(badge).toHaveClass('bg-yellow-50');
      expect(badge).toHaveStyle({ marginTop: '10px' });

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).toBeInTheDocument();
    });
  });

  /**
   * 매니페스트 ↔ 소스 어휘 일치 (S10 18차)
   *
   * `components.json` 의 `status` 설명에 적힌 괄호 안 어휘가 레이아웃 작성자가 읽는 **유일한**
   * 허용값 목록이다. 소스 유니언에만 값을 더하면 작성자는 그 값을 쓰면 안 되는 줄 알고, 반대로
   * 매니페스트에만 더하면 실제로 쓸 때 `statusStyles[...]` 가 undefined 가 되어 className 에
   * 리터럴 "undefined" 가 박히고 배경·글자색·테두리가 통째로 사라진다 — 레이아웃 JSON 은 TS 검사
   * 밖이라 컴파일에도 걸리지 않는다.
   *
   * S10 이 도메인 Enum 의 variant() 값 4종(danger·primary·secondary·dark)을 유니언과 3개 스타일
   * 맵에 더하면서 매니페스트만 6종으로 남겨 뒀다(18차 검출). 기대 어휘를 손으로 적지 않고 소스
   * 유니언에서 파생시켜 양쪽이 갈리는 순간 붉어지게 한다.
   */
  describe('components.json 매니페스트 동기', () => {
    const componentRoot = path.resolve(__dirname, '../../../..');
    const source = fs.readFileSync(path.join(componentRoot, 'src/components/composite/StatusBadge.tsx'), 'utf-8');
    const manifest = JSON.parse(fs.readFileSync(path.join(componentRoot, 'components.json'), 'utf-8'));

    /**
     * 소스 `export type StatusType = 'a' | 'b' | …` 에서 어휘를 뽑는다.
     *
     * 어휘 패턴은 `[a-z][a-z0-9_]*` 다 — 종전 `[a-z]+` 는 밑줄·숫자가 든 값(`in_progress`,
     * `step2`)을 **양쪽 파생에서 함께** 버렸다. 양변이 같은 축소 필터를 쓰면 그 값이
     * 매니페스트에 빠져도 두 집합에서 나란히 사라져 `toEqual` 이 초록으로 끝난다(19차 검출).
     */
    const VOCAB = /^[a-z][a-z0-9_]*$/;
    const unionValues = (
      source.match(/export type StatusType =([\s\S]*?);/)?.[1].match(/'([a-z][a-z0-9_]*)'/g) ?? []
    ).map((v) => v.replace(/'/g, '')).sort();

    const description: string =
      manifest.components.composite.find((c: any) => c.name === 'StatusBadge')?.props?.status?.description ?? '';

    /** 설명서 괄호 안 `a | b | c` 어휘 */
    const documented = (description.match(/\(([^)]*\|[^)]*)\)/)?.[1] ?? '')
      .split('|')
      .map((v) => v.trim())
      .filter((v) => VOCAB.test(v))
      .sort();

    /** 유니언 어휘를 담아야 하는 맵들 — 손으로 적지 않고 소스 선언에서 파생한다. */
    const MAPS = [...source.matchAll(/const (\w+): Record<StatusType,/g)].map((m) => m[1]);

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('파생 원본이 비어 있지 않다', () => {
      expect(unionValues.length).toBeGreaterThanOrEqual(10);
      expect(documented.length).toBeGreaterThanOrEqual(1);
      // 맵 목록도 파생이다 — 비면 아래 `it.each` 가 케이스를 0개 등록하고 초록으로 끝난다.
      expect(MAPS, 'Record<StatusType, …> 맵 선언을 읽지 못했다').toEqual(
        expect.arrayContaining(['statusStyles', 'statusIcons', 'statusLabels']),
      );
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it('매니페스트 설명의 허용값이 소스 유니언과 같다', () => {
      expect(documented).toEqual(unionValues);
    });

    /**
     * StatusBadge 렌더 계약 회귀(기본 라벨은 번역 키, 상태별 스타일·아이콘·매니페스트 동기).
     *
     * @scenario case=status_badge_regression
     * @effects status_badge_renders_translated_default_label_and_style
     */
    it.each(MAPS)(
      '%s 가 유니언 어휘를 전부 담는다',
      (map) => {
        // `Record<StatusType, …>` 가 컴파일 시점에도 같은 것을 강제하지만, 레이아웃 축은 tsc 를
        // 거치지 않고 이 컴포넌트를 소비하므로 런타임 축에도 남긴다. 정규식 대신 구간 절단을 쓴다 —
        // 이 파일은 중첩 제네릭·백슬래시가 많아 정규식이 조용히 빈 문자열을 내기 쉽다.
        const head = source.indexOf(`const ${map}: Record<StatusType,`);

        expect(head, `${map} 선언을 찾지 못했다`).toBeGreaterThan(-1);

        const bodyStart = source.indexOf('{', source.indexOf('= {', head));
        const bodyEnd = source.indexOf('};', bodyStart);

        expect(bodyEnd, `${map} 의 끝을 찾지 못했다`).toBeGreaterThan(bodyStart);

        const keys = [...source.slice(bodyStart, bodyEnd).matchAll(/^ {4}([a-z][a-z0-9_]*):/gm)]
          .map((m) => m[1])
          .sort();

        expect(keys).toEqual(unionValues);
      }
    );
  });
});
