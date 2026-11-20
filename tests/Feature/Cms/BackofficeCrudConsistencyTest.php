<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\MultilingualEditorialComposition;
use Tests\TestCase;

class BackofficeCrudConsistencyTest extends TestCase
{
    use MultilingualEditorialComposition;
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        CmsFixtures::localization(['es', 'en', 'fr']);
        CmsFixtures::identities();
        CmsFixtures::home(['es', 'en', 'fr']);
        BlogFixtures::post(['es', 'en', 'fr']);

        /** @var User $admin */
        $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
        $this->adminUser = $admin;
    }

    public function test_all_admin_index_views_render_unified_datatable_header_and_structure(): void
    {
        $routes = [
            'admin.pages.index',
            'admin.blog.posts.index',
            'admin.blog.categories.index',
            'admin.galleries.index',
            'admin.forms.index',
            'admin.users.index',
        ];

        foreach ($routes as $routeName) {
            $response = $this->actingAs($this->adminUser, 'web')->get(route($routeName));
            $response->assertOk();
            $response->assertSee('cyber-card', false);
            $response->assertSee('name="search"', false);
            $response->assertSee('name="per_page"', false);
        }
    }

    public function test_blog_categories_use_the_shared_ux_and_components(): void
    {
        $blogRes = $this->actingAs($this->adminUser, 'web')->get(route('admin.blog.categories.index'));
        $blogRes->assertOk();
        $blogRes->assertSee('open-create-category-modal', false);
        $blogRes->assertSee('confirm-delete', false);

    }

    public function test_form_header_is_rendered_across_create_and_edit_views(): void
    {
        /** @var Page $page */
        $page = Page::firstOrFail();
        /** @var Post $post */
        $post = Post::firstOrFail();

        // Create forms
        $this->actingAs($this->adminUser, 'web')->get(route('admin.pages.create'))->assertSee('Volver al listado');
        $this->actingAs($this->adminUser, 'web')->get(route('admin.blog.posts.create'))->assertSee('Volver al listado');
        $this->actingAs($this->adminUser, 'web')->get(route('admin.galleries.create'))->assertSee('Volver al listado');

        // Edit forms
        $this->actingAs($this->adminUser, 'web')->get(route('admin.pages.edit', $page->id))->assertSee('Volver al listado');
        $this->actingAs($this->adminUser, 'web')->get(route('admin.blog.posts.edit', $post->id))->assertSee('Volver al listado');
    }
}
