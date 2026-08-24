<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchSource;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;

/**
 * 비즈뿌리오 발송 이력 모델.
 *
 * 문자·알림톡 1건 발송마다 1행. 발송 시 pending 으로 생성되고 webhook 리포트로 상태가
 * 갱신된다. 발송 이력 화면(계획서 §6-5)의 데이터소스.
 *
 * @property int $id
 * @property string $refkey
 * @property string|null $messagekey
 * @property string $channel
 * @property string|null $media
 * @property string $to_number
 * @property string|null $to_name
 * @property int|null $to_user_id
 * @property string $content
 * @property array|null $request_payload
 * @property string|null $notification_type
 * @property int|null $notification_log_id
 * @property string $status
 * @property string|null $result_code
 * @property string|null $result_message
 * @property string|null $fallback_status
 * @property string $source
 * @property bool|null $is_test_mode
 * @property Carbon|null $sent_at
 * @property Carbon|null $reported_at
 * @property array|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class BizppurioDispatch extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'bizppurio_dispatches';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'refkey',
        'messagekey',
        'channel',
        'media',
        'to_number',
        'to_name',
        'to_user_id',
        'content',
        'request_payload',
        'notification_type',
        'notification_log_id',
        'status',
        'result_code',
        'result_message',
        'fallback_status',
        'source',
        'is_test_mode',
        'sent_at',
        'reported_at',
        'raw_payload',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DispatchChannel::class,
            'status' => DispatchStatus::class,
            'source' => DispatchSource::class,
            'to_user_id' => 'integer',
            'notification_log_id' => 'integer',
            'is_test_mode' => 'boolean',
            'sent_at' => 'datetime',
            'reported_at' => 'datetime',
            'request_payload' => 'array',
            'raw_payload' => 'array',
        ];
    }

    /**
     * 마스킹된 수신 전화번호를 반환합니다 (010-****-5678 형식).
     *
     * 가운데 4자리를 `*` 로 가린다. 뒤 4자리·앞 3자리는 노출한다. 전체보기는
     * 권한(messaging.view)이 있는 상세 화면에서 원본 to_number 로 별도 제공한다.
     *
     * @return string 마스킹된 번호
     */
    public function getMaskedNumberAttribute(): string
    {
        $digits = preg_replace('/[^0-9]/', '', $this->to_number) ?? '';
        $len = strlen($digits);

        if ($len < 7) {
            // 너무 짧으면 뒤 절반만 노출
            return str_repeat('*', (int) ceil($len / 2)).substr($digits, (int) ceil($len / 2));
        }

        $head = substr($digits, 0, $len - 8 > 0 ? 3 : $len - 4);
        $tail = substr($digits, -4);

        return $head.'-****-'.$tail;
    }

    /**
     * 회원 수신자 관계 (비회원이면 null).
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * refkey 로 조회하는 스코프 (webhook 매칭).
     *
     * @param  Builder  $query
     * @param  string  $refkey  우리 부여 키
     * @return Builder
     */
    public function scopeByRefkey(Builder $query, string $refkey): Builder
    {
        return $query->where('refkey', $refkey);
    }
}
