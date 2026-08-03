-- 026: Index customer_cashouts.created_at.
-- The daily cashout ledger (cashout_daily_totals in includes/cashouts.php) filters
-- cashouts by their created date for the RDC balance / depot day-close. It had no
-- index on created_at, so that day lookup was a full table scan. This index turns
-- it into an index range scan. Backed by the range-predicate query rewrite shipped
-- with this change.

ALTER TABLE customer_cashouts
    ADD INDEX idx_cashout_created (created_at);
