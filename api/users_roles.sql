-- Prepares the users table for login. Your users table is empty, so these
-- changes are safe. (Written for XAMPP's MariaDB.)
USE commutestay;

ALTER TABLE users
  MODIFY full_name     VARCHAR(100) NOT NULL,
  MODIFY email         VARCHAR(190) NOT NULL,
  MODIFY password_hash VARCHAR(255) NOT NULL,
  MODIFY role          ENUM('student','owner','admin') NOT NULL DEFAULT 'student';

-- One account per email address
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_email (email);

-- Later, to make yourself the admin (after registering normally):
-- UPDATE users SET role = 'admin' WHERE email = 'your-email@example.com';
