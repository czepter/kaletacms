-- What a Claude connection may do (2.2): full (everything the user may), drafts (reads and drafts, never publishes) or
-- read (reads only). Existing connections keep full access. The OAuth code carries the choice from the consent screen.
-- The change log names the connection a change came through.
ALTER TABLE ka_api_tokeny ADD COLUMN access VARCHAR(10) NOT NULL DEFAULT 'full' AFTER druh;
ALTER TABLE ka_oauth_kody ADD COLUMN access VARCHAR(10) NOT NULL DEFAULT 'full' AFTER vyzva;
ALTER TABLE ka_protokol ADD COLUMN via VARCHAR(100) NOT NULL DEFAULT '' AFTER jmeno;
-- New installations get the Claude connection switched on (2.2). A site that never saved its choice of extensions keeps
-- what it had until now: the defaults of 2.1 are written down for it.
INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', 'novinky,poptavky,statistika,presmerovani')
    ON DUPLICATE KEY UPDATE hodnota = IF(hodnota = '', VALUES(hodnota), hodnota);
