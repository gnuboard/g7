<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\DisplayNameSettings;
use App\Services\DisplayNameSettingsService as CoreDisplayNameSettingsService;
use App\Services\SettingsService as CoreSettingsService;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 쇼핑몰 환경설정의 기능별 작성자 표시 이름(상품 후기, 상품 문의)을 읽고 저장합니다.
 *
 * 값은 이커머스 설정 파일에 두지 않는다. 코어 설정 `member.display_name_areas` 의 두 영역 키
 * (`sirsoft-ecommerce:review`, `sirsoft-ecommerce:inquiry`)가 원본이다. 그래서 환경설정의 회원 탭에서 바꾼 값과
 * 쇼핑몰 환경설정에서 바꾼 값이 서로 어긋날 수 없고, 닉네임 항목 잠금과 추가 항목 삭제 차단도 코어가 같은 값으로 판정한다.
 *
 * | 기능 키 | 영역 | 칸이 있는 탭 |
 * | --- | --- | --- |
 * | `review` | `sirsoft-ecommerce:review` | 리뷰 설정 |
 * | `inquiry` | `sirsoft-ecommerce:inquiry` | 기본 정보 (상품 문의 구획) |
 *
 * @since 1.4.0
 */
class EcommerceDisplayNameSettingsService
{
    /**
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameSettings  $settings  표시 이름 설정 읽기
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기 (지금 적용되는 값)
     * @param  CoreDisplayNameSettingsService  $coreDisplayNames  코어 표시 이름 설정 서비스 (종류 미리보기, 저장 뒤 처리)
     * @param  CoreSettingsService  $coreSettings  코어 환경설정 서비스 (영역 설정 저장)
     */
    public function __construct(
        private DisplayNameAreaRegistry $areas,
        private DisplayNameKindRegistry $kinds,
        private DisplayNameSettings $settings,
        private DisplayNameResolver $resolver,
        private CoreDisplayNameSettingsService $coreDisplayNames,
        private CoreSettingsService $coreSettings,
    ) {}

    /**
     * 설정 화면이 읽는 두 묶음을 반환합니다.
     *
     * - `display_name`: 기능마다 영역에 지금 저장된 설정 `{mode, kind}` (`kind` 는 고정일 때만, 그 밖은 null). 화면의 폼 값이다.
     * - `display_name_options`: 읽기 전용. 고를 수 있는 종류 목록(미리보기 포함)과, 상속을 다 따라간 「지금 적용되는 값」.
     *
     * @return array{display_name: array<string, array{mode: string, kind: string|null}>, display_name_options: array{kinds: array<int, array<string, mixed>>, effective: array<string, array<string, mixed>>}} 화면 데이터
     */
    public function forAdmin(): array
    {
        $stored = [];
        $effective = [];

        foreach (MemberDisplayName::FEATURE_AREAS as $feature => $areaKey) {
            $area = $this->areas->get($areaKey);
            if ($area === null) {
                continue;
            }

            $stored[$feature] = $this->settings->areaSetting($area);

            $rule = $this->resolver->areaRule($areaKey);
            $effective[$feature] = [
                'mode' => $rule['mode'],
                'kind' => $rule['kind'],
                'kind_label' => $rule['kind'] !== null ? $this->kinds->get($rule['kind'])?->label() : null,
                // 그 값을 정한 영역의 이름 — 「상위 영역 따름」일 때 어느 설정을 따르고 있는지 화면이 곁들여 보인다
                'source_label' => $this->areas->get($rule['source'])?->label() ?? $area->label(),
            ];
        }

        return [
            'display_name' => $stored,
            'display_name_options' => [
                'kinds' => array_map(
                    static fn (array $kind): array => [
                        'key' => $kind['key'],
                        'label' => $kind['label'],
                        'preview' => $kind['preview'],
                        'requires_nickname' => $kind['requires_nickname'],
                    ],
                    $this->coreDisplayNames->options()['kinds'],
                ),
                'effective' => $effective,
            ],
        ];
    }

    /**
     * 기능별 표시 이름을 코어 영역 설정에 저장합니다.
     *
     * 영역 묶음은 저장할 때 통째로 바뀌므로, 지금 저장된 묶음 전체에 넘어온 기능의 영역만 덮어써서 넘긴다
     * (다른 영역과 꺼진 확장의 영역 값이 지워지지 않게). 넘어오지 않은 기능은 건드리지 않는다.
     * 방식이 고정이 아니면 종류를 저장하지 않는다.
     *
     * @param  array<string, array{mode?: string, kind?: string|null}>  $displayName  기능 키 => `{mode, kind}` (검증된 값)
     * @return bool 저장했으면 true (저장할 기능이 없으면 false)
     */
    public function save(array $displayName): bool
    {
        $areas = $this->settings->storedAreas();
        $changed = false;

        foreach (MemberDisplayName::FEATURE_AREAS as $feature => $areaKey) {
            $setting = $displayName[$feature] ?? null;
            if (! is_array($setting) || ! in_array($setting['mode'] ?? null, DisplayNameArea::MODES, true)) {
                continue;
            }

            $areas[$areaKey] = $setting['mode'] === DisplayNameArea::MODE_FIXED
                ? ['mode' => DisplayNameArea::MODE_FIXED, 'kind' => (string) ($setting['kind'] ?? '')]
                : ['mode' => $setting['mode']];
            $changed = true;
        }

        if (! $changed) {
            return false;
        }

        $this->coreSettings->setSetting(DisplayNameSettings::KEY_AREAS, $areas);

        // 닉네임 항목 잠금 판정을 다시 하게 하고, 작성자 이름이 구워진 검색 엔진용 화면을 비운다.
        $this->coreDisplayNames->afterSave(false);

        return true;
    }
}
