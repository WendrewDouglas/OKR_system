<?php
/**
 * partials/breadcrumbs.php — Shared breadcrumb component.
 *
 * Usage:
 *   $breadcrumbs = [
 *     ['label' => 'Dashboard', 'icon' => 'fa-solid fa-house', 'href' => '/OKR_system/dashboard'],
 *     ['label' => 'Meus OKRs', 'icon' => 'fa-solid fa-bullseye', 'href' => '/OKR_system/meus_okrs'],
 *     ['label' => 'Novo Objetivo', 'icon' => 'fa-solid fa-circle-plus'],
 *   ];
 *   include __DIR__ . '/partials/breadcrumbs.php';
 *
 * CSS classes come from components.css (.crumbs, .sep).
 */
if (empty($breadcrumbs) || !is_array($breadcrumbs)) return;
?>
<nav class="crumbs" aria-label="Breadcrumb">
  <i class="fa-solid fa-route" aria-hidden="true"></i>
  <?php foreach ($breadcrumbs as $i => $item): ?>
    <?php if ($i > 0): ?>
      <span class="sep" aria-hidden="true">/</span>
    <?php endif; ?>
    <?php if (!empty($item['href'])): ?>
      <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
        <?php if (!empty($item['icon'])): ?><i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><?php endif; ?>
        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php else: ?>
      <span aria-current="page">
        <?php if (!empty($item['icon'])): ?><i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><?php endif; ?>
        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
      </span>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
