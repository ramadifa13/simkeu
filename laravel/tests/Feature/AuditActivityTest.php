<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordActivity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('action', 80);
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('context')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function test_it_records_an_audit_event(): void
    {
        $request = Request::create('/portal/admin/anggota', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'SIMKEU test',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        app(RecordActivity::class)->handle(
            null,
            'member.created',
            'App\\Models\\Anggota',
            123,
            ['kode_anggota' => 'AGT-TEST'],
            $request,
        );

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'member.created',
            'auditable_type' => 'App\\Models\\Anggota',
            'auditable_id' => 123,
            'ip_address' => '127.0.0.1',
        ]);
    }
}
