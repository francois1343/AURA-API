ALTER TABLE jobs
    ADD COLUMN IF NOT EXISTS labour_market JSON NULL AFTER verified_at;
