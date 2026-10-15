CREATE TABLE IF NOT EXISTS sectors (
    id VARCHAR(80) PRIMARY KEY,
    label VARCHAR(160) NOT NULL,
    icon VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    color CHAR(7) NOT NULL,
    text_color CHAR(7) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id VARCHAR(100) PRIMARY KEY,
    sector_id VARCHAR(80) NOT NULL,
    title VARCHAR(200) NOT NULL,
    search_query VARCHAR(200) NOT NULL,
    badge VARCHAR(100) NULL,
    summary TEXT NOT NULL,
    description TEXT NOT NULL,
    tasks JSON NOT NULL,
    environment TEXT NOT NULL,
    schedule TEXT NOT NULL,
    access TEXT NOT NULL,
    qualities JSON NOT NULL,
    photo_url TEXT NOT NULL,
    photo_alt VARCHAR(255) NOT NULL,
    forem_url TEXT NOT NULL,
    verified_at DATE NULL,
    labour_market JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT jobs_sector_fk FOREIGN KEY (sector_id) REFERENCES sectors(id),
    INDEX jobs_sector_idx (sector_id),
    INDEX jobs_title_idx (title),
    INDEX jobs_badge_idx (badge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

