<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;

class ValidarObrigatoriosTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_helpers.php';
    }

    private function basePost(): array
    {
        return [
            'id_objetivo'                => '1',
            'descricao'                  => 'Aumentar receita em 20%',
            'ciclo_tipo'                 => 'anual',
            'ciclo_anual_ano'            => '2025',
            'baseline'                   => '0',
            'meta'                       => '100',
            'tipo_frequencia_milestone'  => 'mensal',
        ];
    }

    public function testValidPostReturnsNoErrors(): void
    {
        $errors = validarObrigatorios('save', $this->basePost());
        $this->assertSame([], $errors);
    }

    public function testMissingObjetivoReturnError(): void
    {
        $post = $this->basePost();
        unset($post['id_objetivo']);
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('id_objetivo', $fields);
    }

    public function testMissingDescricaoReturnError(): void
    {
        $post = $this->basePost();
        $post['descricao'] = '';
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('descricao', $fields);
    }

    public function testMissingCicloTipoReturnError(): void
    {
        $post = $this->basePost();
        $post['ciclo_tipo'] = '';
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('ciclo_tipo', $fields);
    }

    public function testMissingBaselineReturnError(): void
    {
        $post = $this->basePost();
        unset($post['baseline']);
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('baseline', $fields);
    }

    public function testMissingMetaReturnError(): void
    {
        $post = $this->basePost();
        unset($post['meta']);
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('meta', $fields);
    }

    public function testSaveModeRequiresFrequencia(): void
    {
        $post = $this->basePost();
        unset($post['tipo_frequencia_milestone']);
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('tipo_frequencia_milestone', $fields);
    }

    public function testEvaluateModeDoesNotRequireFrequencia(): void
    {
        $post = $this->basePost();
        unset($post['tipo_frequencia_milestone']);
        $errors = validarObrigatorios('evaluate', $post);
        $fields = array_column($errors, 'field');
        $this->assertNotContains('tipo_frequencia_milestone', $fields);
    }

    public function testInvalidCicloAnualReturnError(): void
    {
        $post = $this->basePost();
        $post['ciclo_anual_ano'] = '';
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('ciclo_anual_ano', $fields);
    }

    public function testSemestralRequiresCicloSemestral(): void
    {
        $post = $this->basePost();
        $post['ciclo_tipo'] = 'semestral';
        unset($post['ciclo_anual_ano']);
        $errors = validarObrigatorios('save', $post);
        $fields = array_column($errors, 'field');
        $this->assertContains('ciclo_semestral', $fields);
    }
}
