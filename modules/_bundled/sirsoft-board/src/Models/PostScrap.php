<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 게시글 스크랩(북마크) 모델
 *
 * board_post_scraps 테이블 사용. 회원이 저장한 게시글을 가리킨다.
 * post_id 는 FK 없이 앱 계층에서 무결성을 관리하므로 원글이 삭제돼도 레코드는 보존된다.
 */
class PostScrap extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'board_post_scraps';

    /**
     * updated_at 은 사용하지 않는다 (스크랩은 생성/삭제만 존재, 갱신 없음).
     *
     * @var bool
     */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'post_id',
        'board_id',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'post_id' => 'integer',
            'board_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 스크랩한 회원과의 관계.
     *
     * @return BelongsTo 회원 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 스크랩 대상 게시글과의 관계.
     *
     * post_id 는 FK 가 없어 원글이 소프트 삭제되면 이 관계는 null 이 된다
     * (마이페이지에서 "삭제된 게시글" 로 표시하기 위한 의도된 동작).
     *
     * @return BelongsTo 게시글 모델과의 관계
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * 스크랩 당시의 게시판과의 관계.
     *
     * @return BelongsTo 게시판 모델과의 관계
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }
}
