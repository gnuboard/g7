<?php

namespace Tests\Unit\Notifications;

use Tests\TestCase;

/**
 * 코어 소유 알림 정의 타입 ↔ 관리자 화면 라벨 패리티 (#597 후속, 2026-08-24)
 *
 * 관리자 알림 설정 화면의 정의 목록은 제목을 DB `name` 이 아니라 프론트 다국어 키
 * `admin.settings.notification_definitions.types.{type}` 로 렌더한다. 이 네임스페이스의
 * 코어 소유분은 admin 템플릿(sirsoft-admin_basic)의 `lang/partial/{ko,en}/admin.json` 이
 * 공급한다(모듈 소유 타입은 각 모듈의 화면·lang 이 담당).
 *
 * 키가 없으면 예외도 경고도 없이 원시 키 문자열이 화면 제목으로 그대로 노출된다
 * (실사례: sitemap_regenerated / sitemap_regenerate_failed — #481 이 config 정의만 추가하고
 * 템플릿 라벨을 누락). config/core.php 에 정의를 추가하는 커밋이 라벨을 빠뜨리는 일이
 * 재발하지 않도록, 코어 정의 전수를 양 로케일 라벨과 대조한다.
 *
 * 마커는 로케일별 테스트 메서드 docblock 에 있다 (@scenario 는 docblock 당 1조합).
 */
class NotificationDefinitionTypeLabelParityTest extends TestCase
{
    /** 코어 소유 타입 라벨을 공급하는 admin 템플릿 lang partial */
    private const LABEL_FILES = [
        'ko' => 'templates/_bundled/sirsoft-admin_basic/lang/partial/ko/admin.json',
        'en' => 'templates/_bundled/sirsoft-admin_basic/lang/partial/en/admin.json',
    ];

    /**
     * 코어 정의 타입 전수가 ko 라벨을 가진다
     *
     * @scenario locale=ko
     *
     * @effects core_definition_types_have_admin_template_labels
     */
    public function test_every_core_definition_type_has_ko_label(): void
    {
        $this->assertLabelsCoverCoreTypes('ko');
    }

    /**
     * 코어 정의 타입 전수가 en 라벨을 가진다
     *
     * @scenario locale=en
     *
     * @effects core_definition_types_have_admin_template_labels
     */
    public function test_every_core_definition_type_has_en_label(): void
    {
        $this->assertLabelsCoverCoreTypes('en');
    }

    /**
     * 코어 정의 전수 ↔ 해당 로케일 라벨 대조
     *
     * @param  string  $locale  검사할 로케일 (ko|en)
     */
    private function assertLabelsCoverCoreTypes(string $locale): void
    {
        $types = array_keys((array) config('core.notification_definitions'));

        // 모집단 검증 — config 구조가 바뀌어 빈 배열이 되면 초록이 무의미하다.
        $this->assertGreaterThanOrEqual(
            5,
            count($types),
            'config/core.php notification_definitions 에서 코어 정의를 읽지 못했다 — 테스트 경로/구조 확인 필요'
        );

        $relPath = self::LABEL_FILES[$locale];
        $labels = $this->loadTypeLabels($relPath);

        $missing = array_values(array_filter(
            $types,
            fn (string $type) => ! array_key_exists($type, $labels)
                || ! is_string($labels[$type])
                || trim($labels[$type]) === ''
        ));

        $this->assertSame(
            [],
            $missing,
            "admin 템플릿 {$locale} 라벨 누락 — 알림 설정 화면 제목에 원시 키가 그대로 노출된다.\n"
            ."{$relPath} 의 settings.notification_definitions.types 에 다음 타입을 추가할 것:\n"
            .implode(', ', $missing)
        );
    }

    /**
     * partial admin.json 에서 타입 라벨 맵을 읽는다
     *
     * @param  string  $relPath  저장소 루트 기준 경로
     * @return array<string, mixed> 타입 → 라벨
     */
    private function loadTypeLabels(string $relPath): array
    {
        $path = base_path($relPath);
        $this->assertFileExists($path, "라벨 파일이 없다: {$relPath}");

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, "라벨 파일 JSON 파싱 실패: {$relPath}");

        $labels = $data['settings']['notification_definitions']['types'] ?? null;
        $this->assertIsArray(
            $labels,
            "settings.notification_definitions.types 경로가 없다: {$relPath} — 파일 구조가 바뀌었으면 본 테스트의 경로를 갱신할 것"
        );

        return $labels;
    }
}
