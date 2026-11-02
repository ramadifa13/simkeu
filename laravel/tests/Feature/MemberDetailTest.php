<?php

namespace Tests\Feature;

use Tests\TestCase;

class MemberDetailTest extends TestCase
{
    public function test_member_detail_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/anggota/1');

        $response->assertRedirect(route('login'));
    }

    public function test_edit_member_page_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/anggota/1/edit');

        $response->assertRedirect(route('login'));
    }
}
