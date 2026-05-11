<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * sirsoft-board 모듈의 Board 모델 테스트
 */
#[Group('module-dependent')]
class BoardModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Board 모델 클래스명
     */
    private string $boardClass = 'Modules\\Sirsoft\\Board\\Models\\Board';

    /**
     * 테스트 환경 설정
     */
    #[Group('module')]
    protected function setUp(): void
    {
        parent::setUp();

        // Board 클래스가 로드되어 있지 않으면 테스트 스킵
        if (! class_exists($this->boardClass)) {
            $this->markTestSkipped('sirsoft-board 모듈이 설치되어 있지 않습니다.');
        }

        // 모듈 마이그레이션 실행
        $this->artisan('migrate', [
            '--path' => 'modules/sirsoft-board/database/migrations',
            '--realpath' => true,
        ]);
    }

    /**
     * Board 모델 인스턴스 생성
     *
     * @param array $data 생성 데이터
     * @return mixed Board 모델 인스턴스
     */
    private function createBoard(array $data): mixed
    {
        return $this->boardClass::create($data);
    }

    /**
     * fillable 속성이 정상적으로 할당되는지 테스트
     */
    public function test_fillable_attributes_are_assignable(): void
    {
        // Arrange
        $data = [
            'name' => ['ko' => '공지사항', 'en' => 'Notice Board'],
            'slug' => 'notice',
            'per_page' => 20,
            'per_page_mobile' => 15,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'type' => 'basic',
            'categories' => ['공지사항', '자유게시판'],
            'show_view_count' => true,
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => true,
            'use_report' => false,
            'min_title_length' => 2,
            'max_title_length' => 200,
            'min_content_length' => 10,
            'max_content_length' => 10000,
            'min_comment_length' => 2,
            'max_comment_length' => 1000,
            'use_file_upload' => false,
            'max_file_size' => 10485760,
            'max_file_count' => 5,
            'allowed_extensions' => ['jpg', 'png', 'pdf'],
            'comment_order' => 'ASC',
            'notify_author' => false,
            'notify_admin_on_post' => false,
            'blocked_keywords' => ['금지어1', '금지어2'],
        ];

        // Act
        $board = $this->createBoard($data);

        // Assert
        $this->assertInstanceOf($this->boardClass, $board);
        $this->assertEquals('notice', $board->slug);
        $this->assertEquals(20, $board->per_page);
        $this->assertEquals('basic', $board->type);
    }

    /**
     * name 필드가 배열로 캐스팅되는지 테스트
     */
    public function test_name_is_cast_to_array(): void
    {
        // Arrange
        $nameData = [
            'ko' => '공지사항',
            'en' => 'Notice Board',
        ];

        // Act
        $board = $this->createBoard([
            'name' => $nameData,
            'slug' => 'notice',
        ]);

        // Assert
        $this->assertIsArray($board->name);
        $this->assertEquals($nameData, $board->name);
    }

    /**
     * categories 필드가 배열로 캐스팅되는지 테스트
     */
    public function test_categories_is_cast_to_array(): void
    {
        // Arrange
        $categories = ['공지사항', '자유게시판', '질문답변'];

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
            'categories' => $categories,
        ]);

        // Assert
        $this->assertIsArray($board->categories);
        $this->assertEquals($categories, $board->categories);
    }

    /**
     * allowed_extensions 필드가 배열로 캐스팅되는지 테스트
     */
    public function test_allowed_extensions_is_cast_to_array(): void
    {
        // Arrange
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'];

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
            'allowed_extensions' => $extensions,
        ]);

        // Assert
        $this->assertIsArray($board->allowed_extensions);
        $this->assertEquals($extensions, $board->allowed_extensions);
    }

    /**
     * blocked_keywords 필드가 배열로 캐스팅되는지 테스트
     */
    public function test_blocked_keywords_is_cast_to_array(): void
    {
        // Arrange
        $keywords = ['금지어1', '금지어2', '금지어3'];

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
            'blocked_keywords' => $keywords,
        ]);

        // Assert
        $this->assertIsArray($board->blocked_keywords);
        $this->assertEquals($keywords, $board->blocked_keywords);
    }

    /**
     * boolean 필드가 정상적으로 캐스팅되는지 테스트
     *
     * 참고: `notify_author_on_comment` 은 `notify_author` 로 필드 리네임됨 (모듈 리팩토링)
     */
    public function test_boolean_fields_are_cast_correctly(): void
    {
        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
            'show_view_count' => true,
            'use_comment' => false,
            'use_reply' => true,
            'use_report' => false,
            'use_file_upload' => true,
            'notify_author' => false,
            'notify_admin_on_post' => true,
        ]);

        // Assert
        $this->assertIsBool($board->show_view_count);
        $this->assertTrue($board->show_view_count);
        $this->assertFalse($board->use_comment);
        $this->assertTrue($board->use_reply);
        $this->assertFalse($board->use_report);
        $this->assertTrue($board->use_file_upload);
        $this->assertFalse($board->notify_author);
        $this->assertTrue($board->notify_admin_on_post);
    }

    // 참고: `board_manager_id` 컬럼 및 `boardManager()` 관계는
    // 모듈 리팩토링으로 제거됨 (게시판 매니저는 Role/Permission 기반으로 이행).
    // 따라서 `test_belongs_to_board_manager` 는 삭제됨.

    /**
     * creator 관계 테스트
     */
    public function test_belongs_to_creator(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
        ]);
        $board->created_by = $user->id;
        $board->save();

        // Assert
        $board->refresh();
        $this->assertInstanceOf(User::class, $board->creator);
        $this->assertEquals($user->id, $board->creator->id);
    }

    /**
     * updater 관계 테스트
     */
    public function test_belongs_to_updater(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
        ]);
        $board->updated_by = $user->id;
        $board->save();

        // Assert
        $board->refresh();
        $this->assertInstanceOf(User::class, $board->updater);
        $this->assertEquals($user->id, $board->updater->id);
    }

    /**
     * getLocalizedName 메서드 테스트 (한국어)
     */
    public function test_get_localized_name_returns_korean_name(): void
    {
        // Arrange
        $board = $this->createBoard([
            'name' => [
                'ko' => '공지사항',
                'en' => 'Notice Board',
            ],
            'slug' => 'notice',
        ]);

        // Act
        $localizedName = $board->getLocalizedName('ko');

        // Assert
        $this->assertEquals('공지사항', $localizedName);
    }

    /**
     * getLocalizedName 메서드 테스트 (영어)
     */
    public function test_get_localized_name_returns_english_name(): void
    {
        // Arrange
        $board = $this->createBoard([
            'name' => [
                'ko' => '공지사항',
                'en' => 'Notice Board',
            ],
            'slug' => 'notice',
        ]);

        // Act
        $localizedName = $board->getLocalizedName('en');

        // Assert
        $this->assertEquals('Notice Board', $localizedName);
    }

    /**
     * getLocalizedName 메서드 테스트 (fallback)
     */
    public function test_get_localized_name_returns_fallback_when_locale_not_found(): void
    {
        // Arrange
        $board = $this->createBoard([
            'name' => [
                'ko' => '공지사항',
                'en' => 'Notice Board',
            ],
            'slug' => 'notice',
        ]);

        // Act
        $localizedName = $board->getLocalizedName('ja'); // 존재하지 않는 로케일

        // Assert
        // fallback_locale이 'ko'이므로 한국어 이름이 반환되어야 함
        $this->assertEquals('공지사항', $localizedName);
    }

    /**
     * localizedName Attribute 테스트
     */
    public function test_localized_name_attribute_returns_current_locale_name(): void
    {
        // Arrange
        app()->setLocale('ko');
        $board = $this->createBoard([
            'name' => [
                'ko' => '공지사항',
                'en' => 'Notice Board',
            ],
            'slug' => 'notice',
        ]);

        // Act
        $localizedName = $board->localized_name;

        // Assert
        $this->assertEquals('공지사항', $localizedName);
    }

    /**
     * created_by / updated_by 가 fillable 로 설정되어 명시 할당 시 저장된다.
     *
     * Board model 은 created_by/updated_by 를 $fillable 에 포함해
     * 서비스 계층에서 명시적으로 제공할 수 있도록 설계됨.
     * (auto-fill trait 은 부재 — 서비스에서 Auth::id() 등 책임 주입)
     */
    public function test_created_by_and_updated_by_are_fillable(): void
    {
        // Arrange: FK 제약 통과를 위해 실제 User 생성
        $creator = \App\Models\User::factory()->create();

        // Act
        $board = $this->createBoard([
            'name' => ['ko' => '게시판'],
            'slug' => 'test-board',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        // Assert
        $this->assertSame($creator->id, $board->created_by);
        $this->assertSame($creator->id, $board->updated_by);
    }
}
