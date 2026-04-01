<?php

namespace Modules\Sirsoft\Page\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Page\Database\Factories\PageVersionFactory;

class PageVersion extends Model
{
    use HasFactory;

    /**
     * 팩토리 클래스를 반환합니다.
     */
    protected static function newFactory(): PageVersionFactory
    {
        return PageVersionFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'page_versions';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'page_id',
        'version',
        'title',
        'content',
        'content_mode',
        'seo_meta',
        'changes_summary',
        'created_by',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'array',
            'content' => 'array',
            'seo_meta' => 'array',
            'changes_summary' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * 페이지와의 관계를 정의합니다.
     *
     * @return BelongsTo<Page, PageVersion>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * 작성자와의 관계를 정의합니다.
     *
     * @return BelongsTo<User, PageVersion>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
