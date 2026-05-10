-- Support Tickets
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('bug', 'feedback', 'sonstiges') NOT NULL DEFAULT 'sonstiges',
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('offen', 'in_bearbeitung', 'geschlossen') NOT NULL DEFAULT 'offen',
    priority ENUM('niedrig', 'normal', 'hoch') NOT NULL DEFAULT 'normal',
    admin_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email field for users
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL DEFAULT NULL AFTER family_name;
