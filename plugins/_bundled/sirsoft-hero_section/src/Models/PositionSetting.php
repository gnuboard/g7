<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 위치별 슬라이더 설정 (자동 재생·전환 간격·전환 효과)
 *
 * 행이 없으면 기본값(`DEFAULTS`)으로 응답하고 첫 저장 때 만든다.
 */
class PositionSetting extends Model
{
    /**
     * 행이 없을 때 쓰는 기본값
     *
     * @var array{autoplay: bool, interval_seconds: int, effect: string}
     */
    public const DEFAULTS = ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide'];

    /**
     * 허용 전환 간격(초)
     *
     * @var array<int, int>
     */
    public const INTERVALS = [3, 5, 7];

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'hero_section_position_settings';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = ['position', 'autoplay', 'interval_seconds', 'effect', 'updated_by'];

    /**
     * 활동 로그 변경 추적 필드
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'autoplay' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.autoplay', 'type' => 'boolean'],
        'interval_seconds' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.interval_seconds', 'type' => 'number'],
        'effect' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.effect', 'type' => 'text'],
    ];

    /**
     * 속성 캐스팅을 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'autoplay' => 'boolean',
            'interval_seconds' => 'integer',
        ];
    }

    /**
     * 슬라이더 설정 값만 배열로 반환합니다.
     *
     * @return array{autoplay: bool, interval_seconds: int, effect: string} 설정 값
     */
    public function toSettings(): array
    {
        return [
            'autoplay' => (bool) $this->autoplay,
            'interval_seconds' => (int) $this->interval_seconds,
            'effect' => (string) $this->effect,
        ];
    }
}
