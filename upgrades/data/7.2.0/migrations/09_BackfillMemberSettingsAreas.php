<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Storage;

/**
 * 저장된 회원 설정의 표시 이름 영역 묶음에서 빠진 기본 영역을 채웁니다 (7.2.0).
 *
 * 설정 저장 파일은 기본값과 범주의 최상위 키 단위로만 합쳐진다. 그래서 회원 설정 파일에 `display_name_areas` 묶음이 이미
 * 있는 사이트(7.2.0 개발판을 설치해 회원 탭을 저장한 적이 있는 사이트)는 그 뒤 기본값에 더해진 영역이 묶음 안에 없다.
 * 설정을 읽는 쪽이 없는 영역을 영역의 선언 기본값으로 채워 읽으므로 동작은 같지만, 저장 파일을 기본값과 같은 모양으로
 * 맞춰 둔다.
 *
 * 하는 일: `member.json` 의 `display_name_areas` 가 배열일 때만, 아래 기본 영역 가운데 묶음에 없는 키를 기본값으로 더한다.
 * 이미 있는 영역의 값(운영자가 바꾼 방식과 종류)과 묶음의 다른 영역, 파일의 다른 키는 그대로 둔다.
 *
 * 아무것도 하지 않는 경우(모두 로그만 남긴다):
 *  - `member.json` 이 없다(7.1.x 에서 올린 사이트. 읽을 때 기본값이 쓰인다)
 *  - 파일이 JSON 객체가 아니다
 *  - `display_name_areas` 키가 없다(최상위 키 병합으로 기본값이 통째로 쓰인다)
 *  - 빠진 영역이 없다
 *
 * 멱등: 두 번째 실행에서는 빠진 영역이 없어 파일을 쓰지 않는다.
 *
 * V-1 안전 격리: Storage 파사드만 쓴다. 기본 영역 값은 7.2.0 값으로 동결한다(사이트와 관리자 화면은 「이름」 고정,
 * 이커머스 상품 문의는 「가린 이름」 고정 — 종전 표시와 같다).
 */
final class BackfillMemberSettingsAreas implements DataMigration
{
    /** 설정 범주 파일 (settings 디스크 기준) */
    private const MEMBER_SETTINGS_FILE = 'member.json';

    /** 영역 묶음 키 */
    private const AREAS_KEY = 'display_name_areas';

    /**
     * 7.2.0 기본 영역 값 (동결).
     *
     * @var array<string, array{mode: string, kind: string}>
     */
    private const DEFAULT_AREAS = [
        'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
        'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
        'sirsoft-ecommerce:inquiry' => ['mode' => 'fixed', 'kind' => 'masked_name'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillMemberSettingsAreas';
    }

    /**
     * 저장된 영역 묶음에 빠진 기본 영역을 더합니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $disk = Storage::disk('settings');
        if (! $disk->exists(self::MEMBER_SETTINGS_FILE)) {
            $context->logger->info('[7.2.0] member.json 없음 — 표시 이름 영역 보정 skip (읽을 때 기본값 사용)');

            return;
        }

        $settings = json_decode((string) $disk->get(self::MEMBER_SETTINGS_FILE), true);
        if (! is_array($settings) || array_is_list($settings)) {
            $context->logger->warning('[7.2.0] member.json 형식 비정상 — 표시 이름 영역 보정 skip');

            return;
        }

        if (! array_key_exists(self::AREAS_KEY, $settings)) {
            $context->logger->info('[7.2.0] member.json 에 표시 이름 영역 묶음 없음 — 변경 없음 (읽을 때 기본값 사용)');

            return;
        }

        $areas = $settings[self::AREAS_KEY];
        if (! is_array($areas) || ($areas !== [] && array_is_list($areas))) {
            $context->logger->warning('[7.2.0] member.json 의 표시 이름 영역 묶음 형식 비정상 — 보정 skip (값 유지)');

            return;
        }

        $missing = array_diff_key(self::DEFAULT_AREAS, $areas);
        if ($missing === []) {
            $context->logger->info('[7.2.0] 표시 이름 영역 묶음에 빠진 기본 영역 없음 — 변경 없음');

            return;
        }

        // 있는 영역은 그대로 두고 빠진 영역만 뒤에 더한다.
        $settings[self::AREAS_KEY] = $areas + $missing;
        $disk->put(
            self::MEMBER_SETTINGS_FILE,
            (string) json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $context->logger->info('[7.2.0] 저장된 표시 이름 영역 묶음에 빠진 기본 영역을 채웠습니다', [
            'added' => array_keys($missing),
        ]);
    }
}
