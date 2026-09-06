<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftTosspayments\V1_0_1\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 저장된 이커머스 주문설정의 토스 주문서형 결제수단 항목에 PG 고정 선언을 백필한다.
 *
 * 배경:
 *
 * 주문서형 결제수단(toss_*)은 과거 `pg_provider: null` 로 등록되어 저장 파일에 그대로
 * 영속화됐다. 그 결과 관리자 주문설정 화면이 다른 간편결제(KG 이니시스 등)와 달리
 * "PG 고정" 배지 대신 빈 PG 선택 셀렉트(---)를 그렸다.
 *
 * 현재 코드는 결제수단 카탈로그를 병합할 때 능력 선언(pg_provider / pg_locked / needs_pg /
 * refund_method)을 플러그인 정의에서 가져오므로 런타임 표시는 이미 정상이다(자가 치유).
 * 본 마이그레이션은 저장 파일 자체를 현재 선언과 일치시켜 설정 화면과 파일 내용이
 * 어긋나 보이지 않게 한다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + FilePermissionHelper 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class BackfillTossPgProvider implements DataMigration
{
    /**
     * 이 플러그인이 등록하는 주문서형 결제수단의 ID 접두사.
     */
    private const METHOD_PREFIX = 'toss_';

    /**
     * 이 플러그인이 제공하는 PG 식별자.
     */
    private const PG_PROVIDER_ID = 'tosspayments';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'BackfillTossPgProvider';
    }

    /**
     * 저장된 주문설정의 토스 결제수단 항목에 PG 고정 선언을 백필한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 이커머스 모듈의 주문설정 저장 경로. 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다
        // 경로를 직접 조립하면 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';

        if (! File::exists($path)) {
            $context->logger->info('[tosspayments] 이커머스 주문설정 파일 없음 — 기본값으로 동작하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);
        if (! is_array($settings) || ! is_array($settings['payment_methods'] ?? null)) {
            $context->logger->warning('[tosspayments] 주문설정 JSON 형식 비정상 — 결제수단 PG 백필 skip');

            return;
        }

        $updated = 0;
        foreach ($settings['payment_methods'] as $index => $method) {
            if (! is_array($method)) {
                continue;
            }

            $id = (string) ($method['id'] ?? '');
            if (! str_starts_with($id, self::METHOD_PREFIX)) {
                continue;
            }

            $before = [
                $method['pg_provider'] ?? null,
                $method['pg_locked'] ?? null,
                $method['needs_pg'] ?? null,
                $method['refund_method'] ?? null,
            ];

            $method['pg_provider'] = self::PG_PROVIDER_ID;
            $method['pg_locked'] = true;
            $method['needs_pg'] = true;
            $method['refund_method'] = 'pg';

            $after = [
                $method['pg_provider'],
                $method['pg_locked'],
                $method['needs_pg'],
                $method['refund_method'],
            ];

            if ($before !== $after) {
                $updated++;
            }

            $settings['payment_methods'][$index] = $method;
        }

        if ($updated === 0) {
            $context->logger->info('[tosspayments] 결제수단 PG 선언이 이미 최신 — 변경 없음');

            return;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[tosspayments] 주문서형 결제수단 PG 고정 선언 백필 완료', [
            'pg_provider' => self::PG_PROVIDER_ID,
            'updated_methods' => $updated,
        ]);
    }
}
