<?php
declare(strict_types=1);

namespace Tests\Unit\Acl;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ActionMatchesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    public static function matchProvider(): array
    {
        return [
            'M cobre R'         => ['M', 'R', true],
            'M cobre W'         => ['M', 'W', true],
            'M cobre M'         => ['M', 'M', true],
            'W cobre R'         => ['W', 'R', true],
            'W cobre W'         => ['W', 'W', true],
            'R cobre R'         => ['R', 'R', true],
            'R não cobre W'     => ['R', 'W', false],
            'R não cobre M'     => ['R', 'M', false],
            'W não cobre M'     => ['W', 'M', false],
        ];
    }

    #[DataProvider('matchProvider')]
    public function testActionMatches(string $granted, string $need, bool $expected): void
    {
        $this->assertSame($expected, action_matches($granted, $need));
    }
}
