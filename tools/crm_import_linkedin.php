<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../auth/crm_db.php';

const CRM_IMPORT_SOURCE = 'linkedin';

$opts = getopt('', ['source:', 'dry-run', 'limit-files::']);
$sourceDir = isset($opts['source'])
    ? (string)$opts['source']
    : dirname(__DIR__, 2) . '/linkedin_mapper/data/raw';
$dryRun = array_key_exists('dry-run', $opts);
$limitFiles = isset($opts['limit-files']) ? max(1, (int)$opts['limit-files']) : 0;

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Source directory not found: {$sourceDir}\n");
    exit(1);
}

function crm_import_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function crm_import_relative_path(string $base, string $path): string
{
    $base = rtrim(crm_import_normalize_path(realpath($base) ?: $base), '/') . '/';
    $path = crm_import_normalize_path(realpath($path) ?: $path);
    return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
}

function crm_import_files(string $sourceDir): array
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($rii as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (in_array($ext, ['csv', 'html', 'htm'], true)) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function crm_import_read_csv(string $file): array
{
    $handle = fopen($file, 'rb');
    if (!$handle) {
        throw new RuntimeException("Unable to open CSV: {$file}");
    }

    $header = null;
    $rows = [];
    $lineNumber = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        if ($row === [null] || $row === false) {
            continue;
        }
        $trimmed = array_map(static fn($v) => trim((string)$v), $row);
        if ($header === null) {
            if ($trimmed[0] === 'Notes:') {
                continue;
            }
            if (count($trimmed) === 1 && $trimmed[0] === '') {
                continue;
            }
            if (basename($file) === 'Connections.csv' && $trimmed[0] !== 'First Name') {
                continue;
            }
            $header = $trimmed;
            continue;
        }

        $assoc = [];
        foreach ($header as $idx => $name) {
            if ($name === '') {
                $name = 'column_' . $idx;
            }
            $assoc[$name] = $row[$idx] ?? '';
        }
        $rows[] = ['row_number' => $lineNumber, 'data' => $assoc];
    }
    fclose($handle);

    return ['header' => $header ?? [], 'rows' => $rows];
}

function crm_import_entity_hint(string $relativePath): string
{
    $base = strtolower(basename($relativePath));
    return match ($base) {
        'connections.csv' => 'connection',
        'messages.csv', 'guide_messages.csv', 'learning_coach_messages.csv', 'learning_role_play_messages.csv' => 'message',
        'positions.csv' => 'position',
        'profile.csv', 'profile summary.csv' => 'profile',
        'skills.csv' => 'skill',
        'company follows.csv' => 'company',
        'recommendations_received.csv', 'recommendations_given.csv' => 'recommendation',
        'learning.csv' => 'learning',
        'events.csv' => 'event',
        'ad_targeting.csv' => 'ad_targeting',
        default => str_contains($relativePath, 'Jobs/') ? 'job' : 'raw',
    };
}

function crm_import_hash(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : hash('sha256', mb_strtolower($value, 'UTF-8'));
}

function crm_import_normalize_name(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value, 'UTF-8');
}

