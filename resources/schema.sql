-- Good Community single-plugin installation schema, 2026-10-31.
-- Applied by Infrastructure/Schema.php; upgrades use versioned migrations.
-- Target: MySQL 5.7.44 / InnoDB / utf8mb4 / strict SQL mode / UTC connection.
-- lgc_ is the plugin prefix; migrations also apply Flarum's configured prefix.
-- UUIDs are encoded/decoded in PHP as BINARY(16); no UUID_TO_BIN dependency.
-- JSON columns have no DEFAULT; services explicitly supply validated documents.
-- Names, states, nonnegative amounts and typed-value slots require application validation.
-- No CHECK constraint is relied upon. No SKIP LOCKED, CTE or window function is used.
-- Flarum user/post/group IDs are INT UNSIGNED and deliberately have no cross-plugin FK.
-- Every internal FK uses RESTRICT. Historical records are not cascade-deleted.
-- Tables appear in creation order. No database, account, seed or destructive statement.

-- T01 Currency definitions and ledger-maintained actual supply.
CREATE TABLE lgc_currencies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    icon JSON NULL,
    resource_kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    rate_numerator BIGINT UNSIGNED NULL,
    rate_denominator BIGINT UNSIGNED NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    metadata_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY currency_uuid (public_id),
    UNIQUE KEY currency_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T02 Ordinary wallets. No reserved balance or separate task-budget account.
CREATE TABLE lgc_wallets (
    user_id INT UNSIGNED NOT NULL,
    currency_id BIGINT UNSIGNED NOT NULL,
    balance BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id,currency_id), KEY currency_wallets (currency_id,user_id),
    CONSTRAINT lgc_wallet_currency FOREIGN KEY (currency_id) REFERENCES lgc_currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T03 A transaction and its entries commit together. pending never commits on success.
CREATE TABLE lgc_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    related_post_id INT UNSIGNED NULL,
    source_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reversal_of BIGINT UNSIGNED NULL,
    posted_at DATETIME(6) NULL,
    PRIMARY KEY (id), UNIQUE KEY transaction_uuid (public_id),
    UNIQUE KEY transaction_operation (operation_key), UNIQUE KEY one_reversal (reversal_of),
    KEY post_tips (related_post_id,kind,id), KEY actor_transactions (actor_id,id), KEY source_transactions (source_key,id),
    CONSTRAINT lgc_transaction_reversal FOREIGN KEY (reversal_of) REFERENCES lgc_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T04 Signed whole-unit entries; a transfer has zero net delta per currency.
CREATE TABLE lgc_entries (
    transaction_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    currency_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    delta BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    PRIMARY KEY (transaction_id,line_no),
    KEY wallet_activity (user_id,transaction_id,line_no), KEY wallet_entries (user_id,currency_id,transaction_id,line_no),
    KEY currency_entries (currency_id,transaction_id,line_no),
    CONSTRAINT lgc_entry_transaction FOREIGN KEY (transaction_id) REFERENCES lgc_transactions(id),
    CONSTRAINT lgc_entry_currency FOREIGN KEY (currency_id) REFERENCES lgc_currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T05 Task folders. parent_key enforces sibling-name uniqueness at the NULL root.
CREATE TABLE lgc_folders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    parent_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(parent_id,0)) STORED,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    path VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    depth TINYINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id), UNIQUE KEY folder_uuid (public_id),
    UNIQUE KEY sibling_name (parent_key,name_key),
    KEY folder_children (parent_id,sort_order,id), KEY folder_subtree (path),
    CONSTRAINT lgc_folder_parent FOREIGN KEY (parent_id) REFERENCES lgc_folders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T06 Task metadata and immutable-after-publication schedule.
CREATE TABLE lgc_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    comment VARCHAR(1000) NOT NULL,
    folder_id BIGINT UNSIGNED NULL,
    associate_post_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    start_at DATETIME NOT NULL,
    stop_at DATETIME NULL,
    period_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(12) CHARACTER SET ascii NOT NULL,
    published_at DATETIME(6) NULL,
    latest_version INT UNSIGNED NOT NULL DEFAULT 0,
    draft_version INT UNSIGNED NULL,
    is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0,
    statistics_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    statistics_building_generation BIGINT UNSIGNED NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id), UNIQUE KEY task_uuid (public_id), UNIQUE KEY task_name (name_key),
    KEY active_tasks (status,start_at,id), KEY pinned_tasks (status,is_pinned,id), KEY folder_tasks (folder_id,status,id),
    CONSTRAINT lgc_task_folder FOREIGN KEY (folder_id) REFERENCES lgc_folders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Published rule versions; one receipt per user and period.
