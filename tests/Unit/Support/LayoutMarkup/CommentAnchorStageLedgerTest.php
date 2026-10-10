<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\MarkupElement;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use Illuminate\Support\Facades\File;

/**
 * S21 주석 주입 단락의 **경계와 잔량을 재는 자**를 저장소에 둔다.
 *
 * ## 왜 테스트인가
 *
 * S21 은 634파일을 9단락으로 쪼개 도는 작업이고, 매 단락이 착수할 때 두 가지를 다시 물어야 한다 —
 * *"내 단락의 경계가 아직 코퍼스를 빈틈없이 덮는가"* 와 *"내 단락에 몇 자리가 남았는가"*. 이 계량이
 * 스크래치패드에만 있으면 다음 단락은 그 값을 **재현할 수 없고**, 경계가 어긋나 어느 단락에도 안
 * 드는 파일이 생겨도 보고 표면에서 조용히 사라진다({@see LayoutMarkupRoundTripCorpusTest} 가 코퍼스
 * 하한을 상수로 잠근 것과 같은 이유).
 *
 * 이것은 **단락의 종료를 선언하는 판정기가 아니다** — 종료 판정은 세션이 게이트 출력과 산출물
 * 대조로 한다. 여기서 잠그는 것은 그 판정의 **입력이 되는 모집단**이다(경계·잔량·상한).
 *
 * ## 계량이 세 번 좁거나 넓었다 — 그 교정이 {@see remainingAnchors} 에 들어 있다
 *
 * 착수기의 계량기는 세 자리에서 과대계수했고, 그래서 계획서 표가 실측보다 9% 높았다.
 *
 * 1. **이미 주석이 있는 `<data-source>`·`<modal>`** 을 남은 자리로 셌다 — 코퍼스에는 착수 전부터
 *    2,282개의 주석이 있었다.
 * 2. **인라인 모달 래퍼 판정에 봉투 종류를 걸었다** — `<layout>` 일 때만 래퍼를 접어, 확장 조각이
 *    기여하는 모달이 래퍼 + 노드 두 자리로 셌다.
 * 3. **`<partial>` 의 루트 노드**를 봉투 헤더와 별개 자리로 셌다 — 그 둘은 같은 `comment` 키를
 *    다투므로 실제로는 한 자리다({@see DocumentCompiler} 의 하나 채우기).
 */
class CommentAnchorStageLedgerTest extends LayoutMarkupTestCase
{
    /**
     * 코퍼스 하한 — 스캔이 죽으면 "빈틈 0" 이 아니라 "검사 대상 0" 이다. 그 둘을 가른다.
     */
    private const CORPUS_MINIMUM = 634;

    /**
     * 승인된 단락 상한(2026-08-01) — 한 단락에 600건을 넘겨 싣지 않는다.
     */
    private const STAGE_CEILING = 600;

    /**
     * 남은 총량의 하한 — 계량기가 죽으면 모든 단락이 "0 = 완료" 로 보인다. 그 상태를 막는다.
     * 착수 실측 2,975 에서 넉넉히 내린 값이라, 주입이 진행돼도 **미착수 단락이 있는 한** 참이다.
     *
     * S21.8 완주로 미착수 단락이 사라진 뒤에는 이 하한을 적용할 자리가 없다. 그때는 잔여 합계가
     * "0 이어야 한다" 는 더 강한 단언으로 갈리며({@see test_no_pending_stage_exceeds_the_approved_ceiling}),
     * 계량기 생존은 하한이 아니라 합성 표본 자기시험이 맡는다
     * ({@see test_enumerator_counts_each_anchor_kind_and_honours_exclusions}).
     * 이 상수는 단락이 새로 열릴 때 다시 쓰이므로 지우지 않는다.
     */
    private const REMAINING_FLOOR = 100;

