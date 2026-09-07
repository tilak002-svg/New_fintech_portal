-- Single admin-editable override for the API Base URL shown to every
-- customer (Settings/API Access, API documentation). Deliberately its own
-- tiny table rather than reusing the legacy platform_api_settings
-- singleton (superseded by customer_api_credentials — see schema.sql's
-- comment on it — and unrelated in purpose to this).
--
-- NULL api_base_url means "no override configured yet" — every reader
-- falls back to APP_URL + /api/v1 (see includes/functions.php's
-- platform_api_base_url()), so this table starts genuinely optional:
-- nothing breaks before an admin ever touches it.
CREATE TABLE IF NOT EXISTS platform_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    api_base_url VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_platform_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
