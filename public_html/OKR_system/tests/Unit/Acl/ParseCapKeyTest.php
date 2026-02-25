<?php
declare(strict_types=1);

namespace Tests\Unit\Acl;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ParseCapKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    public static function capKeyProvider(): array
    {
        return [
            'formato completo'     => ['W:kr@ORG',     ['W', 'kr', 'ORG']],
            'manage com scope SYS' => ['M:user@SYS',   ['M', 'user', 'SYS']],
            'read com scope OWN'   => ['R:objetivo@OWN',['R', 'objetivo', 'OWN']],
            'sem scope'            => ['W:kr',          ['W', 'kr', '']],
            'sem action'           => ['kr@ORG',        ['', 'kr', 'ORG']],
            'apenas resource'      => ['kr',            ['', 'kr', '']],
            'com espaços'          => [' W : kr @ ORG ',['W', 'kr', 'ORG']],
            'lowercase action'     => ['w:kr@org',      ['W', 'kr', 'ORG']],
            'string vazia'         => ['',              ['', '', '']],
        ];
    }

    #[DataProvider('capKeyProvider')]
    public function testParseCapKey(string $input, array $expected): void
    {
        $result = parse_cap_key($input);
        $this->assertSame($expected, $result);
    }
}
