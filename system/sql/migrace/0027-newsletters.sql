-- Newsletters (1.5): one e-mail template styled by the design system (Core\Mailing). The rendered e-mail is kept from
-- the start of sending, so every subscriber gets the same content. The queue holds recipients only while sending;
-- a day after a newsletter finishes its rows are deleted and only the counts and dates remain.
CREATE TABLE IF NOT EXISTS ka_newsletters (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject      VARCHAR(200) NOT NULL,
    preheader    VARCHAR(200) NOT NULL DEFAULT '',          -- preview text shown next to the subject in the inbox
    intro        TEXT         NOT NULL,
    news_mode    VARCHAR(10)  NOT NULL DEFAULT 'latest',    -- latest | chosen | none
    news_count   TINYINT UNSIGNED NOT NULL DEFAULT 3,        -- how many of the latest news items
    news_ids     VARCHAR(500) NOT NULL DEFAULT '',          -- chosen news items (idc, comma separated)
    button_label VARCHAR(80)  NOT NULL DEFAULT '',
    button_url   VARCHAR(500) NOT NULL DEFAULT '',
    language     CHAR(2)      NOT NULL DEFAULT '',          -- language of the fixed texts and the news items (empty = the site language)
    status       VARCHAR(10)  NOT NULL DEFAULT 'draft',     -- draft | scheduled | sending | sent
    scheduled_at DATETIME     NULL,
    html         MEDIUMTEXT   NULL,                         -- the rendered e-mail, kept from the start of sending
    text         MEDIUMTEXT   NULL,
    recipients   INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    author       INT UNSIGNED NULL,
    created      DATETIME     NOT NULL,
    changed      DATETIME     NULL,
    started_at   DATETIME     NULL,
    finished_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_newsletters_status (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_newsletter_queue (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    newsletter_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt  DATETIME     NULL,                        -- NULL = done (sent or given up)
    sent_at       DATETIME     NULL,
    error         VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY ux_newsletter_queue (newsletter_id, subscriber_id),
    KEY ix_newsletter_queue_next (next_attempt),
    KEY ix_newsletter_queue_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
