-- Outbox table for the storefront database (MySQL 5.7+ / MariaDB 10.3+).
-- Events are inserted in the same transaction as the business change and delivered to the
-- Sabar Mart CRM by OutboxWorker. Rename the table if you pass { table } to MysqlOutboxStore.
CREATE TABLE IF NOT EXISTS storefront_crm_outbox (
  seq             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id        VARCHAR(200)    NOT NULL,
  type            VARCHAR(64)     NOT NULL,
  data            LONGTEXT        NOT NULL,          -- JSON
  status          ENUM('pending', 'sent', 'dead') NOT NULL DEFAULT 'pending',
  attempts        INT UNSIGNED    NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_error      TEXT            NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  sent_at         DATETIME(3)     NULL,
  PRIMARY KEY (seq),
  UNIQUE KEY uq_event_id (event_id),
  KEY idx_pending (status, seq)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
