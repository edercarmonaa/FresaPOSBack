-- Alternativa para el administrador local. No elimina ni altera bases existentes.
-- Ejecutar con sqlcmd conectado a master y autenticación Windows.
IF DB_ID(N'fresapos_test') IS NULL
BEGIN
    EXEC(N'CREATE DATABASE [fresapos_test]');
END;