    /**
     * 이미 주입을 마친 단락 — 잔여 0 이 유지되어야 한다(회귀 시 red).
     *
     * @var list<string>
     */
    private const COMPLETED_STAGES = ['S21', 'S21.1', 'S21.2', 'S21.3', 'S21.4', 'S21.5', 'S21.6', 'S21.7', 'S21.8'];

    /**
     * 단락 경계 — **경로 규칙**으로 적는다. 파일 목록을 손으로 적으면 새 레이아웃이 생겼을 때
     * 어느 단락에도 안 들어간 채 조용히 빠진다.
     *
     * 위에서부터 처음 맞는 규칙이 이긴다(첫 일치 우선). 겹침은 아래 단언이 따로 잡는다.
     *
     * @return array<string, callable(string): bool>
     */
    private function stageRules(): array
    {
        $ecommerce = 'modules/_bundled/sirsoft-ecommerce/';
        $ecommercePartials = $ecommerce.'resources/layouts/admin/partials/';
        $admin = 'templates/_bundled/sirsoft-admin_basic/';
        $adminPartials = $admin.'layouts/partials/';
        $basic = 'templates/_bundled/sirsoft-basic/';
        $board = 'modules/_bundled/sirsoft-board/';

        return [
            'S21' => fn (string $p): bool => str_starts_with($p, 'plugins/_bundled/')
                || str_starts_with($p, 'modules/_bundled/gnuboard7-hello_module/')
                || str_starts_with($p, 'modules/_bundled/sirsoft-page/')
                || str_starts_with($p, 'templates/_bundled/gnuboard7-'),

            'S21.1' => fn (string $p): bool => str_starts_with($p, $ecommerce.'resources/layouts/admin/')
                && (! str_contains($p, '/partials/')
                    || str_starts_with($p, $ecommercePartials.'admin_ecommerce_product_form/')),

            'S21.2' => fn (string $p): bool => str_starts_with($p, $ecommercePartials.'admin_ecommerce_settings/')
                || str_starts_with($p, $ecommercePartials.'admin_ecommerce_product_list/')
                || str_starts_with($p, $ecommercePartials.'admin_ecommerce_order_list/')
                || str_starts_with($p, $ecommercePartials.'admin_ecommerce_promotion_coupon_list/'),

            'S21.3' => fn (string $p): bool => str_starts_with($p, $ecommerce),

            'S21.4' => fn (string $p): bool => (str_starts_with($p, $admin.'layouts/')
                && ! str_starts_with($p, $adminPartials))
                || str_starts_with($p, $adminPartials.'admin_settings/')
                || str_starts_with($p, $adminPartials.'admin_template_list/')
                || str_starts_with($p, $adminPartials.'admin_module_list/'),

            'S21.5' => fn (string $p): bool => str_starts_with($p, $admin),

            'S21.6' => fn (string $p): bool => str_starts_with($p, $basic.'layouts/shop/')
                || str_starts_with($p, $basic.'layouts/partials/shop/')
                || str_starts_with($p, $basic.'layouts/mypage/')
                || str_starts_with($p, $basic.'layouts/partials/mypage/'),

            'S21.7' => fn (string $p): bool => str_starts_with($p, $basic),

            'S21.8' => fn (string $p): bool => str_starts_with($p, $board),
        ];
    }

    /**
     * 소스 전량을 `[상대경로 => 원문]` 으로 돌려줍니다.
     *
     * @return array<string, string>
     */
    private function corpus(): array
    {
        $out = [];

        foreach (LayoutCorpus::scan('.g7.html') as $file) {
            $out[ltrim(str_replace('\\', '/', str_replace(base_path(), '', $file)), '/')] = File::get($file);
        }

        $this->assertGreaterThanOrEqual(
            self::CORPUS_MINIMUM,
            count($out),
            '소스 스캔이 '.count($out).'건 — 모집단이 무너졌다. 아래 단언이 공허하게 참이 된다',
        );

        return $out;
    }

