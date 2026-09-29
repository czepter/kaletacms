-- Leads (2.3): where an enquiry or a sign-up came from – the first page of the visit and the site that sent the visitor
-- (remembered only with the visitor's consent to marketing), campaigns and devices in the statistics, per-page head code.
ALTER TABLE ka_poptavky ADD COLUMN vstup VARCHAR(255) NOT NULL DEFAULT '' AFTER stranka;
ALTER TABLE ka_poptavky ADD COLUMN odkud VARCHAR(100) NOT NULL DEFAULT '' AFTER vstup;
ALTER TABLE ka_odberatele ADD COLUMN kampan VARCHAR(255) NOT NULL DEFAULT '' AFTER zdroj;
ALTER TABLE ka_odberatele ADD COLUMN vstup VARCHAR(255) NOT NULL DEFAULT '' AFTER kampan;
ALTER TABLE ka_stranky ADD COLUMN kod_hlavicky TEXT NULL AFTER zverejnit_od;
CREATE TABLE IF NOT EXISTS ka_stat_kampane (
    den      DATE NOT NULL,
    kampan   VARCHAR(255) NOT NULL,
    navstevy INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, kampan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE IF NOT EXISTS ka_stat_zarizeni (
    den      DATE NOT NULL,
    zarizeni VARCHAR(10) NOT NULL,
    navstevy INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, zarizeni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
