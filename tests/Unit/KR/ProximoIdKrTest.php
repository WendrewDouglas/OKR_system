<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProximoIdKrTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_helpers.php';
    }

    /**
     * SQLite in-memory com o mínimo de key_results. Não suporta FOR UPDATE,
     * então o helper roda aqui com o SELECT ... FOR UPDATE reescrito — por isso
     * o teste usa uma subclasse de PDO que remove a cláusula no prepare().
     */
    private function pdo(array $linhas): PDO
    {
        $pdo = new class('sqlite::memory:') extends PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE key_results (id_kr TEXT PRIMARY KEY, id_objetivo INTEGER, key_result_num INTEGER)');

        $ins = $pdo->prepare('INSERT INTO key_results (id_kr, id_objetivo, key_result_num) VALUES (?,?,?)');
        foreach ($linhas as [$idKr, $idObj, $num]) {
            $ins->execute([$idKr, $idObj, $num]);
        }
        return $pdo;
    }

    public function testFormatoObjetivoComDoisDigitos(): void
    {
        $this->assertSame('006-35', krh_formatar_id_kr(6, 35));
        $this->assertSame('001-05', krh_formatar_id_kr(1, 5));
        $this->assertSame('012-51', krh_formatar_id_kr(12, 51));
    }

    public function testFormatoObjetivoComTresDigitos(): void
    {
        $this->assertSame('003-120', krh_formatar_id_kr(3, 120));
    }

    public function testObjetivoVazioComecaEmUm(): void
    {
        [$num, $id] = krh_proximo_id_kr($this->pdo([]), 51);
        $this->assertSame(1, $num);
        $this->assertSame('001-51', $id);
    }

    public function testSequenciaNormal(): void
    {
        $pdo = $this->pdo([
            ['001-51', 51, 1],
            ['002-51', 51, 2],
        ]);
        [$num, $id] = krh_proximo_id_kr($pdo, 51);
        $this->assertSame(3, $num);
        $this->assertSame('003-51', $id);
    }

    public function testNumeracaoComBuracoNaoRecicla(): void
    {
        // 002 e 003 foram movidos/excluídos; o próximo continua depois do maior
        $pdo = $this->pdo([
            ['001-51', 51, 1],
            ['004-51', 51, 4],
        ]);
        [$num, $id] = krh_proximo_id_kr($pdo, 51);
        $this->assertSame(5, $num);
        $this->assertSame('005-51', $id);
    }

    /**
     * Regressão do bug: delete_kr.php renumerava os sobreviventes para 1..N sem
     * renomear o id_kr. Com num defasado do prefixo, MAX(num)+1 gerava um id que
     * já existia e o INSERT estourava com duplicate key.
     */
    public function testNumeracaoDefasadaNaoColide(): void
    {
        $pdo = $this->pdo([
            ['002-52', 52, 1],   // era num 2
            ['005-52', 52, 2],   // era num 5
        ]);

        [$num, $id] = krh_proximo_id_kr($pdo, 52);
        $this->assertSame(3, $num);
        $this->assertSame('003-52', $id);

        // simula a criação e pede o próximo: tem que PULAR o 005-52 ocupado
        $pdo->prepare('INSERT INTO key_results VALUES (?,?,?)')->execute([$id, 52, $num]);
        [$num2, $id2] = krh_proximo_id_kr($pdo, 52);
        $this->assertSame(4, $num2);
        $this->assertSame('004-52', $id2);

        $pdo->prepare('INSERT INTO key_results VALUES (?,?,?)')->execute([$id2, 52, $num2]);
        [$num3, $id3] = krh_proximo_id_kr($pdo, 52);
        $this->assertSame(6, $num3, 'deveria pular o 005-52, que já existe');
        $this->assertSame('006-52', $id3);
    }

    public function testNaoConfundeObjetivos(): void
    {
        $pdo = $this->pdo([
            ['001-50', 50, 1],
            ['002-50', 50, 2],
            ['001-51', 51, 1],
        ]);
        [, $id] = krh_proximo_id_kr($pdo, 51);
        $this->assertSame('002-51', $id);
    }

    public function testEstouraQuandoNaoHaIdLivre(): void
    {
        $linhas = [];
        for ($i = 1; $i <= 999; $i++) {
            $linhas[] = [krh_formatar_id_kr($i, 51), 51, $i];
        }
        $this->expectException(RuntimeException::class);
        krh_proximo_id_kr($this->pdo($linhas), 51);
    }
}