CREATE TABLE lgc_task_versions (
    task_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    effective_from_occurrence BIGINT UNSIGNED NULL,
    goal JSON NOT NULL,
    reward JSON NOT NULL,
    reward_access JSON NULL,
    funding_user_id INT UNSIGNED NOT NULL,
    audience JSON NOT NULL,
    definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    PRIMARY KEY (task_id,version),
    KEY effective_rule (task_id,state,effective_from_occurrence,version),
    CONSTRAINT lgc_task_version_task FOREIGN KEY (task_id) REFERENCES lgc_tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T08 One shared period per task and occurrence, created lazily with a sealed rule version.
CREATE TABLE lgc_task_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    occurrence BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY task_occurrence (task_id,occurrence), UNIQUE KEY task_period_identity (task_id,id),
    KEY period_end (ends_at,id), KEY period_version (task_id,version),
    CONSTRAINT lgc_period_version FOREIGN KEY (task_id,version) REFERENCES lgc_task_versions(task_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable receipts; administrative revocation changes validity only.
CREATE TABLE lgc_task_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    request_id BINARY(16) NOT NULL,
    transaction_id BIGINT UNSIGNED NULL,
    receipt JSON NOT NULL,
    evaluation JSON NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    validity_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    stats_snapshot JSON NULL,
    claimed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY user_period (user_id,period_id),
    UNIQUE KEY claim_request (user_id,request_id),
    KEY completed_task_lookup (task_id,user_id,active,id),
    KEY claimed_task (task_id,id), KEY user_history (user_id,claimed_at,id),
    KEY claim_transaction (transaction_id),
    CONSTRAINT lgc_claim_period FOREIGN KEY (task_id,period_id) REFERENCES lgc_task_periods(task_id,id),
    CONSTRAINT lgc_claim_transaction FOREIGN KEY (transaction_id) REFERENCES lgc_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T33 Sparse display-statistics projection members, rebuilt independently from money.
CREATE TABLE lgc_task_stat_members (
    task_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    active_claims BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (task_id,scope_key,generation,user_id),
    CONSTRAINT lgc_stat_member_task FOREIGN KEY (task_id) REFERENCES lgc_tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T34 Aggregate display counters. Financial/eligibility paths never read this projection.
CREATE TABLE lgc_task_statistics (
    task_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    completed_users BIGINT UNSIGNED NOT NULL DEFAULT 0,
    successful_claims BIGINT UNSIGNED NOT NULL DEFAULT 0,
    active_claims BIGINT UNSIGNED NOT NULL DEFAULT 0,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    as_of DATETIME(6) NOT NULL,
    PRIMARY KEY (task_id,scope_key,generation),
    CONSTRAINT lgc_task_stat_task FOREIGN KEY (task_id) REFERENCES lgc_tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T11 Immutable parameterized boolean methods, referenced by UUID in every goal.
CREATE TABLE lgc_atoms (
    atom_id BINARY(16) NOT NULL,
    atom_method VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    params JSON NOT NULL,
    definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (atom_id), UNIQUE KEY atom_definition (definition_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T12 Shared reverse routing for rules and queries; JSON references are validated at publication.
CREATE TABLE lgc_bindings (
    owner_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    owner_version INT UNSIGNED NOT NULL,
    dependency_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (owner_kind,owner_id,owner_version,dependency_key),
    KEY dependency_owners (dependency_key,owner_kind,owner_id,owner_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T13 First successfully observed lifetime events. Current profile state is read separately.
CREATE TABLE lgc_user_facts (
    user_id INT UNSIGNED NOT NULL,
    fact_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    source_event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence JSON NOT NULL,
    PRIMARY KEY (user_id,fact_key), KEY fact_time (fact_key,occurred_at,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T14 Shared base filters (two tag namespaces, guarded missing values and scope),
-- excluding actual user, resolved interval and outer threshold. See QUERY-DESIGN.md.
CREATE TABLE lgc_queries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    spec JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY query_definition (definition_hash),
    KEY query_source (source_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T15 Observed per-user source sequence; coverage does not certify unobserved external SQL.
CREATE TABLE lgc_source_states (
    source_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    coverage_from DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_key,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T16 A query result for one user and exact interval. bounds_hash includes NULL boundaries.
CREATE TABLE lgc_counter_windows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    query_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    bounds_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    PRIMARY KEY (id), UNIQUE KEY query_user_bounds (query_id,user_id,bounds_hash),
    KEY user_counters (user_id,query_id,id),
    KEY expired_counters (expires_at,id),
    CONSTRAINT lgc_counter_query FOREIGN KEY (query_id) REFERENCES lgc_queries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T17 Versioned 0/1 contribution; unprepared/unavailable is represented by window state.
CREATE TABLE lgc_counter_contributions (
    counter_id BIGINT UNSIGNED NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    seen_generation BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (counter_id,object_id),
    KEY unseen_contributions (counter_id,seen_generation,object_id),
    CONSTRAINT lgc_contribution_counter FOREIGN KEY (counter_id) REFERENCES lgc_counter_windows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T18 Moderator-owned post/reply fields, independent of users' numeric ratings.
CREATE TABLE lgc_post_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    description VARCHAR(2000) NOT NULL,
    value_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
    unit VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    options JSON NOT NULL,
    rule_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id), UNIQUE KEY post_field_uuid (public_id),
    UNIQUE KEY post_field_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T31 Voting eligibility policies. Scopes are compiled into T12 reverse bindings.
-- Priority is unique; a per-post override takes precedence over matching policies.
CREATE TABLE lgc_rating_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    scope_spec JSON NOT NULL,
    voter_kind VARCHAR(12) CHARACTER SET ascii NOT NULL,
    group_ids JSON NOT NULL,
    priority INT NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY rating_policy_uuid (public_id),
    UNIQUE KEY rating_policy_name (name_key), UNIQUE KEY rating_policy_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T19 One content/aggregate state per post; average = sum_hundredths / (100 * count).
-- Retained votes remain active after a content edit. Separate versions isolate marks from votes.
CREATE TABLE lgc_post_state (
    post_id INT UNSIGNED NOT NULL,
    rating_policy_id BIGINT UNSIGNED NULL,
    content_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    rating_sum_hundredths BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rating_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    marks_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (post_id),
    KEY post_rating_policy (rating_policy_id),
    CONSTRAINT lgc_post_state_policy FOREIGN KEY (rating_policy_id) REFERENCES lgc_rating_policies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T20 Exactly one typed slot is populated. Missing row means explicitly unset.
-- integer_value also holds booleans, epoch milliseconds and duration milliseconds.
CREATE TABLE lgc_post_field_values (
    post_id INT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    integer_value BIGINT NULL,
    decimal_value DECIMAL(30,9) NULL,
    text_value VARCHAR(255) NULL,
    reference_value BINARY(16) NULL,
    set_value JSON NULL,
    PRIMARY KEY (post_id,field_id),
    KEY field_integer (field_id,integer_value,post_id),
    KEY field_decimal (field_id,decimal_value,post_id),
    KEY field_reference (field_id,reference_value,post_id),
    CONSTRAINT lgc_post_value_post FOREIGN KEY (post_id) REFERENCES lgc_post_state(post_id),
    CONSTRAINT lgc_post_value_field FOREIGN KEY (field_id) REFERENCES lgc_post_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T21 Moderator-field history, isolated from individual numeric-rating histories.
CREATE TABLE lgc_post_field_history (
    post_id INT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    action VARCHAR(16) CHARACTER SET ascii NOT NULL,
    snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (post_id,version), UNIQUE KEY post_field_operation (operation_key),
    CONSTRAINT lgc_post_field_history_post FOREIGN KEY (post_id) REFERENCES lgc_post_state(post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T29 One numeric rating per post/user, including withdrawal tombstones.
-- score_hundredths in [0,10000] encodes [0,100] with exactly two decimal places.
CREATE TABLE lgc_post_votes (
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    score_hundredths SMALLINT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    rated_content_version BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (post_id,user_id),
    KEY user_votes (user_id,post_id),
    KEY active_post_voters (post_id,state,user_id),
    CONSTRAINT lgc_vote_post FOREIGN KEY (post_id) REFERENCES lgc_post_state(post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T30 Append-only per-user rating snapshots and retry receipts.
CREATE TABLE lgc_vote_history (
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    action VARCHAR(16) CHARACTER SET ascii NOT NULL,
    score_hundredths SMALLINT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    rated_content_version BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    receipt JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (post_id,user_id,version), UNIQUE KEY vote_operation (operation_key),
    CONSTRAINT lgc_vote_history_vote FOREIGN KEY (post_id,user_id) REFERENCES lgc_post_votes(post_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T22 Achievement metadata. Badge definitions, ownership and wearing are intentionally absent.
CREATE TABLE lgc_achievements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id BINARY(16) NOT NULL,
    name VARCHAR(150) NOT NULL,
    name_key VARCHAR(150) COLLATE utf8mb4_bin NOT NULL,
    description TEXT NOT NULL,
    icon JSON NULL,
    status VARCHAR(12) CHARACTER SET ascii NOT NULL,
    latest_version INT UNSIGNED NOT NULL DEFAULT 0,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY achievement_uuid (public_id),
    UNIQUE KEY achievement_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T23 Automatic achievements reuse goal/atoms; manual achievements have a NULL goal.
CREATE TABLE lgc_achievement_versions (
    achievement_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii NOT NULL,
    reward_access JSON NULL,
    mode VARCHAR(12) CHARACTER SET ascii NOT NULL,
    goal JSON NULL,
    policies JSON NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    PRIMARY KEY (achievement_id,version),
    CONSTRAINT lgc_achievement_version FOREIGN KEY (achievement_id) REFERENCES lgc_achievements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T24 Effective profile display; active_sources > 0 means the user holds the achievement.
CREATE TABLE lgc_achievement_grants (
    user_id INT UNSIGNED NOT NULL,
    achievement_id BIGINT UNSIGNED NOT NULL,
    active_sources INT UNSIGNED NOT NULL DEFAULT 0,
    earned_at DATETIME(6) NULL,
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id,achievement_id),
    KEY profile_achievements (user_id,earned_at,achievement_id),
    KEY achievement_holders (achievement_id,user_id), KEY achievement_population (achievement_id,active_sources,user_id),
    CONSTRAINT lgc_grant_achievement FOREIGN KEY (achievement_id) REFERENCES lgc_achievements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T25 Independent manual/automatic sources; revoke only the selected source.
CREATE TABLE lgc_achievement_sources (
    user_id INT UNSIGNED NOT NULL,
    achievement_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rule_version INT UNSIGNED NULL,
    active TINYINT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    evidence JSON NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (user_id,achievement_id,source_key),
    KEY achievement_sources (achievement_id,active,user_id),
    KEY achievement_source_rule (achievement_id,rule_version),
    CONSTRAINT lgc_source_grant FOREIGN KEY (user_id,achievement_id)
        REFERENCES lgc_achievement_grants(user_id,achievement_id),
    CONSTRAINT lgc_source_achievement_version FOREIGN KEY (achievement_id,rule_version)
        REFERENCES lgc_achievement_versions(achievement_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T26 Immutable business events and transactional Outbox. Payload hash protects key reuse.
CREATE TABLE lgc_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    actor_id INT UNSIGNED NULL,
    subject_user_id INT UNSIGNED NULL,
    aggregate_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_version BIGINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY business_event (event_key),
    KEY aggregate_history (aggregate_key,aggregate_version,id),
    KEY subject_events (subject_user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T27 Restartable work: events, scans, time boundaries, preparation and notification fanout.
CREATE TABLE lgc_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lane TINYINT UNSIGNED NOT NULL DEFAULT 1,
    job_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    payload JSON NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii NOT NULL,
    available_at DATETIME(6) NOT NULL,
    lease_token BINARY(16) NULL,
    lease_until DATETIME(6) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(64) CHARACTER SET ascii NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY job_identity (job_key),
    KEY runnable_jobs (status,lane,available_at,id), KEY expired_leases (status,lease_until,id),
    KEY event_jobs (event_id,id),
    CONSTRAINT lgc_job_event FOREIGN KEY (event_id) REFERENCES lgc_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T28 Delivery receipts reference native notifications; they are not another inbox.
CREATE TABLE lgc_notification_deliveries (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel VARCHAR(12) CHARACTER SET ascii NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii NOT NULL,
    native_notification_id INT UNSIGNED NULL,
    delivered_at DATETIME(6) NULL,
    PRIMARY KEY (event_id,user_id,notification_type,channel),
    KEY user_deliveries (user_id,event_id),
    CONSTRAINT lgc_delivery_event FOREIGN KEY (event_id) REFERENCES lgc_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lgc_access_sources (
    user_id INT UNSIGNED NOT NULL,
    source_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    issuer_id INT UNSIGNED NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    granted_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (user_id,source_key,group_id), KEY access_active (user_id,group_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE lgc_access_memberships (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    preexisting TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id,group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lgc_reward_group_policies (
    grantor_group_id INT UNSIGNED NOT NULL,
    reward_group_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (grantor_group_id,reward_group_id),
    KEY reward_grantors (reward_group_id,grantor_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
