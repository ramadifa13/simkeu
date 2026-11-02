<?php

namespace Tests\Feature;

use Tests\TestCase;

class MemberListTest extends TestCase
{
    public function test_member_list_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/anggota');

        $response->assertRedirect(route('login'));
    }

    public function test_create_member_page_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/anggota/tambah');

        $response->assertRedirect(route('login'));
    }
}
