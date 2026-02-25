-- Migration 004: Corrigir FK bloqueante para exclusao de usuario
-- usuarios.id_user_alteracao -> usuarios.id_user precisa de ON DELETE SET NULL
-- Caso contrario, deletar user A que alterou user B falha com RESTRICT

ALTER TABLE usuarios DROP FOREIGN KEY usuarios_ibfk_2;

ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_alteracao
  FOREIGN KEY (id_user_alteracao) REFERENCES usuarios (id_user)
  ON DELETE SET NULL ON UPDATE CASCADE;
