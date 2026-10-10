<?php

namespace App\Models\Analytics;

use App\Casts\IpBinary;
use App\Enums\Analytics\DeviceType;
use App\Enums\Analytics\ReferrerType;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use Database\Factories\Analytics\AnalyticsVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 방문 원장 모델
 *
 * 방문 1건을 나타내며, 방문이 이어지는 동안 액션 수·체류 시간·이탈 페이지가 계속
 * 갱신되는 유일한 표입니다. user_id 는 외래키가 아니라 단순 숫자 컬럼입니다 —
 * 회원이 탈퇴해도 과거 통계가 사라지거나 탈퇴가 막히면 안 되기 때문입니다.
 *
 * @property int $id
 * @property string $visitor_key
 * @property string $visitor_key_type
 * @property VisitorType $visitor_type
 * @property int|null $user_id
 * @property string|null $user_hash
 * @property Carbon $visit_date
 * @property Carbon $first_action_at
 * @property Carbon $last_action_at
 * @property int $actions_count
 * @property int $pageviews_count
 * @property int $total_time_seconds
 * @property bool $is_bounce
 * @property int|null $entry_action_name_id
 * @property int|null $exit_action_name_id
 * @property VisitSource $source
 * @property string|null $ip
 * @property string|null $ip_hash
 * @property string|null $country_code
 * @property string|null $region_code
 * @property string|null $city
 * @property string $browser_name
 * @property string|null $browser_version
 * @property string $os_name
 * @property string|null $os_version
 * @property DeviceType $device_type
 * @property string|null $screen_resolution
 * @property string|null $browser_language
 * @property string|null $locale
 * @property ReferrerType $referrer_type
 * @property string|null $referrer_name
 * @property string|null $referrer_url
 * @property string|null $referrer_keyword
 * @property string|null $campaign_name
 * @property string|null $campaign_source
 * @property string|null $campaign_medium
 * @property bool|null $is_returning
 * @property array<string, mixed>|null $custom_dimensions
 */
class AnalyticsVisit extends Model
{
    /** @use HasFactory<AnalyticsVisitFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_visits';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'visitor_key',
        'visitor_key_type',
        'visitor_type',
        'user_id',
        'user_hash',
        'visit_date',
        'first_action_at',
        'last_action_at',
        'actions_count',
        'pageviews_count',
        'total_time_seconds',
        'is_bounce',
        'entry_action_name_id',
        'exit_action_name_id',
        'source',
        'ip',
        'ip_hash',
        'country_code',
        'region_code',
        'city',
        'browser_name',
        'browser_version',
        'os_name',
        'os_version',
        'device_type',
        'screen_resolution',
        'browser_language',
        'locale',
        'referrer_type',
        'referrer_name',
        'referrer_url',
        'referrer_keyword',
        'campaign_name',
        'campaign_source',
        'campaign_medium',
        'is_returning',
        'custom_dimensions',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'visitor_type' => VisitorType::class,
            'source' => VisitSource::class,
            'device_type' => DeviceType::class,
            'referrer_type' => ReferrerType::class,
            'visit_date' => 'date',
            'first_action_at' => 'datetime',
            'last_action_at' => 'datetime',
            'actions_count' => 'integer',
            'pageviews_count' => 'integer',
            'total_time_seconds' => 'integer',
            'is_bounce' => 'boolean',
            'is_returning' => 'boolean',
            'ip' => IpBinary::class,
            'custom_dimensions' => 'array',
        ];
    }

    /**
     * 이 방문에 속한 액션 목록
     *
     * 외래키를 두지 않으므로 관계도 로컬 키로 연결합니다.
     *
     * @return HasMany<AnalyticsAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AnalyticsAction::class, 'visit_id');
    }

    /**
     * 이 방문에서 일어난 전환 목록
     *
     * @return HasMany<AnalyticsConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(AnalyticsConversion::class, 'visit_id');
    }
}
