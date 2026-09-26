<?php

namespace Plugins\Sirsoft\Gdpr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;

/**
 * 개인정보 통지 이력. 접수와 결과는 각각 INSERT하며 기존 이벤트를 덮어쓰지 않습니다.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $attempt_id
 * @property PrivacyNoticeStatus $status
 * @property array $channels
 * @property bool $is_test
 * @property array $snapshot
 */
class GdprPrivacyNoticeLog extends Model
{
    protected $table = 'gdpr_privacy_notice_logs';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'attempt_id', 'status', 'channels', 'is_test', 'sent_at', 'snapshot'];

    /**
     * 이력의 JSON·상태·시간 타입을 반환합니다.
     *
     * @return array 처리 결과
     */
    protected function casts(): array
    {
        return ['status' => PrivacyNoticeStatus::class, 'channels' => 'array', 'is_test' => 'boolean', 'snapshot' => 'array', 'sent_at' => 'datetime'];
    }

    /**
     * 완전 삭제 후에는 수신자 관계가 비어 있습니다.
     *
     * @return BelongsTo 처리 결과
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
