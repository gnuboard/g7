<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Validate;

use App\Extension\HookManager;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations\SeedClaimReasons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 1.3.0 업그레이드 데이터 스텝 결함 수정 검증 (#599 validate P4)
 *
 * - 02 사유 시드: 운영자·시스템 발 환불 사유 2종을 시더와 같은 값으로 보장하고 언어팩 번역을 병합한다
 * - 03 설정 병합: 1.2.x 기본값을 고정하고 자동 승인 3키를 넣는다
 * - 04 배송지 백필: 배송지 행을 청크 단위로 읽는다(N+1 제거)
 */
class ValidateP4UpgradeDataTest extends ModuleTestCase
{
    use CountsQueries;

    /** 데이터 마이그레이션 네임스페이스. */
    private const NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\';

    /** 테스트가 만든 임시 언어팩 루트 (tearDown 에서 지운다). */
    private ?string $tempLangRoot = null;

    /**
     * 데이터 마이그레이션 파일을 불러옵니다 (오토로드 밖).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = dirname(__DIR__, 3).'/upgrades/data/1.3.0/migrations/';
        require_once $dir.'02_SeedClaimReasons.php';
        require_once $dir.'03_MergeClaimSettingsDefaults.php';
        require_once $dir.'04_BackfillShippingSnapshotAddress.php';
    }

    /**
     * 임시 언어팩 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->tempLangRoot !== null && File::isDirectory($this->tempLangRoot)) {
            File::deleteDirectory($this->tempLangRoot);
        }

        parent::tearDown();
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 1.2.1 → 1.3.0 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext(fromVersion: '1.2.1', toVersion: '1.3.0', currentStep: '1.3.0');
    }

    /**
     * 일본어 사유 번역을 가진 임시 활성 언어팩 루트를 만들고, 그 루트를 읽는 사유 시드 스텝을 돌려줍니다.
     *
     * @return object 사유 시드 스텝
     */
    private function seedStepWithJapanesePack(): object
    {
        $this->tempLangRoot = sys_get_temp_dir().'/g7-validate-p4-langpacks-'.uniqid();
        $pack = $this->tempLangRoot.'/g7-module-sirsoft-ecommerce-ja';
        File::ensureDirectoryExists($pack.'/seed');
        File::put($pack.'/language-pack.json', json_encode([
            'identifier' => 'g7-module-sirsoft-ecommerce-ja',
            'target_identifier' => 'sirsoft-ecommerce',
            'locale' => 'ja',
        ]));
        File::put($pack.'/seed/claim_reasons.json', json_encode([
            'admin_cancel' => ['name' => '管理者キャンセル'],
            'auto_cancel_expired' => ['name' => '入金期限切れによる自動キャンセル'],
        ], JSON_UNESCAPED_UNICODE));

        $root = $this->tempLangRoot;

        return new class($root) extends SeedClaimReasons
        {
            /**
             * 읽을 언어팩 루트를 받습니다.
             *
             * @param  string  $root  임시 언어팩 루트
             */
            public function __construct(private string $root) {}

            /**
             * 임시 언어팩 루트를 돌려줍니다.
             *
             * @return string 언어팩 루트
             */
            protected function languagePackRoot(): string
            {
                return $this->root;
            }
        };
    }

    /**
     * 임시 언어팩 루트에 번들 팩 사본(1.3.0 판 seed)을 둡니다.
     *
     * @param  array<string, array{name: string}>  $seed  코드 → 항목
     * @return void 반환값 없음
     */
    private function putBundledJapaneseSeed(array $seed): void
    {
        $bundled = $this->tempLangRoot.'/_bundled/g7-module-sirsoft-ecommerce-ja/seed';
        File::ensureDirectoryExists($bundled);
        File::put($bundled.'/claim_reasons.json', json_encode($seed, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 사유 행의 이름을 배열로 읽습니다.
     *
     * @param  string  $code  사유 코드
     * @return array<string, string>|null 다국어 이름 (행이 없으면 null)
     */
    private function refundReasonName(string $code): ?array
    {
        $raw = DB::table('ecommerce_claim_reasons')->where('type', 'refund')->where('code', $code)->value('name');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    /**
     * 업그레이드 경로가 운영자·시스템 발 환불 사유 2종을 시더와 같은 이름으로 만들고 일본어를 병합한다.
     *
     * 수정 전 실패 이유: 02 스텝은 교환·반품만 시드했고, 두 코드는 언어팩을 읽지 않는 모듈
     * 마이그레이션이 시더와 다른 이름(「결제 기한 만료」)으로 넣어 일본어 상점에서 한국어로 남았다.
     *
     * @scenario defect=seed_reasons
     *
     * @effects upgrade_seeds_operator_refund_reasons_with_seeder_names_and_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_seeds_operator_refund_reasons_with_seeder_names_and_language_pack(): void
    {
        DB::table('ecommerce_claim_reasons')
            ->where('type', 'refund')
            ->whereIn('code', ['admin_cancel', 'auto_cancel_expired'])
            ->delete();

        $this->seedStepWithJapanesePack()->run($this->context());

        $defaults = collect((new ClaimReasonSeeder)->getDefaults())
            ->where('type', 'refund')
            ->keyBy('code');

        foreach (['admin_cancel', 'auto_cancel_expired'] as $code) {
            $name = $this->refundReasonName($code);

            $this->assertNotNull($name, "{$code} 행이 업그레이드 경로에서 만들어지지 않았습니다");
            $this->assertSame($defaults[$code]['name']['ko'], $name['ko'], "{$code} 한국어 이름이 시더와 다릅니다");
            $this->assertSame($defaults[$code]['name']['en'], $name['en'], "{$code} 영어 이름이 시더와 다릅니다");
            $this->assertArrayHasKey('ja', $name, "{$code} 에 언어팩 일본어가 병합되지 않았습니다");

            $row = DB::table('ecommerce_claim_reasons')->where('type', 'refund')->where('code', $code)->first();
            $this->assertSame(0, (int) $row->is_user_selectable, "{$code} 는 고객 선택지가 아니어야 합니다");
            $this->assertSame($defaults[$code]['sort_order'], (int) $row->sort_order);
        }

        $this->assertSame('入金期限切れによる自動キャンセル', $this->refundReasonName('auto_cancel_expired')['ja']);
    }

    /**
     * 활성 일본어 팩이 옛 판이라 새 사유 코드의 번역이 없으면 번들 팩 사본에서 메운다.
     *
     * 수정 전 실패 이유: 번들 일괄 업데이트는 모듈을 먼저, 언어팩을 나중에 갱신해 이 스텝이 도는 시점의 활성 팩에
     * 1.3.0 신규 사유(사이즈 불일치 등 7종)가 없었다 — 일본어 상점에서 그 사유가 한국어·영어 이름으로만 들어갔다(EU-5).
     *
     * @scenario defect=seed_reasons
     *
     * @effects upgrade_fills_missing_reason_translations_from_bundled_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_fills_missing_reason_translations_from_bundled_language_pack(): void
    {
        DB::table('ecommerce_claim_reasons')->where('type', 'exchange')->where('code', 'size_mismatch')->delete();
        DB::table('ecommerce_claim_reasons')->where('type', 'refund')->where('code', 'admin_cancel')->delete();

        $step = $this->seedStepWithJapanesePack();
        $this->putBundledJapaneseSeed([
            'size_mismatch' => ['name' => 'サイズが合わない'],
            'admin_cancel' => ['name' => 'バンドル版の名前'],
        ]);

        $step->run($this->context());

        $exchange = DB::table('ecommerce_claim_reasons')->where('type', 'exchange')->where('code', 'size_mismatch')->value('name');
        $this->assertSame('サイズが合わない', json_decode((string) $exchange, true)['ja'] ?? null, '활성 팩에 없는 코드가 번들 팩 사본으로 메워지지 않았습니다');
        $this->assertSame('管理者キャンセル', $this->refundReasonName('admin_cancel')['ja'], '활성 팩에 있는 코드는 활성 팩 값을 써야 합니다');
    }

    /**
     * 실제 번들 일본어 팩 사본으로 업그레이드가 만드는 사유 전부(교환·반품 전부 + 환불 2종)에 일본어 이름이 들어간다.
     *
     * 7.1.0 출시 리허설 실측: 수정 전에는 업데이트 순간 활성 팩이 7.0.11 판이라 새로 만든 교환·반품 사유와
     * `auto_cancel_expired` 의 `name.ja` 가 비었다. 활성 팩은 새 코드가 없는 옛 판으로 두고 번들 사본은 저장소 실물을 쓴다.
     *
     * @scenario defect=seed_reasons
     *
     * @effects upgrade_fills_missing_reason_translations_from_bundled_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_every_reason_created_by_upgrade_gets_japanese_name_from_real_bundled_pack(): void
    {
        DB::table('ecommerce_claim_reasons')->whereIn('type', ['exchange', 'return'])->delete();
        DB::table('ecommerce_claim_reasons')->where('type', 'refund')->whereIn('code', ['admin_cancel', 'auto_cancel_expired'])->delete();

        $step = $this->seedStepWithJapanesePack();
        File::put($this->tempLangRoot.'/g7-module-sirsoft-ecommerce-ja/seed/claim_reasons.json', json_encode([
            'order_mistake' => ['name' => '注文ミス'],
        ], JSON_UNESCAPED_UNICODE));

        $real = json_decode((string) File::get(base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/seed/claim_reasons.json')), true);
        $this->putBundledJapaneseSeed($real);

        $step->run($this->context());

        $rows = DB::table('ecommerce_claim_reasons')
            ->where(fn ($q) => $q->whereIn('type', ['exchange', 'return'])
                ->orWhere(fn ($r) => $r->where('type', 'refund')->whereIn('code', ['admin_cancel', 'auto_cancel_expired'])))
            ->get(['type', 'code', 'name']);

        $this->assertGreaterThanOrEqual(19, $rows->count(), '교환 8·반품 9·환불 2 가 만들어져야 합니다');
        foreach ($rows as $row) {
            $name = json_decode((string) $row->name, true);
            $this->assertSame($real[$row->code]['name'] ?? null, $name['ja'] ?? null, "{$row->type}/{$row->code} 의 일본어 이름이 비었거나 번들 팩과 다릅니다");
        }
    }

    /**
     * 업그레이드 스텝과 신규 설치 시더가 운영자·시스템 발 환불 사유 2종을 같은 값으로 만든다.
     *
     * 모듈 마이그레이션(`2026_09_12_000001`)을 지우고 그 보장을 업그레이드 스텝으로 옮겼으므로, 두 공급
     * 경로 — 업그레이드(02 스텝)와 신규 설치(`ClaimReasonSeeder` + 언어팩 번역 필터) — 가 같은 언어팩
     * 번역을 받았을 때 이름(ko·en·ja)·귀책·고객 선택 여부·정렬이 모두 같아야 한다.
     *
     * 수정 전 실패 이유: 02 스텝은 환불 사유를 만들지 않았고, 두 코드를 넣던 마이그레이션은 시더와
     * 다른 이름으로 언어팩 없이 넣어 두 경로의 결과가 갈렸다.
     *
     * @scenario defect=seed_reasons
     *
     * @effects upgrade_step_and_fresh_install_seeder_produce_the_same_operator_reasons
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_step_and_fresh_install_seeder_produce_the_same_operator_reasons(): void
    {
        $japanese = [
            'admin_cancel' => '管理者キャンセル',
            'auto_cancel_expired' => '入金期限切れによる自動キャンセル',
        ];

        // 신규 설치 경로 — 설치된 언어팩이 시드 번역 필터로 ja 를 얹는 것과 같은 모양으로 흉내 낸다.
        HookManager::addFilter('seed.sirsoft-ecommerce.claim_reasons.translations', function (array $entries) use ($japanese) {
            foreach ($entries as $i => $entry) {
                if (isset($japanese[$entry['code']])) {
                    $entries[$i]['name']['ja'] = $japanese[$entry['code']];
                }
            }

            return $entries;
        }, 1);

        $seeder = new ClaimReasonSeeder;
        $resolve = new \ReflectionMethod($seeder, 'resolveTranslatedDefaults');
        $fresh = collect($resolve->invoke($seeder))->where('type', 'refund')->keyBy('code');

        // 업그레이드 경로 — 같은 번역을 가진 활성 언어팩을 02 스텝이 읽는다.
        DB::table('ecommerce_claim_reasons')
            ->where('type', 'refund')
            ->whereIn('code', array_keys($japanese))
            ->delete();
        $this->seedStepWithJapanesePack()->run($this->context());

        foreach (array_keys($japanese) as $code) {
            $row = DB::table('ecommerce_claim_reasons')->where('type', 'refund')->where('code', $code)->first();
            $this->assertNotNull($row, "{$code} 를 업그레이드 경로가 만들지 않았습니다");

            $upgraded = json_decode((string) $row->name, true);
            ksort($upgraded);
            $installed = $fresh[$code]['name'];
            ksort($installed);

            $this->assertSame($installed, $upgraded, "{$code} 이름이 신규 설치와 업그레이드에서 다릅니다");
            $this->assertSame($fresh[$code]['fault_type'], $row->fault_type);
            $this->assertSame((bool) $fresh[$code]['is_user_selectable'], (bool) $row->is_user_selectable);
            $this->assertSame((int) $fresh[$code]['sort_order'], (int) $row->sort_order);
        }
    }

    /**
     * 모듈 마이그레이션이 사유 데이터를 넣지 않는다 — 데이터 보장은 업그레이드 스텝 한 곳이다.
     *
     * 수정 전 실패 이유: `2026_09_12_000001_ensure_operator_claim_reason_codes_exist` 가 사유 행을
     * 직접 insert 해, 스텝보다 먼저 돌아 스텝의 언어팩 병합을 건너뛰게 만들었다.
     *
     * @scenario defect=seed_reasons
     *
     * @effects no_module_migration_inserts_claim_reason_rows
     *
     * @return void 반환값 없음
     */
    public function test_no_module_migration_inserts_claim_reason_rows(): void
    {
        $offenders = [];

        foreach (File::files($this->getModuleBasePath().'/database/migrations') as $file) {
            $source = (string) File::get($file->getPathname());

            if (str_contains($source, "'auto_cancel_expired'") || str_contains($source, "'admin_cancel'")) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, '모듈 마이그레이션이 운영자 사유 행을 직접 넣습니다');
    }

    /**
     * 설정 병합 스텝이 저장값 없는 상점에 1.2.x 기본값을 쓰고, 자동 승인 3키를 넣는다.
     *
     * 수정 전 실패 이유: 저장값이 없으면 새 버전의 defaults.json(미결제 2종까지 넓힌 값)을 읽어
     * 스텝 결과가 설치된 파일에 따라 달라졌고, 자동 승인 3키는 claim.json 에 들어가지 않았다.
     *
     * @scenario defect=settings_defaults
     *
     * @effects claim_settings_merge_freezes_legacy_default_and_adds_auto_approve_keys
     *
     * @return void 반환값 없음
     */
    public function test_claim_settings_merge_freezes_legacy_default_and_adds_auto_approve_keys(): void
    {
        $dir = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings');
        $orderPath = $dir.'/order_settings.json';
        $claimPath = $dir.'/claim.json';

        $originalOrder = File::exists($orderPath) ? File::get($orderPath) : null;
        $originalClaim = File::exists($claimPath) ? File::get($claimPath) : null;

        try {
            File::ensureDirectoryExists($dir);
            File::put($orderPath, '{}');
            if (File::exists($claimPath)) {
                File::delete($claimPath);
            }

            $class = self::NS.'MergeClaimSettingsDefaults';
            (new $class)->run($this->context());

            $claim = json_decode((string) File::get($claimPath), true);

            $this->assertSame(['payment_complete'], $claim['instant_cancel_statuses']);
            $this->assertSame(['payment_complete', 'preparing'], $claim['auto_approve_refund_statuses']);
            $this->assertSame(['card', 'point', 'free'], $claim['auto_approve_refund_payment_methods']);
            $this->assertArrayHasKey('auto_approve_refund_payment_methods_decided', $claim);
            $this->assertSame([], $claim['auto_approve_refund_payment_methods_decided']);
        } finally {
            $originalOrder === null ? File::delete($orderPath) : File::put($orderPath, $originalOrder);
            if ($originalClaim === null) {
                File::delete($claimPath);
            } else {
                File::put($claimPath, $originalClaim);
            }
        }
    }

    /**
     * 배송지 백필이 주문마다 배송지를 따로 조회하지 않는다.
     *
     * 수정 전 실패 이유: 후보 주문마다 `findShippingAddressRow()` 가 한 번씩 돌아 배송지 조회 수가
     * 주문 수와 같았다(청크당 1회여야 한다).
     *
     * @scenario defect=backfill_n_plus_one
     *
     * @effects shipping_snapshot_backfill_reads_addresses_once_per_chunk
     *
     * @return void 반환값 없음
     */
    public function test_shipping_snapshot_backfill_reads_addresses_once_per_chunk(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create([
                'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make([['product_option_id' => $i + 1]], []),
            ]);
            OrderAddress::factory()->create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'recipient_country_code' => 'KR',
                'zipcode' => '0630'.$i,
            ]);
        }

        $class = self::NS.'BackfillShippingSnapshotAddress';
        $queries = $this->captureQueries(fn () => (new $class)->run($this->context()));

        $orderSelects = count(array_filter($queries, fn (string $sql) => preg_match('/^select .* from [`"]?\w*ecommerce_orders[`"]? where/i', $sql) === 1));
        $addressSelects = count(array_filter($queries, fn (string $sql) => preg_match('/^select .* from [`"]?\w*ecommerce_order_addresses[`"]?/i', $sql) === 1));

        $this->assertGreaterThan(0, $addressSelects, '배송지 조회가 한 번도 일어나지 않았습니다(픽스처 확인)');
        $this->assertLessThanOrEqual(
            $orderSelects,
            $addressSelects,
            "배송지 조회 {$addressSelects}회가 주문 청크 조회 {$orderSelects}회보다 많습니다 — 주문마다 조회합니다(N+1)"
        );
    }
}
