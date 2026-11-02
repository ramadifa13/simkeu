<?php

namespace Tests\Feature;

use Tests\TestCase;

class MemberDocumentTest extends TestCase
{
    public function test_member_document_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/anggota/1/dokumen/foto_ktp');

        $response->assertRedirect(route('login'));
    }
}
