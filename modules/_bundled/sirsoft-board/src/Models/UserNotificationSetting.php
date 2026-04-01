<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSetting extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'board_user_notification_settings';

    /**
     * 기본키
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     */
    public $timestamps = true;

    /**
     * 대량 할당 가능한 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'notify_post_complete',
        'notify_post_reply',
        'notify_comment',
        'notify_reply_comment',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify_post_complete' => 'boolean',
            'notify_post_reply' => 'boolean',
            'notify_comment' => 'boolean',
            'notify_reply_comment' => 'boolean',
        ];
    }

    /**
     * 사용자 관계
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
