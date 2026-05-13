<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Extension\UpgradeContext;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * v1.0.0-beta.2 업그레이드 스텝
 *
 * - 배송유형을 Enum에서 DB 테이블로 이관
 * - ecommerce_mail_templates → notification_definitions + notification_templates 이관
 */
class Upgrade_1_0_0_beta_2 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        // 1. 테이블 존재 확인
        if (! Schema::hasTable('ecommerce_shipping_types')) {
            $context->logger->warning('[v1.0.0-beta.2] ecommerce_shipping_types 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        // 2. 기본 배송유형 데이터 삽입
        $created = 0;
        foreach ($this->getDefaultTypes() as $type) {
            $result = ShippingType::firstOrCreate(
                ['code' => $type['code']],
                $type
            );

            if ($result->wasRecentlyCreated) {
                $created++;
            }
        }

        $total = ShippingType::count();
        $context->logger->info("[v1.0.0-beta.2] 배송유형 초기 데이터: {$created}건 생성 (전체 {$total}건)");

        // 3. 기존 order_shippings 데이터 코드 변환 (domestic_parcel → parcel 등)
        if (Schema::hasTable('ecommerce_order_shippings')) {
            $codeMap = [
                'domestic_parcel' => 'parcel',
                'domestic_express' => 'express',
                'domestic_quick' => 'quick',
            ];

            $converted = 0;
            foreach ($codeMap as $old => $new) {
                $count = DB::table('ecommerce_order_shippings')
                    ->where('shipping_type', $old)
                    ->update(['shipping_type' => $new]);
                $converted += $count;
            }

            if ($converted > 0) {
                $context->logger->info("[v1.0.0-beta.2] order_shippings 코드 변환: {$converted}건");
            }
        }

        // 4. shipping_policy_country_settings의 미등록 코드 자동 생성
        if (Schema::hasTable('ecommerce_shipping_policy_country_settings')) {
            $validCodes = ShippingType::pluck('code')->toArray();
            $invalidMethods = DB::table('ecommerce_shipping_policy_country_settings')
                ->whereNotNull('shipping_method')
                ->whereNotIn('shipping_method', $validCodes)
                ->distinct()
                ->pluck('shipping_method');

            foreach ($invalidMethods as $code) {
                ShippingType::firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => ['ko' => $code, 'en' => $code],
                        'category' => 'other',
                        'is_active' => false,
                        'sort_order' => 99,
                    ]
                );
                $context->logger->info("[v1.0.0-beta.2] 미등록 배송유형 자동 생성: {$code}");
            }
        }

        // 5. 이커머스 알림 정의 이관
        $this->migrateNotificationDefinitions($context);
    }

    /**
     * 이커머스 알림 정의 시딩 및 메일 템플릿 이관.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function migrateNotificationDefinitions(UpgradeContext $context): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            $context->logger->warning('[v1.0.0-beta.2] notification_definitions 테이블 미존재 — 코어 업그레이드 먼저 실행 필요');

            return;
        }

        // 알림 정의 시딩 (7종 — mail + database 채널 템플릿 포함)
        // module.php::getNotificationDefinitions() 가 SSoT — declarative getter 패턴
        $context->logger->info('[v1.0.0-beta.2] 이커머스 알림 정의 시딩...');
        $this->syncEcommerceNotificationDefinitions();

        // database 채널 템플릿 보강 — 이전 빌드에서 시더를 실행하여 mail 템플릿만 생성된 환경에서
        // database 채널 템플릿이 누락된 정의를 보완합니다. updateOrCreate 패턴이므로 중복 생성 없음.
        $this->ensureDatabaseChannelTemplates($context);

        // ecommerce_mail_templates → notification_templates 이관
        if (Schema::hasTable('ecommerce_mail_templates')) {
            $templates = DB::table('ecommerce_mail_templates')->get();
            $migratedCount = 0;

            foreach ($templates as $template) {
                $definition = NotificationDefinition::where('type', $template->type)->first();
                if (! $definition) {
                    continue;
                }

                // ecommerce_mail_templates.variables → notification_definitions.variables fallback 매핑.
                if (property_exists($template, 'variables') && $template->variables) {
                    $existingVariables = $definition->variables;
                    $isEmpty = empty($existingVariables) || $existingVariables === '[]' || $existingVariables === [];
                    if ($isEmpty) {
                        $definition->variables = json_decode($template->variables, true) ?? [];
                        $definition->save();
                    }
                }

                NotificationTemplate::updateOrCreate(
                    ['definition_id' => $definition->id, 'channel' => 'mail'],
                    [
                        'subject' => json_decode($template->subject, true) ?? [],
                        'body' => json_decode($template->body, true) ?? [],
                        'click_url' => null,
                        'recipients' => null,
                        'is_active' => $template->is_active,
                        'is_default' => $template->is_default ?? true,
                        'user_overrides' => property_exists($template, 'user_overrides') && $template->user_overrides ? json_decode($template->user_overrides, true) : null,
                        'updated_by' => $template->updated_by ?? null,
                    ]
                );

                $migratedCount++;
            }

            $context->logger->info("[v1.0.0-beta.2] 이커머스 메일 템플릿 {$migratedCount}건 이관 완료");
        }

        // 캐시 무효화 (CacheInterface 추상화 사용 — 코어 캐시 드라이버 위임)
        $cache = app(CacheInterface::class);
        $cachePrefix = 'mail_template:sirsoft-ecommerce:';
        $types = ['order_confirmed', 'order_shipped', 'order_completed', 'order_cancelled', 'new_order_admin', 'inquiry_received', 'inquiry_replied'];
        foreach ($types as $type) {
            $cache->forget($cachePrefix . $type);
        }

        $context->logger->info('[v1.0.0-beta.2] 이커머스 알림 정의 이관 완료');
    }

    /**
     * module.php::getNotificationDefinitions() 를 SSoT 로 알림 정의를 동기화합니다.
     *
     * declarative getter 패턴 도입 이후 별도 Seeder 없이 Manager 가 자동 동기화하나,
     * 본 업그레이드 스텝은 beta.1/2 → beta.3 경로 호환을 위해 명시적으로 1회 동기화합니다.
     *
     * @return void
     */
    private function syncEcommerceNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        $definedTypes = [];

        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';

            $definition = $helper->syncDefinition($data);
            $definedTypes[] = $definition->type;

            $definedChannels = [];
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);
        }

        $helper->cleanupStaleDefinitions('module', 'sirsoft-ecommerce', $definedTypes);
    }

    /**
     * 이전 빌드에서 mail 템플릿만 시딩된 알림 정의에 database 채널 템플릿을 보강합니다.
     *
     * 시더가 updateOrCreate 패턴이므로 이미 존재하는 경우 no-op입니다.
     * 시더 재실행만으로도 동일 효과이지만, 업그레이드 스텝의 멱등성 보장 목적으로 명시적 검증합니다.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function ensureDatabaseChannelTemplates(UpgradeContext $context): void
    {
        $types = ['order_confirmed', 'order_shipped', 'order_completed', 'order_cancelled', 'new_order_admin', 'inquiry_received', 'inquiry_replied'];
        $created = 0;

        // module.php::getNotificationDefinitions() 가 SSoT
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $definitionsMap = $module
            ? collect($module->getNotificationDefinitions())->keyBy('type')
            : collect();

        foreach ($types as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            if (! $definition) {
                continue;
            }

            $hasDbTemplate = NotificationTemplate::where('definition_id', $definition->id)
                ->where('channel', 'database')
                ->exists();

            if ($hasDbTemplate) {
                continue;
            }

            // 시더 정의에서 database 템플릿 데이터 가져오기
            $defData = $definitionsMap->get($type);
            $dbTemplateData = collect($defData['templates'] ?? [])->firstWhere('channel', 'database');

            if (! $dbTemplateData) {
                continue;
            }

            NotificationTemplate::create([
                'definition_id' => $definition->id,
                'channel' => 'database',
                'subject' => $dbTemplateData['subject'],
                'body' => $dbTemplateData['body'],
                'click_url' => $dbTemplateData['click_url'] ?? null,
                'recipients' => $dbTemplateData['recipients'] ?? null,
                'is_active' => true,
                'is_default' => true,
            ]);

            $created++;
        }

        if ($created > 0) {
            $context->logger->info("[v1.0.0-beta.2] database 채널 템플릿 보강: {$created}건");
        }
    }

    /**
     * 기본 배송유형 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultTypes(): array
    {
        return [
            ['code' => 'parcel', 'name' => ['ko' => '택배', 'en' => 'Parcel'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'direct', 'name' => ['ko' => '직접배송', 'en' => 'Direct Delivery'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'quick', 'name' => ['ko' => '퀵서비스', 'en' => 'Quick Service'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'freight', 'name' => ['ko' => '화물배송', 'en' => 'Freight'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 4],
            ['code' => 'pickup', 'name' => ['ko' => '매장수령', 'en' => 'Store Pickup'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 5],
            ['code' => 'express', 'name' => ['ko' => '국내특급', 'en' => 'Express'], 'category' => 'domestic', 'is_active' => false, 'sort_order' => 6],
            ['code' => 'international_ems', 'name' => ['ko' => '국제EMS', 'en' => 'International EMS'], 'category' => 'international', 'is_active' => false, 'sort_order' => 7],
            ['code' => 'international_standard', 'name' => ['ko' => '국제일반', 'en' => 'International Standard'], 'category' => 'international', 'is_active' => false, 'sort_order' => 8],
            ['code' => 'cvs', 'name' => ['ko' => '편의점택배', 'en' => 'Convenience Store'], 'category' => 'other', 'is_active' => false, 'sort_order' => 9],
            ['code' => 'digital', 'name' => ['ko' => '디지털상품', 'en' => 'Digital'], 'category' => 'other', 'is_active' => false, 'sort_order' => 10],
            ['code' => 'custom', 'name' => ['ko' => '직접입력', 'en' => 'Custom'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 99],
        ];
    }
}
