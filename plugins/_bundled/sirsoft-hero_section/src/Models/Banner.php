<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Plugins\Sirsoft\HeroSection\Database\Factories\BannerFactory;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Enums\DisplayPeriod;

/**
 * 히어로섹션 배너
 *
 * 노출 상태는 컬럼이 아니다 — `statusAt()` 가 사용 여부·기간으로 그 시각의 상태를 계산한다.
 * 목록 필터·정렬·공개 노출은 같은 규칙을 Repository 의 SQL 조건으로 쓴다(두 판정이 같은지 테스트로 잠근다).
 */
class Banner extends Model
{
    use HasFactory;

    /** 기기별 중앙 초점·배경 없음이 기존 배너의 기본 표시입니다. */
    public const DEFAULT_PRESENTATION = ['pc_x' => 50, 'pc_y' => 50, 'mobile_x' => 50, 'mobile_y' => 50, 'overlay' => 'none'];

    /** 이미지 내부 초점의 백분율 범위입니다. */
    public const FOCUS_MIN = 0;

    public const FOCUS_MAX = 100;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'hero_section_banners';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'position', 'is_active', 'sort_order', 'display_period', 'starts_at', 'ends_at',
        'title', 'description', 'button_text', 'image_alt',
        'title_visible', 'title_color', 'title_align',
        'description_visible', 'description_color', 'description_align',
        'link_url', 'link_new_window', 'presentation', 'created_by', 'updated_by',
    ];

    /**
     * 활동 로그 변경 추적 필드
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'position' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.position', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.is_active', 'type' => 'boolean'],
        'display_period' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.display_period', 'type' => 'text'],
        'starts_at' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.starts_at', 'type' => 'datetime'],
        'ends_at' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.ends_at', 'type' => 'datetime'],
        'title' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.title', 'type' => 'text'],
        'link_url' => ['label_key' => 'sirsoft-hero_section::activity_log.fields.link_url', 'type' => 'text'],
    ];

    /**
     * 속성 캐스팅을 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'title' => 'array',
            'description' => 'array',
            'button_text' => 'array',
            'image_alt' => 'array',
            'title_visible' => 'boolean',
            'description_visible' => 'boolean',
            'link_new_window' => 'boolean',
            'presentation' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * 팩토리 인스턴스를 반환합니다.
     *
     * @return BannerFactory 배너 팩토리
     */
    protected static function newFactory(): BannerFactory
    {
        return BannerFactory::new();
    }

    /**
     * 이 배너의 이미지(PC·모바일)를 반환합니다.
     *
     * @return HasMany 이미지 관계
     */
    public function images(): HasMany
    {
        return $this->hasMany(BannerImage::class, 'banner_id');
    }

    /**
     * PC 이미지를 반환합니다.
     *
     * @return HasOne PC 이미지 관계
     */
    public function pcImage(): HasOne
    {
        return $this->hasOne(BannerImage::class, 'banner_id')->where('device', BannerDevice::Pc->value);
    }

    /**
     * 모바일 이미지를 반환합니다.
     *
     * @return HasOne 모바일 이미지 관계
     */
    public function mobileImage(): HasOne
    {
        return $this->hasOne(BannerImage::class, 'banner_id')->where('device', BannerDevice::Mobile->value);
    }

    /**
     * 주어진 시각의 노출 상태를 계산합니다 — 비활성 > 예약 > 종료 > 노출중 순.
     *
     * 경계: 시작 시각 정각부터 노출, 종료 시각 정각까지 노출(그 다음부터 종료). SQL 쪽 조건과 같다.
     *
     * @param  CarbonInterface|null  $now  기준 시각 (null 이면 지금, UTC)
     * @return BannerStatus 노출 상태
     */
    public function statusAt(?CarbonInterface $now = null): BannerStatus
    {
        return BannerStatus::resolve(
            (bool) $this->is_active,
            (string) ($this->display_period ?? DisplayPeriod::Always->value),
            $this->starts_at,
            $this->ends_at,
            $now ?? now(),
        );
    }
}