    /**
     * 한 파일에 남은 주석 앵커 자리 수를 셉니다.
     *
     * 앵커는 다섯 종류다 — 파일 헤더 · 명명 액션 · `<data-source>` · `<modal>` · 액션 블록.
     * 제외 기준 ⓐⓑⓒ 는 `docs/frontend/layout-markup.md` §10.5 의 판정식과 같은 정의를 쓴다.
     *
     * @param  array<int, MarkupElement>  $roots  트리 루트(봉투)
     * @return int 남은 자리 수
     */
    private function remainingAnchors(array $roots): int
    {
        $envelope = $roots[0];
        $rootNode = $envelope->children[0] ?? null;

        // ⓒ `<partial>` 은 루트 노드를 최상위로 펼치므로 봉투 주석과 그 노드 주석이 같은 `comment`
        //    키를 다툰다 — 한 자리다. 어느 쪽이든 차 있으면 닫힌 자리다.
        $flattened = $envelope->name === 'partial' && $rootNode !== null;
        $headerFilled = $envelope->comment !== null || ($flattened && $rootNode->comment !== null);

        $remaining = $headerFilled ? 0 : 1;

        foreach ($envelope->children as $child) {
            if ($child->name === 'action' && $child->comment === null) {
                $remaining++;
            }
        }

        $walk = function (array $elements, bool $topLevel, ?MarkupElement $parent) use (&$walk, &$remaining, $flattened, $rootNode): void {
            foreach ($elements as $element) {
                // 펼쳐진 루트 노드는 위 헤더 자리와 같은 슬롯이므로 다시 세지 않는다.
                $isFlattenedRoot = $flattened && $element === $rootNode;

                if ($element->name === 'data-source' && $element->comment === null && ! $isFlattenedRoot) {
                    $remaining++;
                }

                if ($element->name === 'modal' && ! $isFlattenedRoot) {
                    $inner = $element->children[0] ?? null;
                    // 인라인 모달은 래퍼와 안쪽 노드가 같은 모달 하나다 — 안쪽 한 자리로만 센다.
                    $isInlineWrapper = $topLevel && $inner !== null && $inner->name === 'modal';

                    if (! $isInlineWrapper && $element->comment === null) {
                        $remaining++;
                    }
                }

                if ($element->name === 'on') {
                    $remaining += $this->actionBlockRemaining($element, $parent);
                }

                $walk($element->children, false, $element);
            }
        };

        $walk($envelope->children, true, $envelope);

        return $remaining;
    }

    /**
     * 액션 블록 한 개가 남은 자리인지 판정합니다(제외 기준 ⓐⓑ).
     *
     * @param  MarkupElement  $block  `<on>` 요소
     * @param  MarkupElement|null  $parent  그 블록을 단 노드
     * @return int 남았으면 1, 아니면 0
     */
    private function actionBlockRemaining(MarkupElement $block, ?MarkupElement $parent): int
    {
        $siblingBlocks = $parent === null
            ? 0
            : count(array_filter($parent->children, fn (MarkupElement $c): bool => $c->name === 'on'));

        // ⓑ 부모 노드가 이미 그 자리를 설명했고 이벤트 블록이 하나뿐이면 되풀이다. 둘 이상이면
        //    한 주석이 둘을 설명할 수 없으므로 제외하지 않는다.
        if ($parent !== null && $parent->comment !== null && $siblingBlocks === 1) {
            return 0;
        }

        $target = count($block->children) === 1 ? $block->children[0] : null;

        // ⓐ 자식 없이 속성 ≤1 인 단발 액션 — 코드가 한 줄로 자명하다.
        if ($target !== null && $target->children === [] && count($target->attributes) <= 1) {
            return 0;
        }

        // 액션이 둘 이상이면 `<sequence>` 로 감싼 뒤 그 앞에 쓴다 — 아직 감싸지 않았으면 남은 자리다.
        return $target !== null && $target->comment !== null ? 0 : 1;
    }

