#
# AI Logs reads sys_log filtered by channel + tstamp range, ordered by tstamp.
# Core ships no index leading (channel, tstamp); without it every log-tab
# render filesorts a shared, heavily-written table (PF-01).
#
CREATE TABLE sys_log (
    KEY nst3af_channel_tstamp (channel, tstamp)
);

CREATE TABLE tx_nst3af_provider (
    identifier VARCHAR(64) NOT NULL DEFAULT '',
    title VARCHAR(255) NOT NULL DEFAULT '',
    adapter_type VARCHAR(64) NOT NULL DEFAULT '',
    endpoint_url VARCHAR(255) NOT NULL DEFAULT '',
    api_key TEXT,
    model_id VARCHAR(128) NOT NULL DEFAULT '',
    embedding_model_id VARCHAR(128) NOT NULL DEFAULT '',
    capabilities VARCHAR(255) NOT NULL DEFAULT '',
    temperature DECIMAL(3,2) DEFAULT 0.70,
    system_prompt TEXT,
    is_default TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    priority INT(11) DEFAULT 50 NOT NULL,
    last_used_at INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    last_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
    last_status_at INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    last_status_message TEXT,
    be_groups VARCHAR(255) NOT NULL DEFAULT '',
    is_enabled TINYINT(1) UNSIGNED DEFAULT 1 NOT NULL,
    enabled_for_dashboard TINYINT(1) UNSIGNED DEFAULT 1 NOT NULL,
    pricing_input_per_1m DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    pricing_output_per_1m DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    pricing_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    retention_days_override SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,
    cost_center VARCHAR(64) NOT NULL DEFAULT '',
    privacy_level VARCHAR(16) NOT NULL DEFAULT 'standard',
    no_rerouting TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    UNIQUE KEY provider_identifier_per_site (pid, identifier),
    KEY is_default (is_default),
    KEY is_enabled (is_enabled)
);

CREATE TABLE tx_nst3af_request_log (
    uid INT(11) NOT NULL AUTO_INCREMENT,
    pid INT(11) DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    provider_uid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    provider_identifier VARCHAR(64) NOT NULL DEFAULT '',
    extension_key VARCHAR(64) NOT NULL DEFAULT 'unknown',
    feature_key VARCHAR(128) NOT NULL DEFAULT 'default',
    feature_label VARCHAR(255) NOT NULL DEFAULT '',
    request_source VARCHAR(32) NOT NULL DEFAULT 'unknown',
    content_entity_type VARCHAR(64) NOT NULL DEFAULT '',
    content_entity_uid INT(11) DEFAULT 0 NOT NULL,
    request_type VARCHAR(16) NOT NULL DEFAULT 'complete',
    model_requested VARCHAR(128) NOT NULL DEFAULT '',
    model_used VARCHAR(128) NOT NULL DEFAULT '',
    success TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    error_class VARCHAR(255) NOT NULL DEFAULT '',
    prompt_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
    prompt_tokens INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    completion_tokens INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    total_tokens INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    latency_ms INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    cached TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    estimated_cost DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    credits_used DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    be_user_id INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    brand_context_profile_uid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    quality_score SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,
    quality_dimensions JSON DEFAULT NULL,
    raw_meta MEDIUMTEXT,
    PRIMARY KEY (uid),
    KEY req_crdate (crdate),
    KEY req_beuser_time (be_user_id, crdate),
    KEY req_provider_time (provider_identifier, crdate),
    KEY req_success_time (success, crdate),
    KEY req_model_time (model_used, crdate),
    KEY req_extension_time (extension_key, crdate),
    KEY req_feature_time (feature_key, crdate),
    KEY req_provideruid_time (provider_uid, crdate)
);

CREATE TABLE tx_nst3af_extension_setting (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    extension_key VARCHAR(64) NOT NULL DEFAULT '',
    settings_json MEDIUMTEXT,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY extension_setting_per_site (pid, extension_key),
    KEY pid (pid)
);

