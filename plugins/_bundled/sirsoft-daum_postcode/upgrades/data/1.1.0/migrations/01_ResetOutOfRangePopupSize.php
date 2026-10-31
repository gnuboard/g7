<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftDaumPostcode\V1_1_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 팝업 너비·높이 설정이 1.1.0 검증 범위(100~2000) 밖이면 기본값으로 되돌린다.
 *
 * 배경:
 *
 * 1.0.x 의 설정 저장은 팝업 크기의 정수 여부만 봐서 `-10` 이나 `99999` 도 저장됐다. 1.1.0 부터 코어 설정 저장
 * 검증이 스키마의 min·max 를 규칙으로 쓰므로, 그 값이 남아 있는 사이트는 환경설정 화면에서 **다른 항목을 저장할
 * 때도** 이 두 칸이 검증에 걸려 저장이 막힌다(데이터 손상은 없다). 범위 밖 값만 기본값으로 바꾼다.
 *
 * - 범위 안의 값(문자열 숫자 포함)은 그대로 둔다 — 운영자가 고른 크기다
 * - 키가 없으면 건드리지 않는다 — 조회가 스키마 기본값으로 폴백한다
 * - 숫자가 아니거나 범위 밖이면 기본값(너비 500, 높이 600 — 1.1.0 시점 값 동결)
 *
 * 멱등: 두 번째 실행에서는 범위 밖 값이 없어 아무것도 쓰지 않는다. 설정 파일이 없거나 JSON 이 깨졌으면 skip.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다)
 */
final class ResetOutOfRangePopupSize implements DataMigration
{
    /**
     * 1.1.0 검증 범위 (시점 값 동결).
     */
    private const MIN = 100;

    private const MAX = 2000;

    /**
     * 범위 밖일 때 되돌릴 기본값 (키 => 기본값, 1.1.0 시점 값 동결).
     *
     * @var array<string, int>
     */
    private const DEFAULTS = [
        'popup_width' => 500,
        'popup_height' => 600,
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'ResetOutOfRangePopupSize';
    }

    /**
     * 범위 밖 팝업 크기를 기본값으로 되돌린다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-daum_postcode] 설정 파일 없음 — 팝업 크기 보정 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-daum_postcode] 설정 JSON 형식 비정상 — 팝업 크기 보정 skip');

            return;
        }

        $reset = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $settings) || $this->isInRange($settings[$key])) {
                continue;
            }

            $reset[$key] = ['from' => $settings[$key], 'to' => $default];
            $settings[$key] = $default;
        }

        if ($reset === []) {
            $context->logger->info('[sirsoft-daum_postcode] 팝업 크기가 모두 범위 안 — 변경 없음');

            return;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-daum_postcode] 범위 밖 팝업 크기를 기본값으로 되돌렸습니다', [
            'reset' => $reset,
        ]);
    }

    /**
     * 값이 1.1.0 검증 범위 안의 정수인지 판정한다(숫자 문자열 포함).
     *
     * @param  mixed  $value  저장된 값
     * @return bool 범위 안이면 true
     */
    private function isInRange(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= self::MIN && $value <= self::MAX;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value >= self::MIN && (int) $value <= self::MAX;
        }

        return false;
    }
}
