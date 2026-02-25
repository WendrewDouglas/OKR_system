<?php
declare(strict_types=1);

function h(mixed $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}