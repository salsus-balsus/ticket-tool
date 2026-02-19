-- Overrides für Kommentar-Autoren (z. B. "Unknown" oder Anzeigename)
CREATE TABLE IF NOT EXISTS author_display (
    author_raw VARCHAR(255) NOT NULL PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
);
