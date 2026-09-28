-- Webhook deliveries (1.8, Core\Webhook): every webhook call is logged, a failed one is retried (1, 5, 30 minutes,
-- 2 and 12 hours). The body of a delivered call is not kept; records are deleted after 30 days.
CREATE TABLE IF NOT EXISTS ka_webhook_deliveries (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    event        VARCHAR(40)       NOT NULL,
    url          VARCHAR(500)      NOT NULL,
    body         MEDIUMTEXT        NULL,             -- JSON sent; NULL after a successful delivery
    attempts     TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    status       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- HTTP status of the last attempt (0 = no response)
    error        VARCHAR(255)      NOT NULL DEFAULT '',
    created      DATETIME          NOT NULL,
    next_attempt DATETIME          NULL,             -- NULL = nothing more to do (delivered or given up)
    delivered    DATETIME          NULL,
    PRIMARY KEY (id),
    KEY next_attempt (next_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