CREATE TABLE tx_nst3af_runtime_setting (
    uid INT(11) NOT NULL AUTO_INCREMENT,
    credit_mode TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    selected_license_ext_key VARCHAR(64) NOT NULL DEFAULT 'ns_t3af',
    license_keys VARCHAR(1024) NOT NULL DEFAULT '',
    token_enc TEXT,
    credits_domain VARCHAR(255) NOT NULL DEFAULT '',
    t3planet_api_base_url VARCHAR(255) NOT NULL DEFAULT '',
    activated_at INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    last_balance_synced INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    wizard_completed TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    wizard_last_step TINYINT(2) UNSIGNED DEFAULT 1 NOT NULL,
    wizard_max_step TINYINT(2) UNSIGNED DEFAULT 1 NOT NULL,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_nst3af_credit_receipt (
    uid INT(11) NOT NULL AUTO_INCREMENT,
    request_uuid VARCHAR(64) NOT NULL DEFAULT '',
    feature_key VARCHAR(64) NOT NULL DEFAULT '',
    model VARCHAR(96) NOT NULL DEFAULT '',
    bucket VARCHAR(16) NOT NULL DEFAULT '',
    cost_units INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    cost DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    balance_free DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    balance_paid DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    plan_used DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    plan_total DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    extra MEDIUMTEXT,
    PRIMARY KEY (uid),
    UNIQUE KEY request_uuid (request_uuid),
    KEY crdate (crdate)
);

CREATE TABLE tx_nst3af_product_catalog (
    uid INT(11) NOT NULL DEFAULT 1,
    etag VARCHAR(128) NOT NULL DEFAULT '',
    body_json MEDIUMTEXT,
    fetched_at INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_nst3af_oauth_client (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    client_id VARCHAR(128) DEFAULT '' NOT NULL,
    client_name VARCHAR(255) DEFAULT '' NOT NULL,
    redirect_uris TEXT,
    be_user INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY client_id (client_id),
    KEY be_user (be_user)
);

CREATE TABLE tx_nst3af_oauth_token (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    token_type VARCHAR(32) DEFAULT 'bearer' NOT NULL,
    access_token_hash VARCHAR(64) DEFAULT '' NOT NULL,
    refresh_token_hash VARCHAR(64) DEFAULT '' NOT NULL,
    client_id VARCHAR(128) DEFAULT '' NOT NULL,
    be_user INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    workspace_id INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    scope VARCHAR(255) DEFAULT '' NOT NULL,
    label VARCHAR(255) DEFAULT '' NOT NULL,
    access_token_expires INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    refresh_token_expires INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    revoked TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    last_used_at INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY access_token_hash (access_token_hash),
    KEY refresh_token_hash (refresh_token_hash),
    KEY be_user (be_user),
    KEY token_type (token_type)
);

CREATE TABLE tx_nst3af_oauth_code (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    authorization_code_hash VARCHAR(64) DEFAULT '' NOT NULL,
    client_id VARCHAR(128) DEFAULT '' NOT NULL,
    be_user INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    code_challenge VARCHAR(128) DEFAULT '' NOT NULL,
    code_challenge_method VARCHAR(10) DEFAULT 'S256' NOT NULL,
    redirect_uri VARCHAR(2048) DEFAULT '' NOT NULL,
    scope VARCHAR(255) DEFAULT '' NOT NULL,
    workspace_id INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    code_expires INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    revoked TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY authorization_code_hash (authorization_code_hash),
    KEY client_id (client_id)
);

CREATE TABLE tx_nst3af_mcp_session (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    session_id VARCHAR(36) DEFAULT '' NOT NULL,
    token_uid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    data MEDIUMBLOB,
    last_activity INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY session_id (session_id),
    KEY last_activity (last_activity),
    KEY token_uid (token_uid)
);

CREATE TABLE tx_nst3af_oauth_rate_limit (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    ip_address VARCHAR(45) DEFAULT '' NOT NULL,
    endpoint VARCHAR(64) DEFAULT '' NOT NULL,
    hit_count INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    window_start INT(11) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY ip_endpoint_window (ip_address, endpoint, window_start),
    KEY window_start (window_start)
);

CREATE TABLE tx_nst3af_mcp_discovered_table (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name VARCHAR(255) DEFAULT '' NOT NULL,
    label VARCHAR(255) DEFAULT '' NOT NULL,
    prefix VARCHAR(100) DEFAULT '' NOT NULL,
    enabled TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY table_name (table_name)
);

CREATE TABLE tx_nst3af_usage_budget (
    uid INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    period_type VARCHAR(16) NOT NULL DEFAULT 'monthly',
    period_start INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tokens_used INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    cost_used DECIMAL(12,6) DEFAULT 0.000000 NOT NULL,
    requests_used INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY user_period (user_id, period_type),
    KEY user_id (user_id)
);

CREATE TABLE tx_nst3af_mcp_tool_log (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tool_name VARCHAR(128) NOT NULL DEFAULT '',
    handler_name VARCHAR(255) NOT NULL DEFAULT '',
    call_type VARCHAR(16) NOT NULL DEFAULT 'tool',
    token_uid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    client_label VARCHAR(255) NOT NULL DEFAULT '',
    be_user INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    success TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    error_message TEXT,
    latency_ms INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY tool_log_crdate (crdate),
    KEY tool_log_tool_time (tool_name, crdate),
    KEY tool_log_token_time (token_uid, crdate),
    KEY tool_log_success_time (success, crdate),
    KEY tool_log_client_time (client_label, crdate)
);

CREATE TABLE tx_nst3af_mcp_ip_allowlist (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(255) NOT NULL DEFAULT '',
    cidr VARCHAR(64) NOT NULL DEFAULT '',
    enabled TINYINT(1) UNSIGNED DEFAULT 1 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY enabled (enabled)
);

CREATE TABLE tx_nst3af_mcp_custom_tool (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    tool_key VARCHAR(128) NOT NULL DEFAULT '',
    label VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT,
    handler_type VARCHAR(16) NOT NULL DEFAULT 'php',
    handler_value VARCHAR(512) NOT NULL DEFAULT '',
    parameters_json MEDIUMTEXT,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY tool_key (tool_key)
);

CREATE TABLE tx_nst3af_mcp_prompt_template (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL DEFAULT '',
    description TEXT,
    template_body MEDIUMTEXT,
    arguments_json MEDIUMTEXT,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY name (name)
);

CREATE TABLE tx_nst3af_ai_prompt (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    extension_key VARCHAR(64) NOT NULL DEFAULT '',
    category_id VARCHAR(64) NOT NULL DEFAULT '',
    prompt_kind VARCHAR(32) NOT NULL DEFAULT 'global',
    scope VARCHAR(255) NOT NULL DEFAULT '',
    prompt_type VARCHAR(255) NOT NULL DEFAULT '',
    is_default TINYINT(4) DEFAULT 0 NOT NULL,
    prompt_title VARCHAR(255) NOT NULL DEFAULT '',
    prompt_text MEDIUMTEXT,
    PRIMARY KEY (uid),
    KEY extension_category (extension_key, category_id),
    KEY prompt_lookup (extension_key, scope, prompt_type)
);

CREATE TABLE tx_nst3af_mcp_skill (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL DEFAULT '',
    trigger_keyword VARCHAR(64) NOT NULL DEFAULT '',
    version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    source VARCHAR(32) NOT NULL DEFAULT 'local',
    source_url VARCHAR(2048) NOT NULL DEFAULT '',
    body MEDIUMTEXT,
    tags VARCHAR(512) NOT NULL DEFAULT '',
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY trigger_keyword (trigger_keyword)
);

CREATE TABLE tx_nst3af_brand_context_profile (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    brand_name VARCHAR(60) NOT NULL DEFAULT '',
    industry VARCHAR(128) NOT NULL DEFAULT '',
    website_url VARCHAR(255) NOT NULL DEFAULT '',
    tagline VARCHAR(160) NOT NULL DEFAULT '',
    description VARCHAR(500) NOT NULL DEFAULT '',
    tone_tags TEXT,
    voice_notes VARCHAR(300) NOT NULL DEFAULT '',
    personas TEXT,
    content_rules TEXT,
    forbidden_words TEXT,
    keywords TEXT,
    competitors TEXT,
    language_code VARCHAR(16) NOT NULL DEFAULT '',
    sample_content VARCHAR(600) NOT NULL DEFAULT '',
    compliance_notes VARCHAR(400) NOT NULL DEFAULT '',
    document_extract MEDIUMTEXT,
    include_document_in_prompt TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    is_default TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    completeness TINYINT(3) UNSIGNED DEFAULT 0 NOT NULL,
    crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    hidden TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY pid_default (pid, is_default, deleted),
    KEY pid (pid)
);

CREATE TABLE tx_nst3af_group_settings (
    uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    be_group INT(11) UNSIGNED NOT NULL DEFAULT 0,
    limits_json MEDIUMTEXT,
    credit_cap_monthly INT(11) UNSIGNED NOT NULL DEFAULT 0,
    daily_request_cap INT(11) UNSIGNED NOT NULL DEFAULT 0,
    bulk_page_limit INT(11) UNSIGNED NOT NULL DEFAULT 0,
    scheduler_batch_limit INT(11) UNSIGNED NOT NULL DEFAULT 0,
    configured TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    configured_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
    crdate INT(11) UNSIGNED NOT NULL DEFAULT 0,
    tstamp INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY be_group (be_group)
);
