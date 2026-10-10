<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftPayNhnkcp\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 걷어낸 조각(`admin_order_list_test_badge`)의 남은 파일과 등록 행을 지운다.
 *
 * 배경:
 *
 * 1.0.x 의 조각 파일 「resources/extensions/admin_order_list_test_badge.json」(1.1.0 에서 삭제)은 관리자 주문 목록 `order_datagrid` 의 `columns`
 * 를 통째로 주입해 테스트 결제 배지를 붙였다. 1.1.0 은 결제수단 칸의 호스트 앵커(`payment_method_label`)에 붙는
 * `admin_order_list_payment_method_badge` 로 옮기고 옛 파일을 지웠다.
 *
 * 그런데 코어 7.0.x 의 업데이트는 번들 디렉토리 안의 지워진 파일을 남겨, 확장 갱신이 옛 파일을 활성 디렉토리로 다시
 * 옮기고 살아 있는 조각으로 재등록했다 — 주문 목록 열이 1.0.x 판 11열로 덮여 「클레임」 열이 사라지고 결제수단 칸의
 * 앵커가 무력화됐다(실측). 코어 7.1.0 스텝이 번들의 옛 파일을 지우지만, 그 판정을 비켜 간 파일(본문이 바뀐 사본 등)과
 * 이미 등록된 행은 이 스텝이 정리한다.
 *
 * 하는 일:
 *  1. 활성 디렉토리·번들 디렉토리의 옛 조각 파일을 지운다 — 이 플러그인이 1.1.0 에서 걷어낸 자기 파일이다.
 *     이 스텝은 확장 갱신의 레이아웃 등록보다 먼저 돌아, 지운 파일이 다시 등록되지 않는다.
 *  2. 이 플러그인이 그 조각으로 등록한 `template_layout_extensions` 행을 soft delete 한다 — 파일 키
 *     (`admin_order_list_test_badge`) 행과, 조각 식별자 백필이 대상 이름으로 채운 채 남은 옛 키 행
 *     (`fragment_key = target_name = admin_ecommerce_order_list`). 1.1.0 의 어떤 조각도 이 두 키를 쓰지 않는다.
 *
 * 범위: `source_type = plugin` · `source_identifier = sirsoft-pay_nhnkcp` 행만. 다른 확장의 행은 건드리지 않는다.
 *
 * 멱등: 파일이 없고 살아 있는 행이 없으면 아무것도 바꾸지 않는다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - `Illuminate\Support\Facades\{DB,Schema}` 와 PHP 내장 파일 함수만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class PruneRetiredExtensionFragments implements DataMigration
{
    /**
     * 확장 등록 테이블.
     */
    private const TABLE = 'template_layout_extensions';

    /**
     * 이 플러그인 식별자 — 자기 파일·행만 지우기 위한 스코프.
     */
    private const SOURCE_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    /**
     * 걷어낸 조각 키 → 그 조각이 겨냥하던 레이아웃(백필이 채운 옛 키).
     *
     * @var array<string, string>
     */
    private const RETIRED_FRAGMENTS = [
        'admin_order_list_test_badge' => 'admin_ecommerce_order_list',
    ];

    /**
     * @param  string|null  $pluginsRoot  플러그인 루트 디렉토리 (null 이면 base_path('plugins') — 테스트가 임시 루트를 주입한다)
     */
    public function __construct(private readonly ?string $pluginsRoot = null) {}

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'PruneRetiredExtensionFragments';
    }

    /**
     * 걷어낸 조각의 파일과 등록 행을 지운다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $removedFiles = $this->removeFiles($context);
        $removedRows = $this->softDeleteRows($context);

        $context->logger->info('[sirsoft-pay_nhnkcp] 걷어낸 조각 정리 완료', [
            'files' => $removedFiles,
            'rows' => $removedRows,
        ]);
    }

    /**
     * 활성·번들 디렉토리의 옛 조각 파일을 지운다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<int, string> 지운 파일 경로
     */
    private function removeFiles(UpgradeContext $context): array
    {
        $root = rtrim($this->pluginsRoot ?? base_path('plugins'), '/\\');
        $removed = [];

        foreach ([self::SOURCE_IDENTIFIER, '_bundled/'.self::SOURCE_IDENTIFIER] as $dir) {
            foreach (array_keys(self::RETIRED_FRAGMENTS) as $key) {
                $path = $root.'/'.$dir.'/resources/extensions/'.$key.'.json';

                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                if (! @unlink($path)) {
                    $context->logger->warning('[sirsoft-pay_nhnkcp] 걷어낸 조각 파일 삭제 실패 (권한 확인) — 남으면 다시 등록된다', ['path' => $path]);

                    continue;
                }

                $removed[] = $dir.'/resources/extensions/'.$key.'.json';
            }
        }

        return $removed;
    }

    /**
     * 걷어낸 조각의 등록 행을 soft delete 한다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int soft delete 한 행 수
     */
    private function softDeleteRows(UpgradeContext $context): int
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'fragment_key')) {
            $context->logger->info('[sirsoft-pay_nhnkcp] 확장 등록 테이블·조각 식별자 컬럼 없음 — 걷어낸 조각 행 정리 skip');

            return 0;
        }

        return DB::table(self::TABLE)
            ->where('source_type', 'plugin')
            ->where('source_identifier', self::SOURCE_IDENTIFIER)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                foreach (self::RETIRED_FRAGMENTS as $key => $legacyTarget) {
                    $query->orWhere('fragment_key', $key)
                        ->orWhere(fn ($legacy) => $legacy->where('fragment_key', $legacyTarget)->where('target_name', $legacyTarget));
                }
            })
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
}
