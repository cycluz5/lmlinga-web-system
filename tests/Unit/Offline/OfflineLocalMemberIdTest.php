<?php

namespace Tests\Unit\Offline;

use App\Support\Offline\OfflineLocalMemberId;
use PHPUnit\Framework\TestCase;

class OfflineLocalMemberIdTest extends TestCase
{
    public function test_local_ids_keep_url_case(): void
    {
        $this->assertTrue(OfflineLocalMemberId::isLocal('MB-L-abc12'));
        $this->assertSame('MB-L-abc12', OfflineLocalMemberId::normalize('MB-L-abc12'));
        $this->assertSame('MB-L-ABC12', OfflineLocalMemberId::normalize('MB-L-ABC12'));
    }

    public function test_server_member_ids_are_uppercased(): void
    {
        $this->assertFalse(OfflineLocalMemberId::isLocal('MB-044'));
        $this->assertSame('MB-044', OfflineLocalMemberId::normalize('mb-044'));
    }
}
