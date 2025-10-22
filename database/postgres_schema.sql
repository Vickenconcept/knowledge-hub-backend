

-- ========================
-- CACHE TABLE
-- ========================
CREATE TABLE cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

-- ========================
-- CACHE LOCKS TABLE
-- ========================
CREATE TABLE cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

-- ========================
-- JOBS TABLE
-- ========================
CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);

CREATE INDEX jobs_queue_index ON jobs (queue);

-- ========================
-- JOB BATCHES TABLE
-- ========================
CREATE TABLE job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT NULL,
    cancelled_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);

-- ========================
-- FAILED JOBS TABLE
-- ========================
CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

-- ========================
-- PERSONAL ACCESS TOKENS TABLE
-- ========================
CREATE TABLE personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT NOT NULL,
    name TEXT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    abilities TEXT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index 
    ON personal_access_tokens (tokenable_type, tokenable_id);
CREATE INDEX personal_access_tokens_expires_at_index ON personal_access_tokens (expires_at);

-- ========================
-- ORGANIZATIONS TABLE
-- ========================
CREATE TABLE organizations (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    owner_id BIGINT NULL,
    settings JSON NULL,
    plan VARCHAR(255) DEFAULT 'free' NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT organizations_owner_id_foreign
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ========================
-- PRICING TIERS TABLE
-- ========================
CREATE TABLE pricing_tiers (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    monthly_base_fee DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    cost_markup_multiplier DECIMAL(4, 2) DEFAULT 2.0 NOT NULL,
    max_users INTEGER NULL,
    max_documents INTEGER NULL,
    max_chat_queries_per_month INTEGER NULL,
    max_storage_gb INTEGER NULL,
    max_monthly_spend DECIMAL(10, 2) NULL,
    custom_connectors BOOLEAN DEFAULT false NOT NULL,
    priority_support BOOLEAN DEFAULT false NOT NULL,
    api_access BOOLEAN DEFAULT false NOT NULL,
    white_label BOOLEAN DEFAULT false NOT NULL,
    is_active BOOLEAN DEFAULT true NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================
-- ORGANIZATION BILLING TABLE
-- ========================
CREATE TABLE organization_billing (
    id UUID PRIMARY KEY,
    org_id UUID UNIQUE NOT NULL,
    pricing_tier_id UUID NOT NULL,
    billing_cycle VARCHAR(20) DEFAULT 'monthly' NOT NULL,
    current_period_start DATE NOT NULL,
    current_period_end DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'active' NOT NULL,
    payment_method VARCHAR(255) NULL,
    payment_provider_customer_id VARCHAR(255) NULL,
    alert_threshold_percent DECIMAL(5, 2) DEFAULT 80.00 NOT NULL,
    auto_suspend_on_limit BOOLEAN DEFAULT false NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT organization_billing_org_id_foreign
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT organization_billing_pricing_tier_id_foreign
        FOREIGN KEY (pricing_tier_id) REFERENCES pricing_tiers(id) ON DELETE RESTRICT,
    CONSTRAINT organization_billing_billing_cycle_check
        CHECK (billing_cycle IN ('monthly', 'annual')),
    CONSTRAINT organization_billing_status_check
        CHECK (status IN ('active', 'past_due', 'canceled', 'suspended'))
);

-- ========================
-- INVOICES TABLE
-- ========================
CREATE TABLE invoices (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    invoice_number VARCHAR(255) UNIQUE NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    infrastructure_cost DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    markup_amount DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    base_subscription_fee DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    total_amount DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    total_chat_queries INTEGER DEFAULT 0 NOT NULL,
    total_documents INTEGER DEFAULT 0 NOT NULL,
    total_embeddings INTEGER DEFAULT 0 NOT NULL,
    total_vector_queries INTEGER DEFAULT 0 NOT NULL,
    status VARCHAR(20) DEFAULT 'draft' NOT NULL,
    issued_at DATE NULL,
    paid_at DATE NULL,
    due_date DATE NULL,
    payment_method VARCHAR(255) NULL,
    payment_transaction_id VARCHAR(255) NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT invoices_org_id_foreign
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT invoices_status_check
        CHECK (status IN ('draft', 'issued', 'paid', 'overdue', 'void'))
);

CREATE INDEX invoices_org_id_index ON invoices (org_id);

-- ========================
-- REVENUE TRACKING TABLE
-- ========================
CREATE TABLE revenue_tracking (
    id UUID PRIMARY KEY,
    date DATE UNIQUE NOT NULL,
    total_revenue DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    subscription_revenue DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    usage_revenue DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    total_costs DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    openai_costs DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    pinecone_costs DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    other_infrastructure_costs DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    gross_profit DECIMAL(10, 2) DEFAULT 0 NOT NULL,
    profit_margin_percent DECIMAL(5, 2) DEFAULT 0 NOT NULL,
    active_organizations INTEGER DEFAULT 0 NOT NULL,
    total_queries_processed INTEGER DEFAULT 0 NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX revenue_tracking_date_index ON revenue_tracking (date);

-- ========================
-- CONNECTORS TABLE
-- ========================
CREATE TABLE connectors (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    type VARCHAR(255) NOT NULL,
    label VARCHAR(255) NULL,
    encrypted_tokens TEXT NULL,
    metadata JSON NULL,
    status VARCHAR(255) DEFAULT 'disconnected' NOT NULL,
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX connectors_org_id_index ON connectors (org_id);

-- ========================
-- DOCUMENTS TABLE
-- ========================
CREATE TABLE documents (
    id UUID PRIMARY KEY,
    external_id VARCHAR(255) NULL,
    org_id UUID NOT NULL,
    connector_id UUID NULL,
    title VARCHAR(255) NULL,
    source_url TEXT NULL,
    mime_type VARCHAR(255) NULL,
    doc_type VARCHAR(255) NULL,
    metadata JSON NULL,
    summary TEXT NULL,
    tags JSON NULL,
    sha256 VARCHAR(255) NULL,
    size BIGINT NULL,
    s3_path VARCHAR(255) NULL,
    fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX documents_org_id_index ON documents (org_id);
CREATE INDEX documents_connector_id_index ON documents (connector_id);
CREATE INDEX documents_doc_type_index ON documents (doc_type);
CREATE INDEX documents_sha256_index ON documents (sha256);
CREATE INDEX idx_documents_org_connector ON documents (org_id, connector_id);
CREATE INDEX documents_org_id_connector_id_external_id_index 
    ON documents (org_id, connector_id, external_id);

COMMENT ON COLUMN documents.doc_type IS 'Auto-detected: resume, report, contract, presentation, spreadsheet, code, etc.';
COMMENT ON COLUMN documents.metadata IS 'Extracted entities, dates, keywords, categories, etc.';
COMMENT ON COLUMN documents.summary IS 'AI-generated summary of document content';
COMMENT ON COLUMN documents.tags IS 'Auto-extracted or user-defined tags';

-- ========================
-- CHUNKS TABLE
-- ========================
CREATE TABLE chunks (
    id UUID PRIMARY KEY,
    document_id UUID NOT NULL,
    org_id UUID NOT NULL,
    chunk_index INTEGER DEFAULT 0 NOT NULL,
    text TEXT NOT NULL,
    char_start INTEGER DEFAULT 0 NOT NULL,
    char_end INTEGER DEFAULT 0 NOT NULL,
    token_count INTEGER DEFAULT 0 NOT NULL,
    embedding BYTEA NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX chunks_document_id_index ON chunks (document_id);
CREATE INDEX chunks_org_id_index ON chunks (org_id);

-- ========================
-- INGEST JOBS TABLE
-- ========================
CREATE TABLE ingest_jobs (
    id UUID PRIMARY KEY,
    connector_id UUID NOT NULL,
    org_id UUID NOT NULL,
    status VARCHAR(255) DEFAULT 'queued' NOT NULL,
    stats JSON NULL,
    error_log TEXT NULL,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX ingest_jobs_connector_id_index ON ingest_jobs (connector_id);
CREATE INDEX ingest_jobs_org_id_index ON ingest_jobs (org_id);

-- ========================
-- QUERIES TABLE
-- ========================
CREATE TABLE queries (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    user_id UUID NOT NULL,
    query_text TEXT NOT NULL,
    top_k INTEGER DEFAULT 6 NOT NULL,
    result_chunk_ids JSON NULL,
    model_used VARCHAR(255) NULL,
    cost_estimate DECIMAL(10, 4) NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE INDEX queries_org_id_index ON queries (org_id);
CREATE INDEX queries_user_id_index ON queries (user_id);

-- ========================
-- QUERY LOGS TABLE
-- ========================
CREATE TABLE query_logs (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    user_id BIGINT NOT NULL,
    query_text TEXT NOT NULL,
    top_k INTEGER DEFAULT 5 NOT NULL,
    result_count INTEGER DEFAULT 0 NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT query_logs_org_id_foreign
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT query_logs_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX query_logs_org_id_created_at_index ON query_logs (org_id, created_at);

-- ========================
-- CONVERSATIONS TABLE
-- ========================
CREATE TABLE conversations (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    user_id BIGINT NOT NULL,
    title VARCHAR(255) NULL,
    response_style VARCHAR(255) DEFAULT 'comprehensive' NOT NULL,
    preferences JSON NULL,
    last_message_at TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT conversations_org_id_foreign
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT conversations_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX conversations_org_id_user_id_last_message_at_index 
    ON conversations (org_id, user_id, last_message_at);

COMMENT ON COLUMN conversations.response_style IS 'Response format: comprehensive, structured_profile, summary_report, qa_friendly, bullet_brief, etc.';
COMMENT ON COLUMN conversations.preferences IS 'Detail level, tone, include_sources, max_length, etc.';

-- ========================
-- MESSAGES TABLE
-- ========================
CREATE TABLE messages (
    id UUID PRIMARY KEY,
    conversation_id UUID NOT NULL,
    role VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    sources JSON NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT messages_conversation_id_foreign
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT messages_role_check
        CHECK (role IN ('user', 'assistant'))
);

CREATE INDEX messages_conversation_id_created_at_index ON messages (conversation_id, created_at);

-- ========================
-- CONVERSATION SUMMARIES TABLE
-- ========================
CREATE TABLE conversation_summaries (
    id UUID PRIMARY KEY,
    conversation_id UUID NOT NULL,
    user_id BIGINT NOT NULL,
    org_id UUID NOT NULL,
    summary TEXT NOT NULL,
    key_topics JSON NULL,
    entities_mentioned JSON NULL,
    decisions_made JSON NULL,
    message_count INTEGER DEFAULT 0 NOT NULL,
    turn_start INTEGER DEFAULT 0 NOT NULL,
    turn_end INTEGER DEFAULT 0 NOT NULL,
    period_start TIMESTAMP NULL,
    period_end TIMESTAMP NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT conversation_summaries_conversation_id_foreign
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT conversation_summaries_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX conversation_summaries_conversation_id_index ON conversation_summaries (conversation_id);
CREATE INDEX conversation_summaries_user_id_index ON conversation_summaries (user_id);
CREATE INDEX conversation_summaries_org_id_index ON conversation_summaries (org_id);
CREATE INDEX conversation_summaries_created_at_index ON conversation_summaries (created_at);

-- ========================
-- COST TRACKING TABLE
-- ========================
CREATE TABLE cost_tracking (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    user_id BIGINT NULL,
    operation_type VARCHAR(50) DEFAULT 'embedding' NOT NULL,
    model_used VARCHAR(255) NOT NULL,
    provider VARCHAR(255) DEFAULT 'openai' NOT NULL,
    tokens_input INTEGER DEFAULT 0 NOT NULL,
    tokens_output INTEGER DEFAULT 0 NOT NULL,
    total_tokens INTEGER DEFAULT 0 NOT NULL,
    cost_usd DECIMAL(10, 6) DEFAULT 0 NOT NULL,
    document_id UUID NULL,
    conversation_id UUID NULL,
    ingest_job_id UUID NULL,
    query_text TEXT NULL,
    created_at TIMESTAMP NOT NULL,
    CONSTRAINT cost_tracking_org_id_foreign
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT cost_tracking_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT cost_tracking_operation_type_check
        CHECK (operation_type IN ('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion'))
);

CREATE INDEX cost_tracking_org_id_created_at_index ON cost_tracking (org_id, created_at);
CREATE INDEX cost_tracking_operation_type_index ON cost_tracking (operation_type);
CREATE INDEX cost_tracking_org_operation_created_index ON cost_tracking (org_id, operation_type, created_at);

-- ========================
-- FEEDBACK TABLE
-- ========================
CREATE TABLE feedback (
    id BIGSERIAL PRIMARY KEY,
    conversation_id UUID NOT NULL,
    message_id UUID NOT NULL,
    user_id BIGINT NOT NULL,
    rating VARCHAR(10) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT feedback_conversation_id_foreign
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT feedback_message_id_foreign
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT feedback_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT feedback_rating_check
        CHECK (rating IN ('up', 'down')),
    CONSTRAINT feedback_message_id_user_id_unique
        UNIQUE (message_id, user_id)
);

CREATE INDEX feedback_conversation_id_user_id_index ON feedback (conversation_id, user_id);
CREATE INDEX feedback_message_id_user_id_index ON feedback (message_id, user_id);
CREATE INDEX feedback_rating_index ON feedback (rating);
CREATE INDEX feedback_created_at_index ON feedback (created_at);

COMMENT ON COLUMN feedback.rating IS '👍 or 👎 rating';
COMMENT ON COLUMN feedback.comment IS 'Optional feedback comment';

-- ========================================================================================
-- END OF SCHEMA
-- ========================================================================================

