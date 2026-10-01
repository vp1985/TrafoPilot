CREATE TABLE IF NOT EXISTS llx_hwoscore_audit_event (
    rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL DEFAULT 1,
    event_uuid VARCHAR(36) NOT NULL,
    event_type VARCHAR(128) NOT NULL,
    object_type VARCHAR(64) NULL,
    object_id VARCHAR(128) NULL,
    fk_user_author INTEGER NULL,
    date_event DATETIME NOT NULL,
    payload_json LONGTEXT NULL,
    idempotency_key VARCHAR(191) NULL,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hwoscore_audit_event_uuid (entity, event_uuid),
    UNIQUE KEY uk_hwoscore_audit_idempotency (entity, idempotency_key),
    KEY idx_hwoscore_audit_object (entity, object_type, object_id),
    KEY idx_hwoscore_audit_date (entity, date_event)
) ENGINE=InnoDB;
