-- Addresses not found (404) can be ignored (1.9): a probe of a bot or an address nobody needs any more leaves the
-- warning and the list for good; a new address brings the warning back.
ALTER TABLE ka_nenalezeno ADD COLUMN ignorovano DATETIME NULL AFTER naposledy;