    /**
     * 단락별 `[파일 수, 잔여 앵커]` 를 잽니다 (첫 일치 우선 배정).
     *
     * @return array<string, array{files: int, remaining: int}>
     */
    private function ledger(): array
    {
        $rules = $this->stageRules();
        $tokenizer = new MarkupTokenizer;
        $builder = new MarkupTreeBuilder;

        $ledger = array_map(fn (): array => ['files' => 0, 'remaining' => 0], $rules);

        foreach ($this->corpus() as $relative => $source) {
            foreach ($rules as $stage => $rule) {
                if (! $rule($relative)) {
                    continue;
                }

                $roots = $builder->build($tokenizer->tokenize($source, $relative), $relative);
                $ledger[$stage]['files']++;
                $ledger[$stage]['remaining'] += $this->remainingAnchors($roots);

                continue 2;
            }
        }

        return $ledger;
    }

    /**
     * 9단락 경계가 코퍼스를 빈틈없이 덮는다 — 어느 단락에도 안 드는 파일이 없다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects stage_rules_partition_the_corpus_with_no_gap
     */
    public function test_stage_rules_leave_no_layout_unassigned(): void
    {
        $rules = $this->stageRules();
        $unassigned = [];
        $assigned = 0;

        foreach (array_keys($this->corpus()) as $relative) {
            $matched = false;

            foreach ($rules as $rule) {
                if ($rule($relative)) {
                    $matched = true;

                    break;
                }
            }

            $matched ? $assigned++ : $unassigned[] = $relative;
        }

        $this->assertSame([], $unassigned,
            '어느 주입 단락에도 들지 않는 소스가 있다 — 그 파일은 보고 표면에서 사라진다:'
            .PHP_EOL.implode(PHP_EOL, $unassigned));

        $this->assertGreaterThanOrEqual(self::CORPUS_MINIMUM, $assigned,
            '배정된 파일이 '.$assigned.'건 — 경계 규칙이 좁아졌다');
    }

    /**
     * 주입을 마쳤다고 선언한 단락에는 남은 자리가 없다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects completed_injection_stages_keep_zero_remaining_anchor
     */
    public function test_completed_stages_have_no_remaining_anchor(): void
    {
        $ledger = $this->ledger();

        foreach (self::COMPLETED_STAGES as $stage) {
            $this->assertSame(0, $ledger[$stage]['remaining'],
                $stage.' 은 주입 완료 단락인데 남은 앵커가 '.$ledger[$stage]['remaining']
                .'건이다 — 새 소스가 들어왔거나 주석이 지워졌다');

            $this->assertGreaterThan(0, $ledger[$stage]['files'],
                $stage.' 의 파일 수가 0 이다 — 경계 규칙이 죽어 "잔여 0" 이 공허하게 참이 됐다');
        }
    }

