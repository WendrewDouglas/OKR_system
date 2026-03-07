<?php
declare(strict_types=1);

namespace Tests\Unit\Acl;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ScopeCoversTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    public static function scopeProvider(): array
    {
        return [
            'SYS cobre ORG'     => ['SYS', 'ORG',  true],
            'SYS cobre UNIT'    => ['SYS', 'UNIT', true],
            'SYS cobre TEAM'    => ['SYS', 'TEAM', true],
            'SYS cobre OWN'     => ['SYS', 'OWN',  true],
            'SYS não cobre SYS' => ['SYS', 'SYS',  true],  // igual cobre
            'ORG cobre ORG'     => ['ORG', 'ORG',  true],
            'ORG não cobre SYS' => ['ORG', 'SYS',  false],
            'OWN não cobre ORG' => ['OWN', 'ORG',  false],
            'TEAM não cobre ORG'=> ['TEAM','ORG',  false],
        ];
    }

    #[DataProvider('scopeProvider')]
    public function testScopeCovers(string $granted, string $need, bool $expected): void
    {
        $this->assertSame($expected, scope_covers($granted, $need));
    }
}
