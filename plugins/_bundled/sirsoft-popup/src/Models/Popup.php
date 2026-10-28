<?php

namespace Plugins\Sirsoft\Popup\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Plugins\Sirsoft\Popup\Database\Factories\PopupFactory;
use Plugins\Sirsoft\Popup\Enums\DisplayPeriod;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Enums\PopupStatus;

/**
 * 팝업
 *
 * 노출 상태는 컬럼이 아니다 — `statusAt()` 가 사용 여부·기간으로 그 시각의 상태를 계산한다.
 * 목록 필터·정렬·공개 노출은 같은 규칙을 Repository 의 SQL 조건으로 쓴다(두 판정이 같은지 테스트로 잠근다).
 * 노출 페이지·기기·「보지 않기」는 사용자 브라우저가 판정한다(SPA 경로 이동마다 서버에 묻지 않으려고).
 */
class Popup extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'popups';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title', 'is_active', 'sort_order', 'content_type', 'display_period', 'starts_at', 'ends_at',
        'page_scope', 'routes', 'audience', 'device', 'image_alt', 'link_url', 'link_new_window',
        'body_html', 'created_by', 'updated_by',
    ];

    /**
     * 활동 로그 변경 추적 필드
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'title' => ['label_key' => 'sirsoft-popup::activity_log.fields.title', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-popup::activity_log.fields.is_active', 'type' => 'boolean'],
        'content_type' => ['label_key' => 'sirsoft-popup::activity_log.fields.content_type', 'type' => 'text'],
        'display_period' => ['label_key' => 'sirsoft-popup::activity_log.fields.display_period', 'type' => 'text'],
        'starts_at' => ['label_key' => 'sirsoft-popup::activity_log.fields.starts_at', 'type' => 'datetime'],
        'ends_at' => ['label_key' => 'sirsoft-popup::activity_log.fields.ends_at', 'type' => 'datetime'],
        'page_scope' => ['label_key' => 'sirsoft-popup::activity_log.fields.page_scope', 'type' => 'text'],
        'audience' => ['label_key' => 'sirsoft-popup::activity_log.fields.audience', 'type' => 'text'],
        'device' => ['label_key' => 'sirsoft-popup::activity_log.fields.device', 'type' => 'text'],
        'link_url' => ['label_key' => 'sirsoft-popup::activity_log.fields.link_url', 'type' => 'text'],
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
            'routes' => 'array',
            'image_alt' => 'array',
            'body_html' => 'array',
            'link_new_window' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * 팩토리 인스턴스를 반환합니다.
     *
     * @return PopupFactory 팝업 팩토리
     */
    protected static function newFactory(): PopupFactory
    {
        return PopupFactory::new();
    }

    /**
     * 이 팝업의 이미지(PC·모바일)를 반환합니다.
     *
     * @return HasMany 이미지 관계
     */
    public function images(): HasMany
    {
        return $this->hasMany(PopupImage::class, 'popup_id');
    }

    /**
     * PC 이미지를 반환합니다.
     *
     * @return HasOne PC 이미지 관계
     */
    public function pcImage(): HasOne
    {
        return $this->hasOne(PopupImage::class, 'popup_id')->where('device', ImageDevice::Pc->value);
    }

    /**
     * 모바일 이미지를 반환합니다.
     *
     * @return HasOne 모바일 이미지 관계
     */
    public function mobileImage(): HasOne
    {
        return $this->hasOne(PopupImage::class, 'popup_id')->where('device', ImageDevice::Mobile->value);
    }

    /**
     * 주어진 시각의 노출 상태를 계산합니다 — 비활성 > 예약 > 종료 > 노출중 순.
     *
     * @param  CarbonInterface|null  $now  기준 시각 (null 이면 지금, UTC)
     * @return PopupStatus 노출 상태
     */
    public function statusAt(?CarbonInterface $now = null): PopupStatus
    {
        return PopupStatus::resolve(
            (bool) $this->is_active,
            (string) ($this->display_period ?? DisplayPeriod::Always->value),
            $this->starts_at,
            $this->ends_at,
            $now ?? now(),
        );
    }
}
