<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftTosspayments\V1_1_0\Migrations\DropLegacyPaymentPanelOverlay;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 기설치본의 구 overlay 등록 행 제거 (S13 — 결제 정보 카드 주입을 공용 슬롯으로 전환).
 *
 * 이 플러그인은 가상계좌 입금 안내·영수증·부가세 내역을 주문 상세의 결제 정보 카드에
 * 끼워 넣는다. 그 주입이 레이아웃 이름을 겨냥하는 overlay(`target_layout: mypage/orders/show`)
 * 였는데, 그 카드는 `templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_payment.g7.html` 이고 클레임 신청서 상세도 같은
 * partial 을 재사용한다. overlay 는 `extends` 상속 체인만 따라가고 partial 재사용은 따라가지
 * 않으므로 그 화면에서는 카드는 뜨는데 이 블록만 빠진 채 열렸다. 그래서 주입 대상을 공용 슬롯
 * (`mypage_order_payment_provider_slot`)으로 옮겼다.
 *
 * 확장 갱신은 등록과 "적용 불가" 정리만 하고 파일이 더 이상 선언하지 않는 대상의 행은
 * 지우지 않는다. 그래서 이미 설치를 마친 사이트는 구 overlay 행이 살아남아 주문 상세에
 * 같은 블록이 두 번 뜬다. 이 마이그레이션이 그 행만 지운다.
 *
 * 이 테스트가 고정하는 것은 세 축이다 — 삭제 범위(자기 행만) · 멱등 · 대상 0건 안전.
 * 되돌리면(스코프 조건 제거 / run() 본문 제거) 각각 red 가 된다.
 */
#[Group('payment')]
#[Group('upgrade')]
class DropLegacyPaymentPanelOverlayTest extends PluginTestCase
{
    /**
     * 확장 등록 테이블.
     */
    private const TABLE = 'template_layout_extensions';

    /**
     * 구 overlay 가 겨냥하던 레이아웃 이름.
     */
    private const LEGACY_TARGET = 'mypage/orders/show';

    /**
     * 이 플러그인 식별자.
     */
    private const SELF = 'sirsoft-tosspayments';

    /**
     * FK 대상 템플릿 ID.
     */
    private int $templateId;

    /**
     * 업그레이드 데이터 마이그레이션을 불러오고 이 템플릿의 확장 등록 행을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/sirsoft-tosspayments/upgrades/data/1.1.0/migrations/DropLegacyPaymentPanelOverlay.php');

        $this->templateId = $this->ensureTemplate();
        DB::table(self::TABLE)->where('template_id', $this->templateId)->delete();
    }

    /**
     * 자기 플러그인이 남긴 구 overlay 행만 지우고 다른 확장·다른 대상·확장 슬롯 행은 남긴다.
     *
     * @scenario scope=own_rows
     *
     * @effects legacy_overlay_rows_of_this_plugin_only_are_removed
     *
     * @return void 반환값 없음
     */
    public function test_자기_플러그인이_남긴_구_overlay_행만_지운다(): void
    {
        $mine = $this->seedRow('overlay', self::LEGACY_TARGET, self::SELF);
        $otherPlugin = $this->seedRow('overlay', self::LEGACY_TARGET, 'sirsoft-other_plugin');
        $otherTarget = $this->seedRow('overlay', 'shop/checkout', self::SELF);
        $extensionPoint = $this->seedRow('extension_point', self::LEGACY_TARGET, self::SELF);

        // 하한 — 지울 대상이 실제로 심어졌는지 먼저 확인한다.
        // 이 단언이 없으면 seed 가 깨져도 아래 검사가 전건 통과한다.
        $this->assertSame(1, $this->countById($mine), '지울 대상 행이 심어지지 않았다 — seed 가 깨졌다');

        $this->runMigration();

        $this->assertSame(0, $this->countById($mine), '자기 구 overlay 행이 지워지지 않았다');
        $this->assertSame(1, $this->countById($otherPlugin), '다른 플러그인의 행까지 지웠다 — source_identifier 스코프가 없다');
        $this->assertSame(1, $this->countById($otherTarget), '다른 레이아웃을 겨냥한 행까지 지웠다 — target_name 스코프가 없다');
        $this->assertSame(1, $this->countById($extensionPoint), '확장점 등록 행까지 지웠다 — extension_type 스코프가 없다');
    }

    /**
     * 두 번 실행해도 결과가 같다(멱등).
     *
     * @scenario scope=rerun
     *
     * @effects legacy_overlay_drop_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_두_번_실행해도_안전하다(): void
    {
        $mine = $this->seedRow('overlay', self::LEGACY_TARGET, self::SELF);
        $survivor = $this->seedRow('overlay', self::LEGACY_TARGET, 'sirsoft-other_plugin');

        $this->assertSame(1, $this->countById($mine), '지울 대상 행이 심어지지 않았다 — seed 가 깨졌다');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(0, $this->countById($mine));
        $this->assertSame(1, $this->countById($survivor), '재실행이 남의 행을 건드렸다');
    }

    /**
     * 지울 행이 없어도 예외 없이 끝난다.
     *
     * @scenario scope=no_rows
     *
     * @effects legacy_overlay_drop_is_safe_without_rows
     *
     * @return void 반환값 없음
     */
    public function test_지울_행이_없어도_안전하다(): void
    {
        $survivor = $this->seedRow('overlay', self::LEGACY_TARGET, 'sirsoft-other_plugin');

        $this->runMigration();

        $this->assertSame(1, $this->countById($survivor));
        $this->assertSame(1, DB::table(self::TABLE)->where('template_id', $this->templateId)->count());
    }

    /**
     * 마이그레이션을 실행합니다. (공개판 1.0.3 에서 올리는 경로)
     *
     * @return void 반환값 없음
     */
    private function runMigration(): void
    {
        (new DropLegacyPaymentPanelOverlay)->run(new UpgradeContext(
            fromVersion: '1.0.3',
            toVersion: '1.1.0',
            currentStep: '1.1.0',
        ));
    }

    /**
     * 등록 행을 하나 심고 그 ID 를 돌려줍니다.
     *
     * @param  string  $type  확장 타입 (overlay | extension_point)
     * @param  string  $target  타겟 이름
     * @param  string  $source  출처 식별자
     * @return int 삽입된 행 ID
     */
    private function seedRow(string $type, string $target, string $source): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'template_id' => $this->templateId,
            'extension_type' => $type,
            'target_name' => $target,
            'source_type' => 'plugin',
            'source_identifier' => $source,
            'content' => '{}',
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 주어진 ID 의 행 수를 셉니다 (0 또는 1).
     *
     * @param  int  $id  등록 행 ID
     * @return int 행 수
     */
    private function countById(int $id): int
    {
        return DB::table(self::TABLE)->where('id', $id)->count();
    }

    /**
     * FK 대상 템플릿 행을 확보합니다.
     *
     * @return int 템플릿 ID
     */
    private function ensureTemplate(): int
    {
        $existing = DB::table('templates')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('templates')->insertGetId([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => 'basic', 'en' => 'basic']),
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
