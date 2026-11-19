<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 동적 위치 대상별 슬라이더 설정 재정의
 *
 * `overrides` 에는 대상이 명시한 항목만 담는다 — 키가 없으면 위치 기본값을 상속하고, `false`·`null` 은 명시값이다.
 */
class TargetSetting extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'hero_section_target_settings';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = ['position', 'target_key', 'overrides', 'updated_by'];

    /**
     * 활동 로그 변경 추적 필드 — 재정의 묶음 전체를 한 항목으로 본다
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'overrides' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.overrides', 'type' => 'json'],
    ];

    /**
     * 속성 캐스팅을 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'overrides' => 'array',
        ];
    }

    /**
     * 재정의 항목만 설정 기본값 순서로 반환합니다 — 알 수 없는 키는 버리고(저장 규칙이 막지만 옛 행·직접 수정에 대비), DB JSON 이 바꾼 키
     * 순서를 되돌려 응답·비교가 늘 같은 모양이다. `null` 명시값도 키가 있으면 남긴다.
     *
     * @return array<string, mixed> 항목 => 명시값
     */
    public function overrideValues(): array
    {
        $values = is_array($this->overrides) ? $this->overrides : [];
        $ordered = [];
        foreach (array_keys(PositionSetting::DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $ordered[$key] = $values[$key];
            }
        }

        return $ordered;
    }
}
