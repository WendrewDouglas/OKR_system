<?php
declare(strict_types=1);

/**
 * Atribui um avatar da galeria, por GÊNERO (inferido do primeiro nome), aos
 * usuários com e-mail @planningbi.com.br.
 *
 * - Infere genero por lista de nomes BR + heuristica de fallback (termina em 'a' -> feminino).
 * - Escolhe um avatar da galeria (kind=default, gallery/*.svg) do genero correspondente,
 *   distribuido de forma deterministica (id_user % N) para variar entre usuarios.
 * - NUNCA sobrescreve quem ja tem avatar 'custom' (foto propria enviada).
 * - Idempotente: re-rodar atribui o mesmo avatar.
 *
 * Uso:
 *   php tools/assign_planningbi_avatars.php --dry
 *   php tools/assign_planningbi_avatars.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$dry = in_array('--dry', $argv, true);

$root = dirname(__DIR__);
require $root . '/auth/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* ---- listas de nomes (normalizados: minusculo, sem acento) ---- */
$FEM = array_flip([
  // presentes na base @planningbi
  'amanda','camila','carla','eduarda','elaine','gabriela','giovana','ingrid','isabela','karina',
  'larissa','mariana','natalia','nathalia','olivia','patricia','paula','sabrina','simone','tatiane',
  'vanessa','yasmin',
  // comuns adicionais (robustez para novos usuarios)
  'ana','maria','julia','beatriz','leticia','aline','bruna','fernanda','juliana','luana','marcia',
  'renata','sandra','luiza','livia','clara','helena','laura','sofia','sophia','valentina','manuela',
  'cecilia','heloisa','rafaela','daniela','priscila','adriana','viviane','cristina','debora','tatiana',
  'carolina','caroline','andressa','jessica','jaqueline','monica','raquel','rosana','silvia','vera',
  'katia','flavia','bianca','vitoria','vivian','rita','alessandra','michele','michelle','denise','elis',
]);
$MASC = array_flip([
  // presentes na base @planningbi
  'bernardo','bruno','daniel','diego','fabio','felipe','heitor','henrique','joao','julio','lucas',
  'marcelo','otavio','rafael','renato','thiago','vinicius','william',
  // comuns adicionais
  'jose','antonio','carlos','paulo','pedro','marcos','luiz','luis','andre','gabriel','rodrigo','gustavo',
  'eduardo','fernando','ricardo','sergio','roberto','alexandre','leonardo','mateus','matheus','guilherme',
  'caio','vitor','victor','samuel','davi','david','enzo','arthur','miguel','igor','ivan','jorge','cesar',
  'wesley','wagner','anderson','alan','adriano','tiago','joaquim','benicio','noah','bryan','kevin',
]);

function norm_name(string $full): string {
    $first = preg_split('/\s+/', trim($full))[0] ?? '';
    $first = mb_strtolower($first, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ü'=>'u','ù'=>'u','ç'=>'c','ñ'=>'n'];
    $first = strtr($first, $map);
    return preg_replace('/[^a-z]/', '', $first);
}

function infer_gender(string $full, array $FEM, array $MASC, bool &$byHeuristic): string {
    $n = norm_name($full);
    $byHeuristic = false;
    if (isset($FEM[$n]))  return 'feminino';
    if (isset($MASC[$n])) return 'masculino';
    $byHeuristic = true;
    // fallback: nomes terminados em 'a' tendem a feminino
    return (substr($n, -1) === 'a') ? 'feminino' : 'masculino';
}

/* ---- pools de avatares da galeria por genero (ordenados p/ determinismo) ---- */
$pools = ['masculino' => [], 'feminino' => []];
foreach ($pdo->query(
    "SELECT id, gender FROM avatars
      WHERE kind='default' AND active=1 AND path LIKE 'gallery/%'
        AND gender IN ('masculino','feminino')
      ORDER BY id"
) as $a) {
    $pools[$a['gender']][] = (int)$a['id'];
}
if (!$pools['masculino'] || !$pools['feminino']) {
    fwrite(STDERR, "Galeria por genero vazia. Rode o seeder 007 antes.\n"); exit(1);
}

/* ---- usuarios @planningbi.com.br ---- */
$users = $pdo->query(
    "SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, a.kind AS cur_kind
       FROM usuarios u
  LEFT JOIN avatars a ON a.id = u.avatar_id
      WHERE u.email_corporativo LIKE '%@planningbi.com.br'
      ORDER BY u.id_user"
)->fetchAll();

$upd = $pdo->prepare("UPDATE usuarios SET avatar_id = :aid WHERE id_user = :uid");

$applied = 0; $skippedCustom = 0; $heur = [];
foreach ($users as $u) {
    $uid = (int)$u['id_user'];
    if (($u['cur_kind'] ?? '') === 'custom') { $skippedCustom++; continue; } // preserva foto propria

    $byHeuristic = false;
    $g = infer_gender((string)$u['primeiro_nome'], $FEM, $MASC, $byHeuristic);
    $pool = $pools[$g];
    $avatarId = $pool[$uid % count($pool)];

    $flag = $byHeuristic ? ' [heuristica]' : '';
    if ($byHeuristic) $heur[] = $u['primeiro_nome'] . " -> " . $g;

    echo ($dry ? "[DRY] " : "") . str_pad("#$uid", 5) . " "
       . str_pad(trim($u['primeiro_nome'].' '.($u['ultimo_nome'] ?? '')), 26)
       . " -> {$g} (avatar_id={$avatarId}){$flag}\n";

    if (!$dry) { $upd->execute([':aid' => $avatarId, ':uid' => $uid]); $applied++; }
}

echo "\n" . ($dry ? "[DRY-RUN] " : "")
   . "PLANNINGBI_AVATARS_OK total=" . count($users)
   . " aplicados=" . ($dry ? 0 : $applied)
   . " preservados_custom={$skippedCustom}"
   . " por_heuristica=" . count($heur) . "\n";
if ($heur) echo "  (heuristica usada em: " . implode(', ', $heur) . ")\n";
