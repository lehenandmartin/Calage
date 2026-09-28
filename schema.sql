-- Calage schema. Run automatically by Db on first launch (PRAGMA user_version = 0).
-- Dates as ISO 8601 text, UTC.

CREATE TABLE folders (
    id         INTEGER PRIMARY KEY,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE newsletters (
    id         INTEGER PRIMARY KEY,
    folder_id  INTEGER REFERENCES folders(id) ON DELETE RESTRICT, -- NULL = no folder
    name       TEXT NOT NULL,
    token      TEXT NOT NULL UNIQUE,                               -- share link, random
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX newsletters_folder ON newsletters(folder_id);

CREATE TABLE versions (
    id            INTEGER PRIMARY KEY,
    newsletter_id INTEGER NOT NULL REFERENCES newsletters(id) ON DELETE CASCADE,
    number        INTEGER,                 -- NULL while a draft
    status        TEXT NOT NULL CHECK (status IN ('draft', 'published')),
    html          TEXT NOT NULL,           -- original HTML, original paths
    subject       TEXT NOT NULL DEFAULT '',
    preheader     TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    published_at  TEXT,
    CHECK ((status = 'draft' AND number IS NULL AND published_at IS NULL)
        OR (status = 'published' AND number IS NOT NULL AND published_at IS NOT NULL)),
    UNIQUE (newsletter_id, number)
);
-- At most one draft per newsletter.
CREATE UNIQUE INDEX versions_one_draft ON versions(newsletter_id) WHERE status = 'draft';

CREATE TABLE images (
    hash       TEXT PRIMARY KEY,           -- sha256 of the content
    ext        TEXT NOT NULL,
    mime       TEXT NOT NULL,
    size       INTEGER NOT NULL,
    width      INTEGER,
    height     INTEGER,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE version_images (
    version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
    path       TEXT NOT NULL,              -- path as written in the HTML
    hash       TEXT NOT NULL REFERENCES images(hash), -- an image is never deleted
    PRIMARY KEY (version_id, path)
);
CREATE INDEX version_images_hash ON version_images(hash);
