<?php
declare(strict_types=1);

/**
 * Formatação/normalização de nomes de usuário — SOMENTE PARA EXIBIÇÃO.
 *
 * Regras acordadas:
 *  - Nome curto (padrão em todo o sistema): primeiro nome + ÚLTIMA palavra do sobrenome.
 *      "Adriana Camargo Vieira de Castilho" -> "Adriana Castilho"
 *  - Nome completo (apenas perfil/editar perfil): primeiro + todos os sobrenomes.
 *  - Normalização: primeira letra maiúscula, demais minúsculas (Title Case),
 *      com partículas conectivas (de, da, do, das, dos, e, ...) em minúsculo.
 *
 * IMPORTANTE: estas funções NÃO alteram dados gravados. Nunca use o resultado
 * para WHERE/JOIN/ORDER BY nem como valor submetido — apenas para exibir.
 */

if (!function_exists('nome_normalizar')) {
    /** Title Case pt-BR com partículas em minúsculo. "MARIA DA SILVA" -> "Maria da Silva". */
    function nome_normalizar(?string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s) ?? '');
        if ($s === '') {
            return '';
        }
        $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        // Partículas conectivas ficam em minúsculo, exceto se forem a 1ª palavra.
        static $particulas = ['De','Da','Do','Das','Dos','E','Di','Du','Del','Della','La','Le','Van','Von','Y'];
        $parts = explode(' ', $s);
        foreach ($parts as $i => $w) {
            if ($i > 0 && in_array($w, $particulas, true)) {
                $parts[$i] = mb_strtolower($w, 'UTF-8');
            }
        }
        return implode(' ', $parts);
    }
}

if (!function_exists('nome_completo')) {
    /** Nome completo normalizado (primeiro + todos os sobrenomes). Para perfil/editar perfil. */
    function nome_completo(?string $primeiro, ?string $ultimo = ''): string
    {
        return nome_normalizar(trim(((string) $primeiro) . ' ' . ((string) $ultimo)));
    }
}

if (!function_exists('nome_exibicao')) {
    /**
     * Nome curto: primeiro nome + última palavra "real" do sobrenome.
     *   nome_exibicao('Adriana','Camargo Vieira de Castilho') -> "Adriana Castilho"
     * Se `ultimo` vier vazio, deriva do próprio `primeiro` (1ª + última palavra).
     */
    function nome_exibicao(?string $primeiro, ?string $ultimo = ''): string
    {
        static $particulas = ['de','da','do','das','dos','e','di','du','del','della','la','le','van','von','y'];

        $p = nome_normalizar($primeiro);
        $u = nome_normalizar($ultimo);

        if ($u === '') {
            $tok = $p === '' ? [] : explode(' ', $p);
            if (count($tok) <= 1) {
                return $p;
            }
            return $tok[0] . ' ' . $tok[count($tok) - 1];
        }

        $primeiroNome = $p === '' ? '' : explode(' ', $p)[0];

        // Última palavra do sobrenome que não seja partícula (ex.: "... de Castilho" -> "Castilho").
        $tokU = explode(' ', $u);
        $ultimoSobrenome = end($tokU);
        while (count($tokU) > 1 && in_array(mb_strtolower($ultimoSobrenome, 'UTF-8'), $particulas, true)) {
            array_pop($tokU);
            $ultimoSobrenome = end($tokU);
        }

        return trim($primeiroNome . ' ' . $ultimoSobrenome);
    }
}

if (!function_exists('nome_exibicao_str')) {
    /** Variante que recebe um nome já combinado em 1 string. "Ana Paula Souza Lima" -> "Ana Lima". */
    function nome_exibicao_str(?string $nomeCompleto): string
    {
        $n = nome_normalizar($nomeCompleto);
        if ($n === '') {
            return '';
        }
        $tok = explode(' ', $n);
        if (count($tok) <= 1) {
            return $n;
        }
        return $tok[0] . ' ' . $tok[count($tok) - 1];
    }
}
