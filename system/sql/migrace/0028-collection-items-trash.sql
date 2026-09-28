-- Collection items get a trash like pages and news: deleting hides the item and keeps it 30 days (1.6).
ALTER TABLE ka_kolekce_polozky ADD COLUMN smazano DATETIME NULL AFTER zmeneno;
