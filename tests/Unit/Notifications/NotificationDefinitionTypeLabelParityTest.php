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
     * 같은 라벨을 공급하는 **모든 채널**을 저장소에서 파생합니다.
     *
     * 위 `LABEL_FILES` 는 번들 원본 두 개뿐이라 ⓐ 화면이 실제로 읽는 **설치본**과
     * ⓑ ja 사이트가 읽는 **템플릿 언어팩**이 모집단 밖이었다. 그래서 번들만 채우고도
     * 초록이 나오고, 관리자 화면·일본어 사이트는 원시 키를 그대로 띄운다.
     *
     * 손으로 적지 않고 glob 으로 파생한다 — 로케일이나 언어팩이 하나 늘면 케이스를
     * 더하지 않아도 이 검사가 함께 넓어진다.
     *
     * @return array<string, string> 채널 이름 → 저장소 루트 기준 경로
     */
    private function labelChannels(): array
    {
        $channels = [];

        foreach (['templates/_bundled', 'templates'] as $root) {
            foreach (glob(base_path($root.'/sirsoft-admin_basic/lang/partial/*/admin.json')) ?: [] as $path) {
                $locale = basename(dirname($path));
                $channels[$root.':'.$locale] = $root.'/sirsoft-admin_basic/lang/partial/'.$locale.'/admin.json';
            }
        }

        $packGlob = 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-*/frontend/partial/admin.json';
        foreach (glob(base_path($packGlob)) ?: [] as $path) {
            $pack = basename(dirname(dirname(dirname($path))));
            $channels['langpack:'.$pack] = 'lang-packs/_bundled/'.$pack.'/frontend/partial/admin.json';
        }

        return $channels;
    }

    /**
     * 코어 정의 타입 전수가 ko 라벨을 가진다
     *
     * @scenario locale=ko
     *
     * @effects core_definition_types_have_admin_template_labels
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
     */
    public function test_every_core_definition_type_has_en_label(): void
    {
        $this->assertLabelsCoverCoreTypes('en');
    }

    /**
     * 라벨을 공급하는 **채널 전량**이 코어 정의 타입을 덮는지 확인합니다.
     *
     * 번들 원본만 재던 앞 두 메서드와 달리, 화면이 실제로 읽는 설치본과 ja 사이트가 읽는
     * 템플릿 언어팩까지 저장소에서 파생해 함께 대조한다 — 번들만 채우고 설치본·팩을
     * 빠뜨리면 게이트는 초록인데 관리자 화면에는 원시 키가 그대로 뜬다.
     *
     * @scenario locale=ko
     *
     * @effects every_label_channel_covers_core_definition_types
     *
     * @return void 반환값 없음
     */
    public function test_every_label_channel_covers_core_definition_types(): void
    {
        $types = $this->coreDefinitionTypes();
        $channels = $this->labelChannels();

        // 모집단이 비면 아래 루프가 0회 돌며 조용히 통과한다 — 그 자체를 잰다.
        $this->assertNotSame([], $channels, '라벨 공급 채널을 하나도 찾지 못했다 — glob 경로가 낡았다.');

        // 갈래별로 따로 잰다. 종전에는 `count($channels) >= 3` 하나로 묶었는데, 설치본은
        // `.gitignore` 로 저장소에서 제외돼 있어 **깨끗한 클론·CI 에서는 설치본이 0개여도**
        // 번들 ko·en + 언어팩 ja 셋만으로 하한 3을 채워 초록이 됐다. 이 검사가 닫겠다고
        // 선언한 결함(설치본에만 원시 키가 남는 것)이 바로 그 환경에서 측정되지 않았다.
        //
        // 설치본은 **있을 때만** 재는 것이 옳다 — 없는 환경에서 red 를 내면 CI 가 영구히
        // 빨갛다. 그러나 디렉터리가 있는데 채널이 0개로 잡히면 그것은 glob 이 낡았거나
        // 설치본 구조가 바뀐 것이므로 red 다.
        $this->assertNotSame(
            [],
            $this->channelsInGroup($channels, 'templates/_bundled:'),
            '번들 원본 채널을 하나도 찾지 못했다 — glob 경로가 낡았다.'
        );
        $this->assertNotSame(
            [],
            $this->channelsInGroup($channels, 'langpack:'),
            '템플릿 언어팩 채널을 하나도 찾지 못했다 — glob 경로가 낡았다.'
        );

        if (is_dir(base_path('templates/sirsoft-admin_basic/lang/partial'))) {
            $this->assertNotSame(
                [],
                $this->channelsInGroup($channels, 'templates:'),
                '설치본 디렉터리는 있는데 라벨 채널이 잡히지 않았다 — glob 경로가 낡았다.'
            );
        }

        $gaps = [];

        foreach ($channels as $channel => $relPath) {
            $labels = $this->loadTypeLabels($relPath);

            $missing = $this->missingTypes($types, $labels);

            if ($missing !== []) {
                $gaps[] = $channel.' ('.$relPath.') → '.implode(', ', $missing);
            }
        }

        $this->assertSame(
            [],
            $gaps,
            "알림 정의 타입 라벨이 일부 채널에만 있다 — 그 채널을 쓰는 화면은 제목에 원시 키를 띄운다.\n"
            .implode("\n", $gaps)
        );
    }

    /**
     * 채널 목록에서 한 갈래(번들·설치본·언어팩)에 속한 것만 골라냅니다.
     *
     * `labelChannels()` 가 붙이는 키 접두사로 가른다. 세 갈래의 접두사는 `templates/_bundled:`
     * (번들) · `templates:`(설치본) · `langpack:`(템플릿 언어팩)이고, 구분자가 각각 `/` 와 `:`
     * 라 서로의 앞부분이 되지 않는다 — 접두사 하나로 갈래가 정확히 갈린다.
     *
     * @param  array<string, string>  $channels  채널 이름 → 경로
     * @param  string  $prefix  갈래 접두사
     * @return array<string, string> 그 갈래의 채널만
     */
    private function channelsInGroup(array $channels, string $prefix): array
    {
        return array_filter(
            $channels,
            static fn (string $name): bool => str_starts_with($name, $prefix),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * 코어 소유 알림 정의 타입 전수를 config 에서 읽는다
     *
     * 본인인증 분류(`category = identity`, 7.1.0~ #699)는 뺀다 — 그 카드는 처음부터 서버가 주는 로케일 이름
     * (`$localized(def.name)`)으로 그리고, 운영자가 추가하는 정책별 문구(`identity.policy.{키}`)는 정적 라벨을
     * 가질 수 없어 이 네임스페이스로 덮을 수 없다. 이름의 다국어는 정의 선언과 언어팩 seed 가 공급한다.
     *
     * @return array<int, string> 타입 문자열 목록
     */
    private function coreDefinitionTypes(): array
    {
        $definitions = (array) config('core.notification_definitions');
        $types = array_keys(array_filter(
            $definitions,
            fn ($definition) => ($definition['category'] ?? null) !== 'identity'
        ));

        // 모집단 검증 — config 구조가 바뀌어 빈 배열이 되면 초록이 무의미하다.
        $this->assertGreaterThanOrEqual(
            5,
            count($types),
            'config/core.php notification_definitions 에서 코어 정의를 읽지 못했다 — 테스트 경로/구조 확인 필요'
        );

        return $types;
    }

    /**
     * 라벨 맵에서 비어 있는 타입을 가려낸다
     *
     * @param  array<int, string>  $types  코어 정의 타입 전수
     * @param  array<string, mixed>  $labels  타입 → 라벨
     * @return array<int, string> 라벨이 없거나 빈 타입
     */
    private function missingTypes(array $types, array $labels): array
    {
        return array_values(array_filter(
            $types,
            fn (string $type) => ! array_key_exists($type, $labels)
                || ! is_string($labels[$type])
                || trim($labels[$type]) === ''
        ));
    }

    /**
     * 코어 정의 전수 ↔ 해당 로케일 라벨 대조
     *
     * @param  string  $locale  검사할 로케일 (ko|en)
     * @return void 반환값 없음
     */
    private function assertLabelsCoverCoreTypes(string $locale): void
    {
        $types = $this->coreDefinitionTypes();

        $relPath = self::LABEL_FILES[$locale];
        $labels = $this->loadTypeLabels($relPath);

        $missing = $this->missingTypes($types, $labels);

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