    /**
     * 잔여가 0 이 된 단락은 **반드시** 완료 원장에 등재돼 있다 — 위 단언의 역방향.
     *
     * 61차 계획서 준수 검증 이 연 자리다. 60차는 «S21.2 가 원장에 없어 주석 459건이 전부
     * 지워져도 초록이었다» 를 상수 한 줄로 닫았는데, 그것은 **인스턴스**를 닫은 것이고
     * 재발 경로는 그대로였다 — S21.3 을 완료 선언하면서 상수에 추가하지 않으면 똑같이 초록이다.
     * 등재를 강제하는 검사가 저장소 어디에도 없었기 때문이다(상한 단언의 `REMAINING_FLOOR` 는
     * 계량기 사망만 잡지, 한 단락이 조용히 원장 밖에 남는 것은 잡지 못한다 — 373건이 통째로
     * 지워져도 합계는 하한 위다).
     *
     * 그래서 등재의 근거를 **계획서 선언이 아니라 계량 결과**로 삼는다. 잔여가 0 이 되는 순간
     * 이 단언이 red 로 "원장에 넣어라" 를 알리고, 넣고 나면 위 단언이 그 0 을 영구히 잠근다.
     * 계획서는 저장소 밖 문서라 테스트가 읽을 수 없고, 읽을 수 있다 해도 산문을 판정 입력으로
     * 삼는 것은 이 트랙이 반복해 데인 형태다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects finished_stages_must_be_declared_in_the_completed_ledger
     */
    public function test_stages_with_zero_remaining_are_declared_completed(): void
    {
        $ledger = $this->ledger();
        $undeclared = [];

        foreach ($this->stageRules() as $stage => $_) {
            if (in_array($stage, self::COMPLETED_STAGES, true)) {
                continue;
            }

            if ($ledger[$stage]['remaining'] === 0) {
                $undeclared[] = $stage.' — 파일 '.$ledger[$stage]['files'].'건, 잔여 0';
            }
        }

        $this->assertSame([], $undeclared,
            '잔여가 0 인데 완료 원장(COMPLETED_STAGES)에 없는 단락이 있다 — 이 상태에서는 그 단락의 '
            .'주석이 전부 지워져도 어떤 단언도 red 가 되지 않는다. 주입을 마쳤다면 상수에 등재하고, '
            .'아직이라면 계량기가 왜 0 을 내는지 먼저 볼 것:'
            .PHP_EOL.implode(PHP_EOL, $undeclared));

        $this->assertNotSame([], self::COMPLETED_STAGES,
            '완료 원장이 비었다 — 이 단언이 공허하게 참이 되는 유일한 상태다');
    }

    /**
     * 승인 상한(600)을 넘는 단락이 없다.
     *
     * 순회는 **전 단락**이다(완료 단락 포함). 완료 단락의 잔여는 0 이라 상한 판정에 영향이 없고,
     * 회귀로 다시 부풀면 여기서도 걸리는 편이 낫다 — 모집단을 좁히는 것이 이 트랙이 네 번 데인
     * 형태이므로 좁힐 이유가 없으면 좁히지 않는다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects no_pending_stage_exceeds_the_approved_batch_ceiling
     */
    public function test_no_pending_stage_exceeds_the_approved_ceiling(): void
    {
        $ledger = $this->ledger();
        $over = [];
        $total = 0;
        $pending = [];

        foreach ($this->stageRules() as $stage => $_) {
            $remaining = $ledger[$stage]['remaining'];
            $total += $remaining;

            if (! in_array($stage, self::COMPLETED_STAGES, true)) {
                $pending[] = $stage;
            }

            if ($remaining > self::STAGE_CEILING) {
                $over[] = $stage.' — 잔여 '.$remaining.'건 (상한 '.self::STAGE_CEILING.')';
            }
        }

        $this->assertSame([], $over,
            '승인 상한을 넘는 단락이 있다 — 경계를 다시 쪼개는 것은 설계 권한이므로 보고 대상이다:'
            .PHP_EOL.implode(PHP_EOL, $over));

        if ($pending === []) {
            // 미착수 단락이 없다 — 하한 대신 "정확히 0" 을 잠근다(더 강한 단언이다).
            // 계량기가 죽어 0 이 된 경우는 합성 표본 자기시험이 따로 잡는다.
            $this->assertSame(0, $total,
                '미착수 단락이 없는데 잔여가 '.$total.'건 — 완료 선언한 단락에 자리가 되살아났다');

            return;
        }

        $this->assertGreaterThanOrEqual(self::REMAINING_FLOOR, $total,
            '미착수 단락('.implode(', ', $pending).')이 있는데 잔여 합계가 '.$total.'건 — '
            .'계량기가 죽으면 모든 단락이 "완료" 로 보인다. 정말로 그 단락들이 끝났다면 '
            .'COMPLETED_STAGES 에 등재할 것');
    }

