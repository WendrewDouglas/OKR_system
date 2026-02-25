-- ============================================================
-- Migration 002: Corrigir RBAC para usuários cadastrados
-- Problema: auth_register.php inseria apenas em usuarios_permissoes
--           (legada), sem inserir em rbac_user_role (sistema atual).
-- ============================================================

-- 1) Vincular todos os usuários sem papel RBAC ao papel 'user_admin'
INSERT INTO rbac_user_role (user_id, role_id, valid_from)
SELECT u.id_user, r.role_id, NOW()
FROM usuarios u
CROSS JOIN rbac_roles r
WHERE r.role_key = 'user_admin'
  AND r.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM rbac_user_role ur WHERE ur.user_id = u.id_user
  );

-- 2) Garantir que 'user_admin' tenha TODAS as capabilities existentes
--    (acesso geral, isolado por company via tenant check no has_cap)
INSERT IGNORE INTO rbac_role_capability (role_id, capability_id, effect)
SELECT r.role_id, c.capability_id, 'ALLOW'
FROM rbac_roles r
CROSS JOIN rbac_capabilities c
WHERE r.role_key = 'user_admin'
  AND r.is_active = 1;
