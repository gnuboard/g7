<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\AllowedExtensions;
use App\Support\PublicAssetDisk;
use App\Support\PublicDiskHardening;
use App\Support\PublicDiskHardeningProbe;
use Illuminate\Support\Facades\Log;

/**
 * 드라이버 설정 저장 시 공개 디스크 보호 규칙 파일을 맞추고 직접 주소 응답을 점검하는 리스너
 *
 * 환경설정 > 드라이버 탭을 저장하면
 * - 공개 디스크 루트의 웹서버 규칙 파일을 지금 설정(「공개 디스크로 내보낼 확장자」)에 맞춰 다시 만들고,
 * - 공개 자산 디스크가 로컬 공개 디스크(`public`)면 `/storage` 직접 주소의 보호 헤더를 점검해 결과를 남긴다.
 *   다른 디스크면 지난 점검 결과를 지운다(경고를 띄울 대상이 아니다).
 *
 * 저장은 막지 않는다 — 이 리스너의 실패는 경고 로그로만 남긴다.
 */
class PublicDiskHardeningListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.after_save' => [
                'method' => 'onSettingsSave',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 드라이버 탭 저장 뒤 규칙 파일을 맞추고 직접 주소를 점검합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($tab, $mergedSettings, $result)
     * @return void 반환값 없음
     */
    public function onSettingsSave(...$args): void
    {
        $tab = $args[0] ?? null;
        $settings = is_array($args[1] ?? null) ? $args[1] : [];
        $saved = $args[2] ?? true;

        if ($tab !== 'drivers' || $saved === false) {
            return;
        }

        // 자동 시험 중에는 실제 공개 디스크와 사이트 주소를 건드리지 않는다 — 이 리스너를 검증하는
        // 시험만 스위치를 켜고 임시 저장소 경로·가짜 HTTP 로 돌린다.
        if (app()->runningUnitTests() && ! config('core.storage.public_disk_hardening_in_tests', false)) {
            return;
        }

        try {
            // 저장한 요청 안에서는 부팅 때 주입된 config 가 옛 값이다 — 방금 저장한 값으로 맞춘다.
            if (array_key_exists('public_asset_extensions', $settings)) {
                $extensions = AllowedExtensions::normalize($settings['public_asset_extensions']);
                if ($extensions !== []) {
                    config(['core.storage.public_asset_extensions' => $extensions]);
                }
            }
            if (array_key_exists('public_asset_disk', $settings)) {
                $disk = (string) ($settings['public_asset_disk'] ?? '');
                config(['core.storage.public_asset_disk' => $disk === 'none' ? '' : $disk]);
            }

            PublicDiskHardening::ensureHtaccess();

            if (PublicAssetDisk::resolve() === 'public') {
                PublicDiskHardeningProbe::run();
            } else {
                PublicDiskHardeningProbe::forget();
            }
        } catch (\Throwable $e) {
            Log::warning('공개 디스크 보호 규칙 점검에 실패했습니다.', ['error' => $e->getMessage()]);
        }
    }
}