    /**
     * 계량기가 살아 있다 — 다섯 앵커 종류와 제외 기준 셋을 합성 표본으로 태운다.
     *
     * 위 세 단언은 모두 "0건" 또는 "상한 이하" 를 주장하므로, 계량기가 죽어 항상 0 을 내도 초록이다.
     * 그 상태를 막으려면 **세는 것을 보여야** 한다(표본을 판정식이 아니라 모집단 입구에).
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects anchor_enumerator_counts_each_anchor_kind_and_honours_exclusions
     */
    public function test_enumerator_counts_each_anchor_kind_and_honours_exclusions(): void
    {
        $count = function (string $markup): int {
            $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup, 'probe.g7.html'), 'probe.g7.html');

            return $this->remainingAnchors($roots);
        };

        // 다섯 앵커 종류가 각각 한 자리로 잡힌다 — 아래 넷은 헤더를 채워 두어 그 1건이 앵커 본체다.
        $this->assertSame(1, $count('<layout><div/></layout>'), '파일 헤더 앵커를 못 센다');
        $this->assertSame(1, $count('<!-- h --><layout><data-source id="a" endpoint="/a"/><div/></layout>'),
            '`<data-source>` 앵커를 못 센다');
        $this->assertSame(1, $count('<!-- h --><layout><modal data-ref="m"><div/></modal><div/></layout>'),
            '`<modal>` 앵커를 못 센다');
        $this->assertSame(1, $count('<!-- h --><layout><action id="save"><api-call target="/a" method="POST"/></action><div/></layout>'),
            '명명 액션 앵커를 못 센다');
        $this->assertSame(1, $count('<!-- h --><layout><button><on type="click"><set-state target="local" a="1" b="2"/></on></button></layout>'),
            '액션 블록 앵커를 못 센다');
        // 헤더가 비어 있으면 같은 표본이 한 자리 더 나온다 — 헤더 자리와 본체 자리가 독립임을 보인다.
        $this->assertSame(2, $count('<layout><data-source id="a" endpoint="/a"/><div/></layout>'),
            '헤더 자리와 `<data-source>` 자리가 독립으로 세지지 않는다');

        // 채워진 자리는 남은 자리가 아니다.
        $this->assertSame(0, $count('<!-- h --><layout><!-- d --><data-source id="a" endpoint="/a"/></layout>'),
            '이미 주석이 달린 `<data-source>` 를 남은 자리로 센다');

        // ⓐ 인자 없는 단발 액션 — 헤더 1건만 남는다.
        $this->assertSame(1, $count('<layout><button><on type="click"><close-modal target="m"/></on></button></layout>'),
            '제외 기준 ⓐ 가 죽었다');

        // ⓑ 부모 노드가 이미 설명했고 이벤트 블록이 하나뿐 — 헤더 1건만 남는다.
        $this->assertSame(1, $count('<layout><!-- 저장 --><button><on type="click"><set-state target="local" a="1" b="2"/></on></button></layout>'),
            '제외 기준 ⓑ 가 죽었다');

        // ⓑ 반례 — 블록이 둘이면 한 주석이 둘을 설명할 수 없으므로 제외하지 않는다.
        $this->assertSame(3, $count('<layout><!-- 입력 --><input><on type="change"><set-state target="local" a="1" b="2"/></on><on type="blur"><set-state target="local" c="1" d="2"/></on></input></layout>'),
            '제외 기준 ⓑ 가 블록 2개짜리까지 삼킨다');

        // ⓒ `<partial>` 루트 노드가 이미 주석을 가지면 봉투 앞에 자리가 없다.
        $this->assertSame(0, $count('<partial><!-- 창 --><modal data-ref="m"><div/></modal></partial>'),
            '제외 기준 ⓒ 가 죽었다 — 같은 슬롯을 두 자리로 센다');

        // 인라인 모달 래퍼는 안쪽 노드와 한 자리다(봉투 종류와 무관).
        $this->assertSame(1, $count('<!-- h --><layout><modal><modal data-ref="m"><div/></modal></modal></layout>'),
            '인라인 모달 래퍼를 별도 자리로 센다');
    }
}
