-- Collection items as full pages (1.9): their own SEO title, description, share image, noindex and scheduled publishing,
-- like pages. Versions of items go to ka_stavba_revize under cast = 'polozka:<idp>'. A collection gets its structured
-- data type (Service, Person, Product, Event, FAQPage) and which fields fill its properties.
ALTER TABLE ka_kolekce_polozky
    ADD COLUMN seo_titulek VARCHAR(200) NOT NULL DEFAULT '' AFTER data,
    ADD COLUMN popis VARCHAR(300) NOT NULL DEFAULT '' AFTER seo_titulek,
    ADD COLUMN obrazek VARCHAR(255) NOT NULL DEFAULT '' AFTER popis,
    ADD COLUMN noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER obrazek,
    ADD COLUMN zverejnit_od DATETIME NULL AFTER zobrazit,
    ADD KEY ix_kolekce_polozky_zverejnit (zverejnit_od);
ALTER TABLE ka_kolekce ADD COLUMN schema_org TEXT NULL AFTER detail;
