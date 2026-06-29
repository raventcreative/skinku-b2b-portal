<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LearningTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_youtube_id_parsing_and_embed(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
        ];
        foreach ($cases as $url => $id) {
            $l = new Lesson(['title' => 't', 'video_url' => $url]);
            $this->assertEquals($id, $l->youtubeId());
            $this->assertEquals("https://www.youtube.com/embed/{$id}", $l->embedUrl());
            $this->assertStringContainsString($id, $l->thumbnailUrl());
        }
    }

    public function test_manager_can_create_lesson(): void
    {
        $admin = $this->user(User::ROLE_ADMIN); // has manage_learning by default

        $this->actingAs($admin)->post('/learning', [
            'title' => 'Cara Pakai Portal',
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'category' => 'Onboarding',
            'audience' => [User::ROLE_DISTRIBUTOR],
            'is_published' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', ['title' => 'Cara Pakai Portal', 'created_by' => $admin->id]);
    }

    public function test_audience_targeting_controls_visibility(): void
    {
        $lesson = Lesson::create([
            'title' => 'Khusus Distributor', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'audience' => [User::ROLE_DISTRIBUTOR], 'is_published' => true,
        ]);

        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        $reseller = $this->user(User::ROLE_RESELLER);

        $this->assertTrue($lesson->visibleTo($dist));
        $this->assertFalse($lesson->visibleTo($reseller));

        $this->actingAs($dist)->get(route('learning.show', $lesson))->assertOk();
        $this->actingAs($reseller)->get(route('learning.show', $lesson))->assertForbidden();
    }

    public function test_lesson_without_audience_visible_to_all(): void
    {
        $lesson = Lesson::create(['title' => 'Umum', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_published' => true]);
        $this->assertTrue($lesson->visibleTo($this->user(User::ROLE_RESELLER)));
    }

    public function test_reseller_cannot_manage_learning_by_default(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))
            ->post('/learning', ['title' => 'x', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertForbidden();
    }

    public function test_learning_index_renders(): void
    {
        Lesson::create(['title' => 'Umum', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_published' => true]);
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))->get('/learning')->assertOk();
    }
}
