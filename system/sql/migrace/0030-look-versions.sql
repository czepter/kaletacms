-- Look versions (1.7, Core\Look): the published look (design system, shared classes, menus) kept before a draft look
-- was published – the last 20, each can come back into the draft.
CREATE TABLE IF NOT EXISTS ka_look_versions (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    data    MEDIUMTEXT   NOT NULL,                  -- {design_system, classes: {name: {styl, css}}, menus: {"location|language": items}}
    summary VARCHAR(500) NOT NULL DEFAULT '',       -- what the publishing changed, in words
    author  INT UNSIGNED NULL,
    created DATETIME     NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
