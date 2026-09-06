<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftTosspayments\V1_0_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 저장된 결제 리다이렉트 주소를 상점 주소 설정을 따르도록 백필한다.
 *
 * 배경 (공개 #85):
 *
 * 결제 완료·실패 후 돌아갈 주소의 기본값이 `/shop/...` 리터럴이었다. 상점 주소는
 * 운영자 설정(`basic_info.route_path` / `no_route`)이라 주소를 바꾼 상점에서는
 * 결제를 마친 구매자가 존재하지 않는 페이지로 떨어졌다. 리다이렉트라 예외도 로그도
 * 남지 않아 증상이 드러나지 않는다.
 *
 * 코드 기본값은 `{shopBase}` 자리표시자로 바뀌었지만, 이미 설치를 마친 사이트는
 * 예전 리터럴이 설정 파일에 저장돼 있어 그대로 깨진 채로 남는다. 본 마이그레이션이
 * 그 저장값을 자리표시자 형태로 바꾼다.
 *
 * 운영자 입력 보존: 저장값이 **예전 기본값과 정확히 같을 때만** 바꾼다. 운영자가
 * 직접 넣은 주소(절대 URL·자기 경로)는 의도된 값이므로 건드리지 않는다.
 *
 * 멱등: 이미 자리표시자로 바뀐 값은 예전 기본값과 다르므로 재실행해도 변화가 없다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + FilePermissionHelper 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class BackfillShopBaseRedirectUrls implements DataMigration
{
    /**
     * 예전 기본값 => 새 기본값. 저장값이 좌변과 정확히 같을 때만 우변으로 바꾼다.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const REPLACEMENTS = [
        'redirect_success_url' => ['/shop/orders/{orderId}/complete', '{shopBase}/orders/{orderId}/complete'],
        'redirect_fail_url' => ['/shop/checkout', '{shopBase}/checkout'],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'BackfillShopBaseRedirectUrls';
    }

    /**
     * 저장된 리다이렉트 주소를 자리표시자 형태로 백필한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 이 플러그인의 설정 저장 경로. 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다
        // 경로를 직접 조립하면 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::plugin('sirsoft-tosspayments', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[tosspayments] 설정 파일 없음 — 새 기본값으로 동작하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[tosspayments] 설정 JSON 형식 비정상 — 리다이렉트 주소 백필 skip');

            return;
        }

        [$settings, $updated] = self::apply($settings);

        if ($updated === []) {
            $context->logger->info('[tosspayments] 리다이렉트 주소가 예전 기본값이 아님 — 운영자 설정 보존, 변경 없음');

            return;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[tosspayments] 결제 리다이렉트 주소를 상점 주소 설정 기준으로 백필 완료', [
            'updated_keys' => $updated,
        ]);
    }

    /**
     * 저장된 설정 배열에 백필을 적용한 결과를 반환합니다.
     *
     * 파일 I/O 와 분리한 순수 함수다 — 실제 설정 파일을 건드리지 않고 판단 규칙
     * (예전 기본값과 **정확히 같을 때만** 교체)을 검증할 수 있다.
     *
     * @param  array<string, mixed>  $settings  저장된 설정
     * @return array{0: array<string, mixed>, 1: array<int, string>} [갱신된 설정, 바뀐 키 목록]
     */
    public static function apply(array $settings): array
    {
        $updated = [];

        foreach (self::REPLACEMENTS as $key => [$legacy, $replacement]) {
            if (($settings[$key] ?? null) === $legacy) {
                $settings[$key] = $replacement;
                $updated[] = $key;
            }
        }

        return [$settings, $updated];
    }
}
