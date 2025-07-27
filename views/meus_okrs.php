<?php
// views/meus_okrs.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->prepare("SELECT * FROM objetivos WHERE dono = :id ORDER BY dt_prazo ASC");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $objetivos = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro ao conectar: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Meus OKRs – OKR System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" crossorigin="anonymous"/>
    <style>
        .main-wrapper {
            padding: 2rem 2rem 2rem 1.5rem;
        }

        @media (max-width: 991px) {
            .main-wrapper {
                padding: 1rem;
            }
        }

        .grid-okrs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .okr-card {
            background: #fff;
            border-left: 5px solid #0d6efd;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1rem;
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .okr-card:hover {
            transform: scale(1.02);
        }

        .okr-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .okr-basic {
            font-size: 0.875rem;
            color: #555;
        }

        .okr-hidden {
            opacity: 0;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .okr-card:hover .okr-hidden {
            opacity: 1;
            max-height: 400px;
            margin-top: 0.5rem;
        }

        .okr-badges {
            margin-top: 0.75rem;
        }

        .okr-badges .badge {
            font-size: 0.7rem;
            margin-right: 0.3rem;
        }

        .okr-footer {
            margin-top: 1rem;
            text-align: right;
        }

        .okr-footer a {
            font-size: 0.8rem;
        }

        .empty-message {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .empty-message p {
            font-size: 1.1rem;
            color: #444;
            margin-bottom: 1.5rem;
        }

        .btn-novo {
            display: inline-block;
            padding: 0.6rem 1.2rem;
            font-size: 1rem;
            border-radius: 30px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/../views/partials/header.php'; ?>

        <main id="main-content" class="main-wrapper">
            <h1><i class="fas fa-bullseye me-2"></i>Meus OKRs</h1>

            <?php if (empty($objetivos)): ?>
                <div class="empty-message mt-4">
                    <p>🎯 Você ainda não possui objetivos cadastrados como responsável.</p>
                    <div>
                        <a href="/OKR_system/novo_objetivo" class="btn btn-primary btn-novo">
                            <i class="fas fa-plus me-2"></i>Criar novo objetivo
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid-okrs mt-4">
                    <?php foreach ($objetivos as $obj): ?>
                        <div class="okr-card">
                            <div class="okr-title"><?= htmlspecialchars($obj['descricao']) ?></div>
                            <div class="okr-basic">Prazo: <?= date('d/m/Y', strtotime($obj['dt_prazo'])) ?></div>
                            <div class="okr-basic">Ciclo: <?= $obj['tipo_ciclo'] . ' - ' . $obj['ciclo'] ?></div>

                            <div class="okr-hidden">
                                <div class="okr-basic"><strong>Tipo:</strong> <?= ucfirst($obj['tipo']) ?></div>
                                <div class="okr-basic"><strong>Pilar:</strong> <?= htmlspecialchars($obj['pilar_bsc']) ?></div>
                                <?php if (!empty($obj['justificativa_ia'])): ?>
                                    <div class="okr-basic mt-2">
                                        <strong>Justificativa IA:</strong><br>
                                        <small><?= nl2br(htmlspecialchars($obj['justificativa_ia'])) ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="okr-badges">
                                <span class="badge bg-secondary"><?= ucfirst($obj['status']) ?></span>
                                <span class="badge bg-<?= $obj['status_aprovacao'] === 'pendente' ? 'warning' : 'success' ?>">
                                    <?= ucfirst($obj['status_aprovacao']) ?>
                                </span>
                                <span class="badge bg-<?= $obj['qualidade'] === 'ótimo' ? 'success' : ($obj['qualidade'] === 'moderado' ? 'warning' : 'secondary') ?>">
                                    <?= ucfirst($obj['qualidade']) ?>
                                </span>
                            </div>

                            <div class="okr-footer">
                                <a href="OKR_detalhe_objetivo.php?id=<?= $obj['id_objetivo'] ?>" class="btn btn-sm btn-outline-primary">
                                    Detalhar
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php include __DIR__ . '/../views/partials/chat.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
