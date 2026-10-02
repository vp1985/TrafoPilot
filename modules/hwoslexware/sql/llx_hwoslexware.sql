CREATE TABLE IF NOT EXISTS llx_hwoslexware_run (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 organization_id VARCHAR(36) NOT NULL,
 mode VARCHAR(16) NOT NULL,
 status VARCHAR(24) NOT NULL,
 fk_user INTEGER NULL,
 context VARCHAR(24) NOT NULL,
 checkpoint_json LONGTEXT NOT NULL,
 stats_json LONGTEXT NOT NULL,
 date_creation DATETIME NOT NULL,
 date_finished DATETIME NULL,
 last_error VARCHAR(128) NULL,
 KEY idx_lx_run (entity, organization_id, status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS llx_hwoslexware_resource (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 organization_id VARCHAR(36) NOT NULL,
 resource_type VARCHAR(64) NOT NULL,
 remote_id VARCHAR(128) NOT NULL,
 revision VARCHAR(64) NULL,
 remote_created VARCHAR(64) NULL,
 remote_updated VARCHAR(64) NULL,
 archived INTEGER NOT NULL DEFAULT 0,
 missing INTEGER NOT NULL DEFAULT 0,
 payload_json LONGTEXT NOT NULL,
 checksum VARCHAR(64) NOT NULL,
 date_sync DATETIME NOT NULL,
 fk_run BIGINT NOT NULL,
 projection_status VARCHAR(24) NOT NULL DEFAULT 'pending',
 last_error VARCHAR(128) NULL,
 UNIQUE KEY uk_lx_resource (entity, organization_id, resource_type, remote_id),
 KEY idx_lx_projection (entity, projection_status),
 KEY idx_lx_seen (entity, fk_run)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS llx_hwoslexware_mapping (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_resource BIGINT NOT NULL,
 object_type VARCHAR(64) NOT NULL,
 object_id INTEGER NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 snapshot_checksum VARCHAR(64) NOT NULL,
 remote_checksum VARCHAR(64) NOT NULL,
 projection_policy VARCHAR(24) NOT NULL DEFAULT 'owned',
 date_creation DATETIME NOT NULL,
 UNIQUE KEY uk_lx_mapping_resource (entity, fk_resource),
 UNIQUE KEY uk_lx_mapping_object (entity, object_type, object_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS llx_hwoslexware_issue (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_resource BIGINT NOT NULL,
 issue_type VARCHAR(32) NOT NULL,
 details_json LONGTEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'open',
 date_creation DATETIME NOT NULL,
 UNIQUE KEY uk_lx_issue (entity, fk_resource, issue_type)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS llx_hwoslexware_file (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_resource BIGINT NOT NULL,
 remote_id VARCHAR(128) NOT NULL,
 representation VARCHAR(32) NOT NULL,
 content_hash VARCHAR(64) NOT NULL,
 mime_type VARCHAR(128) NOT NULL,
 content_blob LONGBLOB NOT NULL,
 date_sync DATETIME NOT NULL,
 UNIQUE KEY uk_lx_file (entity, fk_resource, remote_id, representation)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS llx_hwoslexware_relation (
 rowid BIGINT AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_resource BIGINT NOT NULL,
 related_id VARCHAR(128) NOT NULL,
 related_type VARCHAR(64) NOT NULL,
 UNIQUE KEY uk_lx_relation (entity, fk_resource, related_id, related_type)
) ENGINE=InnoDB;