function crm_import_ascii_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = str_replace('&', ' e ', $value);
    $value = preg_replace('/[^a-z0-9\/. -]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function crm_import_company_root_key(?string $company): ?string
{
    $company = trim((string)$company);
    if ($company === '') {
        return null;
    }

    $key = crm_import_ascii_key($company);
    if ($key === '') {
        return null;
    }

    $parts = preg_split('/\s+[-|]\s+/', $key);
    if (is_array($parts) && trim((string)($parts[0] ?? '')) !== '') {
        $key = trim((string)$parts[0]);
    }

    $key = str_replace(['s/a', 's.a.'], ' sa ', $key);
    $key = preg_replace('/[^a-z0-9 ]+/', ' ', $key) ?? $key;
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;

    $stopwords = [
        'a', 'e', 'the', 'of', 'and',
        'de', 'da', 'do', 'das', 'dos',
        'grupo', 'group', 'holding',
        'ltda', 'ltd', 'sa', 'me', 'epp', 'eireli',
        'inc', 'corp', 'corporation', 'company', 'co',
        'brasil', 'brazil',
    ];

    foreach (explode(' ', trim($key)) as $token) {
        $token = trim($token);
        if ($token === '' || in_array($token, $stopwords, true)) {
            continue;
        }
        if (strlen($token) < 2) {
            continue;
        }
        return substr($token, 0, 120);
    }

    return null;
}

function crm_import_plain_text(?string $html): string
{
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function crm_import_parse_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/\s+UTC$/', '', $value) ?? $value;
    $formats = [
        'd M Y',
        'M Y',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'm/d/y, g:i A',
        'm/d/Y, g:i A',
        'D M d H:i:s Y',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value, new DateTimeZone('America/Sao_Paulo'));
        if ($dt instanceof DateTime) {
            return $dt->format(str_contains($format, 'H') || str_contains($format, 'g') ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
    }

    $ts = strtotime($value);
    return $ts ? date(strlen($value) > 11 ? 'Y-m-d H:i:s' : 'Y-m-d', $ts) : null;
}

function crm_import_seniority(string $position): string
{
    $p = mb_strtolower($position, 'UTF-8');
    return match (true) {
        preg_match('/\b(ceo|chief|founder|fundador|s[óo]cio|owner|propriet[aá]rio|presidente)\b/u', $p) === 1 => 'owner',
        preg_match('/\b(cfo|coo|cto|cio|cmo|c-level|diretor executivo)\b/u', $p) === 1 => 'c_level',
        preg_match('/\b(diretor|director|superintendente)\b/u', $p) === 1 => 'director',
        preg_match('/\b(head|lider|líder)\b/u', $p) === 1 => 'head',
        preg_match('/\b(gerente|manager|gestor)\b/u', $p) === 1 => 'manager',
        preg_match('/\b(coordenador|coordinator|coordena[çc][aã]o)\b/u', $p) === 1 => 'coordinator',
        preg_match('/\b(especialista|specialist|consultor|consultant)\b/u', $p) === 1 => 'specialist',
        preg_match('/\b(analista|analyst)\b/u', $p) === 1 => 'analyst',
        preg_match('/\b(assistente|assistant|auxiliar|estagi[aá]rio|intern)\b/u', $p) === 1 => 'assistant',
        default => 'unknown',
    };
}

function crm_import_department(string $position, string $company = ''): string
{
    $p = mb_strtolower($position . ' ' . $company, 'UTF-8');
    return match (true) {
        preg_match('/\b(ceo|coo|chief|diretor|director|estrat[eé]g|strategy|planejamento|okr|bsc)\b/u', $p) === 1 => 'strategy',
        preg_match('/\b(ti|tecnologia|technology|dados|data|bi|analytics|sistemas|software|dev|it)\b/u', $p) === 1 => 'it_data',
        preg_match('/\b(finance|financeiro|controladoria|controller|custos|contabilidade|fiscal|fp&a)\b/u', $p) === 1 => 'finance',
        preg_match('/\b(opera[çc][oõ]es|operations|log[ií]stica|supply|produção|producao|pcp|industrial)\b/u', $p) === 1 => 'operations',
        preg_match('/\b(comercial|sales|vendas|marketing|business development|ecommerce|e-commerce)\b/u', $p) === 1 => 'commercial_marketing',
        preg_match('/\b(rh|recursos humanos|people|talent|recrutamento|hr)\b/u', $p) === 1 => 'hr',
        preg_match('/\b(jur[ií]dico|legal|advocacia|compliance|lgpd|dpo)\b/u', $p) === 1 => 'legal',
        preg_match('/\b(compras|suprimentos|procurement|purchasing)\b/u', $p) === 1 => 'procurement',
        default => 'unknown',
    };
}

function crm_import_score(string $seniority, string $department, string $company, ?string $connectedOn): float
{
    $score = 10.0;
    $score += match ($seniority) {
        'owner', 'c_level' => 35,
        'director', 'head' => 28,
        'manager' => 22,
        'coordinator' => 15,
        'specialist' => 10,
        default => 4,
    };
    $score += match ($department) {
        'strategy', 'it_data', 'finance', 'operations', 'commercial_marketing' => 20,
        default => 5,
    };
    if (trim($company) !== '') {
        $score += 10;
    }
    if ($connectedOn) {
        $dt = DateTime::createFromFormat('Y-m-d', $connectedOn);
        if ($dt && $dt > new DateTime('-18 months')) {
            $score += 8;
        }
    }
    return min(100, $score);
}

function crm_import_get_or_create_account(PDO $pdo, string $company): ?int
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $normalized = crm_import_normalize_name($company);
    $rootKey = crm_import_company_root_key($company);
    $st = $pdo->prepare('SELECT id_account FROM crm_accounts WHERE normalized_name = ? ORDER BY id_account LIMIT 1');
    $st->execute([$normalized]);
    $id = $st->fetchColumn();
    if ($id) {
        if ($rootKey !== null) {
            $upd = $pdo->prepare('UPDATE crm_accounts SET company_root_key = COALESCE(company_root_key, ?) WHERE id_account = ?');
            $upd->execute([$rootKey, (int)$id]);
        }
        return (int)$id;
    }

    $ins = $pdo->prepare("
        INSERT INTO crm_accounts
          (account_name, normalized_name, company_root_key, source_type, source_confidence, account_status, priority)
        VALUES
          (?, ?, ?, 'linkedin', 60, 'new', 'medium')
    ");
    $ins->execute([$company, $normalized, $rootKey]);
    return (int)$pdo->lastInsertId();
}

function crm_import_upsert_contact(PDO $pdo, array $data): int
{
    $linkedinHash = crm_import_hash($data['linkedin_url'] ?? '');
    if ($linkedinHash) {
        $st = $pdo->prepare('SELECT id_contact FROM crm_contacts WHERE linkedin_url_hash = ? LIMIT 1');
        $st->execute([$linkedinHash]);
        $id = $st->fetchColumn();
        if ($id) {
            $upd = $pdo->prepare("
                UPDATE crm_contacts
                   SET first_name = COALESCE(NULLIF(?, ''), first_name),
                       last_name = COALESCE(NULLIF(?, ''), last_name),
                       full_name = COALESCE(NULLIF(?, ''), full_name),
                       normalized_full_name = COALESCE(NULLIF(?, ''), normalized_full_name),
                       current_account_id = COALESCE(?, current_account_id),
                       current_company_name = COALESCE(NULLIF(?, ''), current_company_name),
                       current_position = COALESCE(NULLIF(?, ''), current_position),
                       seniority = ?,
                       department = ?,
                       connected_on = COALESCE(?, connected_on),
                       lead_score = GREATEST(lead_score, ?),
                       updated_at = NOW()
                 WHERE id_contact = ?
            ");
            $upd->execute([
                $data['first_name'] ?? '',
                $data['last_name'] ?? '',
                $data['full_name'] ?? '',
                crm_import_normalize_name($data['full_name'] ?? ''),
                $data['current_account_id'] ?? null,
                $data['current_company_name'] ?? '',
                $data['current_position'] ?? '',
                $data['seniority'] ?? 'unknown',
                $data['department'] ?? 'unknown',
                $data['connected_on'] ?? null,
                $data['lead_score'] ?? 0,
                (int)$id,
            ]);
            return (int)$id;
        }
    }

    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    }

    $ins = $pdo->prepare("
        INSERT INTO crm_contacts
          (first_name, last_name, full_name, normalized_full_name, linkedin_url, linkedin_url_hash,
           current_account_id, current_company_name, current_position, seniority, department,
           connected_on, relationship_strength, contact_status, source_type, lead_score)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'warm', 'new', 'linkedin', ?)
    ");
    $ins->execute([
        $data['first_name'] ?? null,
        $data['last_name'] ?? null,
        $fullName,
        crm_import_normalize_name($fullName),
        $data['linkedin_url'] ?? null,
        $linkedinHash,
        $data['current_account_id'] ?? null,
        $data['current_company_name'] ?? null,
        $data['current_position'] ?? null,
        $data['seniority'] ?? 'unknown',
        $data['department'] ?? 'unknown',
        $data['connected_on'] ?? null,
        $data['lead_score'] ?? 0,
    ]);
    return (int)$pdo->lastInsertId();
}

function crm_import_insert_channel(PDO $pdo, int $contactId, string $type, string $value): void
{
    $value = trim($value);
    if ($value === '') {
        return;
    }
    $hash = crm_import_hash($value);
    $st = $pdo->prepare("
        INSERT IGNORE INTO crm_contact_channels
          (id_contact, channel_type, channel_value, channel_hash, is_primary, consent_status, source_type)
        VALUES
          (?, ?, ?, ?, ?, 'unknown', 'linkedin')
    ");
    $st->execute([$contactId, $type, $value, $hash, in_array($type, ['linkedin', 'email'], true) ? 1 : 0]);
}

function crm_import_insert_position(PDO $pdo, int $contactId, ?int $accountId, string $company, string $title, ?string $startedOn = null, ?string $finishedOn = null, ?string $description = null, ?string $location = null): void
{
    $title = trim($title);
    if ($title === '') {
        return;
    }
    $st = $pdo->prepare("
        SELECT id_position
          FROM crm_contact_positions
         WHERE id_contact = ?
           AND COALESCE(company_name, '') = ?
           AND title = ?
           AND COALESCE(started_on, '0000-00-00') = COALESCE(?, '0000-00-00')
         LIMIT 1
    ");
    $st->execute([$contactId, $company, $title, $startedOn]);
    if ($st->fetchColumn()) {
        return;
    }
    $ins = $pdo->prepare("
        INSERT INTO crm_contact_positions
          (id_contact, id_account, company_name, title, description, location, started_on, finished_on, is_current, source_type)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, 'linkedin')
    ");
    $ins->execute([$contactId, $accountId, $company ?: null, $title, $description, $location, $startedOn, $finishedOn, $finishedOn ? 0 : 1]);
}

function crm_import_start_batch(PDO $pdo, string $relativePath, string $file, int $totalRows, string $hint): int
{
    $st = $pdo->prepare("
        INSERT INTO crm_import_batches
          (source_type, source_name, original_filename, file_sha256, file_size_bytes, status, total_rows, started_at, metadata_json)
        VALUES
          ('linkedin', ?, ?, ?, ?, 'processing', ?, NOW(), ?)
    ");
    $metadata = json_encode(['entity_hint' => $hint, 'relative_path' => $relativePath], JSON_UNESCAPED_UNICODE);
    $st->execute([$hint, $relativePath, hash_file('sha256', $file), filesize($file) ?: 0, $totalRows, $metadata]);
    return (int)$pdo->lastInsertId();
}

function crm_import_insert_raw_rows(PDO $pdo, int $batchId, array $rows, string $hint): int
{
    $ins = $pdo->prepare("
        INSERT INTO crm_import_rows
          (id_import_batch, row_number, entity_hint, raw_json, raw_text, row_hash, processing_status)
        VALUES
          (?, ?, ?, ?, ?, ?, 'processed')
    ");
    $count = 0;
    foreach ($rows as $row) {
        $json = json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = implode(' ', array_map('strval', $row['data']));
        $ins->execute([$batchId, $row['row_number'], $hint, $json, $text, hash('sha256', $json ?: '')]);
        $count++;
    }
    return $count;
}

function crm_import_finish_batch(PDO $pdo, int $batchId, int $processedRows, int $insertedRows, int $updatedRows = 0, int $skippedRows = 0, int $errorRows = 0): void
{
    $st = $pdo->prepare("
        UPDATE crm_import_batches
           SET status = 'completed',
               processed_rows = ?,
               inserted_rows = ?,
               updated_rows = ?,
               skipped_rows = ?,
               error_rows = ?,
               finished_at = NOW()
         WHERE id_import_batch = ?
    ");
    $st->execute([$processedRows, $insertedRows, $updatedRows, $skippedRows, $errorRows, $batchId]);
}

function crm_import_connections(PDO $pdo, array $rows): array
{
    $stats = ['contacts' => 0, 'accounts' => 0, 'channels' => 0, 'positions' => 0];
    foreach ($rows as $row) {
        $r = $row['data'];
        $first = trim((string)($r['First Name'] ?? ''));
        $last = trim((string)($r['Last Name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full === '') {
            continue;
        }
        $company = trim((string)($r['Company'] ?? ''));
        $position = trim((string)($r['Position'] ?? ''));
        $connected = crm_import_parse_date($r['Connected On'] ?? '');
        $accountId = crm_import_get_or_create_account($pdo, $company);
        if ($accountId) {
            $stats['accounts']++;
        }
        $seniority = crm_import_seniority($position);
        $department = crm_import_department($position, $company);
        $score = crm_import_score($seniority, $department, $company, $connected);
        $contactId = crm_import_upsert_contact($pdo, [
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $full,
            'linkedin_url' => $r['URL'] ?? '',
            'current_account_id' => $accountId,
            'current_company_name' => $company,
            'current_position' => $position,
            'seniority' => $seniority,
            'department' => $department,
            'connected_on' => $connected,
            'lead_score' => $score,
        ]);
        $stats['contacts']++;

        if (!empty($r['URL'])) {
            crm_import_insert_channel($pdo, $contactId, 'linkedin', (string)$r['URL']);
            $stats['channels']++;
        }
        if (!empty($r['Email Address'])) {
            crm_import_insert_channel($pdo, $contactId, 'email', (string)$r['Email Address']);
            $stats['channels']++;
        }
        if ($position !== '') {
            crm_import_insert_position($pdo, $contactId, $accountId, $company, $position);
            $stats['positions']++;
        }
    }
    return $stats;
}

function crm_import_positions(PDO $pdo, array $rows): array
{
    $stats = ['accounts' => 0];
    foreach ($rows as $row) {
        $r = $row['data'];
        $company = trim((string)($r['Company Name'] ?? ''));
        if ($company !== '') {
            crm_import_get_or_create_account($pdo, $company);
            $stats['accounts']++;
        }
    }
    return $stats;
}

function crm_import_messages(PDO $pdo, array $rows): array
{
    $stats = ['conversations' => 0, 'messages' => 0, 'contacts' => 0];
    $convCache = [];
    foreach ($rows as $row) {
        $r = $row['data'];
        $externalId = trim((string)($r['CONVERSATION ID'] ?? ''));
        if ($externalId === '') {
            continue;
        }
        $senderName = trim((string)($r['FROM'] ?? ''));
        $senderUrl = trim((string)($r['SENDER PROFILE URL'] ?? ''));
        $isOwn = mb_strtolower($senderName, 'UTF-8') === 'wendrew gomes';
        $contactId = null;
        if (!$isOwn && $senderName !== '') {
            $parts = preg_split('/\s+/u', $senderName, 2);
            $contactId = crm_import_upsert_contact($pdo, [
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'full_name' => $senderName,
                'linkedin_url' => $senderUrl,
                'relationship_strength' => 'warm',
                'lead_score' => 20,
            ]);
            $stats['contacts']++;
            if ($senderUrl !== '') {
                crm_import_insert_channel($pdo, $contactId, 'linkedin', $senderUrl);
            }
        }

        if (!isset($convCache[$externalId])) {
            $st = $pdo->prepare('SELECT id_conversation FROM crm_conversations WHERE source_type = "linkedin" AND external_conversation_id = ? LIMIT 1');
            $st->execute([$externalId]);
            $convId = $st->fetchColumn();
            if (!$convId) {
                $ins = $pdo->prepare("
                    INSERT INTO crm_conversations
                      (source_type, external_conversation_id, conversation_title, id_contact, direction, folder, started_at, last_message_at, message_count)
                    VALUES
                      ('linkedin', ?, ?, ?, 'mixed', ?, ?, ?, 0)
                ");
                $sentAt = crm_import_parse_date($r['DATE'] ?? '');
                $ins->execute([
                    $externalId,
                    $r['CONVERSATION TITLE'] ?? null,
                    $contactId,
                    $r['FOLDER'] ?? null,
                    $sentAt,
                    $sentAt,
                ]);
                $convId = (int)$pdo->lastInsertId();
                $stats['conversations']++;
            }
            $convCache[$externalId] = (int)$convId;
        }

        $sentAt = crm_import_parse_date($r['DATE'] ?? '');
        $contentHtml = (string)($r['CONTENT'] ?? '');
        $contentHash = hash('sha256', $externalId . '|' . $sentAt . '|' . $senderName . '|' . $contentHtml);
        $exists = $pdo->prepare('SELECT id_message FROM crm_messages WHERE id_conversation = ? AND content_hash = ? LIMIT 1');
        $exists->execute([$convCache[$externalId], $contentHash]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $ins = $pdo->prepare("
            INSERT INTO crm_messages
              (id_conversation, id_contact, sender_name, sender_profile_url, recipient_names, recipient_profile_urls,
               direction, subject, content_html, content_text, sent_at, folder, attachments_json, is_draft, content_hash)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $convCache[$externalId],
            $contactId,
            $senderName ?: null,
            $senderUrl ?: null,
            $r['TO'] ?? null,
            $r['RECIPIENT PROFILE URLS'] ?? null,
            $isOwn ? 'outbound' : 'inbound',
            $r['SUBJECT'] ?? null,
            $contentHtml ?: null,
            crm_import_plain_text($contentHtml),
            $sentAt,
            $r['FOLDER'] ?? null,
            !empty($r['ATTACHMENTS']) ? json_encode(['raw' => $r['ATTACHMENTS']], JSON_UNESCAPED_UNICODE) : null,
            strtolower((string)($r['IS MESSAGE DRAFT'] ?? '')) === 'yes' ? 1 : 0,
            $contentHash,
        ]);
        $stats['messages']++;

        $upd = $pdo->prepare("
            UPDATE crm_conversations
               SET message_count = message_count + 1,
                   last_message_at = GREATEST(COALESCE(last_message_at, '1000-01-01'), COALESCE(?, last_message_at)),
                   updated_at = NOW()
             WHERE id_conversation = ?
        ");
        $upd->execute([$sentAt, $convCache[$externalId]]);
    }
    return $stats;
}

$files = crm_import_files($sourceDir);
if ($limitFiles > 0) {
    $files = array_slice($files, 0, $limitFiles);
}

$summary = [
    'source_dir' => $sourceDir,
    'dry_run' => $dryRun,
    'files' => count($files),
    'raw_rows' => 0,
    'normalized' => [],
];

if ($dryRun) {
    foreach ($files as $file) {
        $relative = crm_import_relative_path($sourceDir, $file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $parsed = crm_import_read_csv($file);
            $rows = count($parsed['rows']);
        } else {
            $rows = 1;
        }
        $summary['raw_rows'] += $rows;
        echo "[dry-run] {$relative}: {$rows} row(s)\n";
    }
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$pdo = crm_db();
$pdo->beginTransaction();
try {
    foreach ($files as $file) {
        $relative = crm_import_relative_path($sourceDir, $file);
        $hint = crm_import_entity_hint($relative);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $parsed = crm_import_read_csv($file);
            $rows = $parsed['rows'];
        } else {
            $rows = [[
                'row_number' => 1,
                'data' => [
                    'filename' => $relative,
                    'content' => file_get_contents($file) ?: '',
                ],
            ]];
        }

        $batchId = crm_import_start_batch($pdo, $relative, $file, count($rows), $hint);
        $insertedRaw = crm_import_insert_raw_rows($pdo, $batchId, $rows, $hint);
        $summary['raw_rows'] += $insertedRaw;

        $base = basename($relative);
        $normalized = [];
        if ($base === 'Connections.csv') {
            $normalized = crm_import_connections($pdo, $rows);
        } elseif ($base === 'messages.csv') {
            $normalized = crm_import_messages($pdo, $rows);
        } elseif ($base === 'Positions.csv') {
            $normalized = crm_import_positions($pdo, $rows);
        }
        $summary['normalized'][$relative] = $normalized;
        crm_import_finish_batch($pdo, $batchId, $insertedRaw, $insertedRaw);
        echo "[import] {$relative}: raw={$insertedRaw}";
        if ($normalized) {
            echo ' normalized=' . json_encode($normalized, JSON_UNESCAPED_UNICODE);
        }
        echo "\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
