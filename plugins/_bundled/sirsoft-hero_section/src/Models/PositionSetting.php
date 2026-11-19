<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 위치별 슬라이더 설정 (자동 재생·전환 간격·전환 효과·표시 폭·메뉴 겹치기·페이징 모양·기기별 최대 높이)
 *
 * 행이 없으면 기본값(`DEFAULTS`)으로 응답하고 첫 저장 때 만든다.
 */
class PositionSetting extends Model
{
    /**
     * 행이 없을 때 쓰는 기본값
     *
     * @var array{autoplay: bool, interval_seconds: int, effect: string, width: string, header_overlay: bool, pagination: string, max_height_pc: int|null, max_height_mobile: int|null}
     */
    public const DEFAULTS = ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots', 'max_height_pc' => null, 'max_height_mobile' => null];

    /**
     * 허용 전환 간격(초)
     *
     * @var array<int, int>
     */
    public const INTERVALS = [3, 5, 7];

    /**
     * 기기별 최대 높이(px) 하한 — 이보다 낮으면 문구·넘김 단추가 들어갈 자리가 없다
     */
    public const MAX_HEIGHT_MIN = 160;

    /**
     * 기기별 최대 높이(px) 상한
     */
    public const MAX_HEIGHT_MAX = 2000;

    /**
     * 메뉴 겹치기에서 문구 자리가 좁아질 수 있다고 안내하는 최대 높이(px) — 문구가 메뉴 높이만큼 아래에서 시작하므로
     * 이보다 낮으면 관리 화면이 사이트에서 확인하라고 알린다(저장은 막지 않는다).
     */
    public const MAX_HEIGHT_OVERLAY_HINT = 320;

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
    protected $fillable = ['position', 'autoplay', 'interval_seconds', 'effect', 'width', 'header_overlay', 'pagination', 'max_height_pc', 'max_height_mobile', 'updated_by'];

    /**
     * 활동 로그 변경 추적 필드
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'autoplay' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.autoplay', 'type' => 'boolean'],
        'interval_seconds' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.interval_seconds', 'type' => 'number'],
        'effect' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.effect', 'type' => 'text'],
        'width' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.width', 'type' => 'text'],
        'header_overlay' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.header_overlay', 'type' => 'boolean'],
        'pagination' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.pagination', 'type' => 'text'],
        'max_height_pc' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.max_height_pc', 'type' => 'number'],
        'max_height_mobile' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.max_height_mobile', 'type' => 'number'],
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
            'header_overlay' => 'boolean',
            'max_height_pc' => 'integer',
            'max_height_mobile' => 'integer',
        ];
    }

    /**
     * 슬라이더 설정 값만 배열로 반환합니다.
     *
     * @return array{autoplay: bool, interval_seconds: int, effect: string, width: string, header_overlay: bool, pagination: string, max_height_pc: int|null, max_height_mobile: int|null} 설정 값
     */
    public function toSettings(): array
    {
        return [
            'autoplay' => (bool) $this->autoplay,
            'interval_seconds' => (int) $this->interval_seconds,
            'effect' => (string) $this->effect,
            'width' => (string) ($this->width ?? self::DEFAULTS['width']),
            'header_overlay' => (bool) ($this->header_overlay ?? self::DEFAULTS['header_overlay']),
            'pagination' => (string) ($this->pagination ?? self::DEFAULTS['pagination']),
            'max_height_pc' => $this->max_height_pc === null ? null : (int) $this->max_height_pc,
            'max_height_mobile' => $this->max_height_mobile === null ? null : (int) $this->max_height_mobile,
        ];
    }
}
