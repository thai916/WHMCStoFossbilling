<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Installation;

final class Schema
{
    public static function install(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) throw new \Box\Mod\Whmcsmigrator\Security\SafeException('Extension schema installation requires no active destination transaction. No implicit commit was allowed.');
        $tables = [
            'source' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fingerprint CHAR(64) NOT NULL UNIQUE, label VARCHAR(255) NOT NULL, credentials MEDIUMTEXT NOT NULL, schema_json LONGTEXT NOT NULL, mapping_json LONGTEXT NOT NULL, source_version VARCHAR(80) NULL, tested_at DATETIME NOT NULL, created_at DATETIME NOT NULL',
            'run' => "id CHAR(32) PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, mode VARCHAR(20) NOT NULL, schema_json LONGTEXT NOT NULL, mapping_json LONGTEXT NOT NULL, plan_json LONGTEXT NOT NULL, owner_id BIGINT NOT NULL, backup_reference VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_batch_at DATETIME NULL, INDEX(source_id)",
            'cursor' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id CHAR(32) NOT NULL, table_name VARCHAR(64) NOT NULL, expected BIGINT UNSIGNED NOT NULL, maximum VARCHAR(30) NOT NULL, after_id VARCHAR(30) NOT NULL DEFAULT '0', processed BIGINT UNSIGNED NOT NULL DEFAULT 0, imported BIGINT UNSIGNED NOT NULL DEFAULT 0, archived BIGINT UNSIGNED NOT NULL DEFAULT 0, skipped BIGINT UNSIGNED NOT NULL DEFAULT 0, failed BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(24) NOT NULL DEFAULT 'pending', UNIQUE KEY(run_id, table_name)",
            'identity' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, destination_entity VARCHAR(64) NOT NULL, destination_id BIGINT UNSIGNED NULL, checksum CHAR(64) NOT NULL, destination_checksum CHAR(64) NULL, run_id CHAR(32) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY(source_id, table_name, source_key, destination_entity)',
            'archive' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, payload LONGTEXT NOT NULL, checksum CHAR(64) NOT NULL, original_dates TEXT NOT NULL, relationships TEXT NOT NULL, exclusions TEXT NOT NULL, run_id CHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY(source_id, table_name, source_key), INDEX(run_id)',
            'attempt' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id CHAR(32) NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, status VARCHAR(24) NOT NULL, error_type VARCHAR(80) NULL, message TEXT NULL, retryable TINYINT NOT NULL DEFAULT 0, attempts INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY(run_id, table_name, source_key)',
            'batch' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id CHAR(32) NOT NULL, request_id CHAR(32) NOT NULL, result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY(run_id, request_id)',
            'analysis' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, expected BIGINT UNSIGNED NOT NULL, maximum VARCHAR(30) NOT NULL, schema_checksum CHAR(64) NOT NULL, analyzed_at DATETIME NOT NULL, UNIQUE KEY(source_id,table_name)',
            'archive_revision' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, archive_id BIGINT UNSIGNED NOT NULL, payload LONGTEXT NOT NULL, checksum CHAR(64) NOT NULL, exclusions TEXT NOT NULL, run_id CHAR(32) NOT NULL, preserved_at DATETIME NOT NULL, INDEX(archive_id)',
            'price' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, source_key VARCHAR(30) NOT NULL, product_id BIGINT UNSIGNED NOT NULL, currency VARCHAR(3) NOT NULL, prices_json TEXT NOT NULL, native_applied TINYINT NOT NULL DEFAULT 0, checksum CHAR(64) NOT NULL, run_id CHAR(32) NOT NULL, UNIQUE KEY(source_id,source_key), UNIQUE KEY(source_id,product_id,currency)',
            'archive_link' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, field_name VARCHAR(64) NOT NULL, target_key VARCHAR(191) NOT NULL, UNIQUE KEY(source_id,table_name,source_key,field_name), INDEX lookup_target(source_id,table_name,field_name,target_key)',
            'assessment' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id CHAR(32) NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, category VARCHAR(30) NOT NULL, currency VARCHAR(16) NULL, currency_basis VARCHAR(80) NOT NULL, source_metrics TEXT NOT NULL, native_metrics TEXT NOT NULL, status VARCHAR(32) NOT NULL, message TEXT NULL, checked_at DATETIME NOT NULL, UNIQUE KEY(run_id,table_name,source_key)',
            'reconcile_state' => "run_id CHAR(32) PRIMARY KEY, after_id BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'scanning', checked_at DATETIME NULL",
            'reconcile_total' => 'run_id CHAR(32) NOT NULL, metric VARCHAR(40) NOT NULL, currency VARCHAR(16) NOT NULL, source_total DECIMAL(36,2) NOT NULL DEFAULT 0, native_total DECIMAL(36,2) NOT NULL DEFAULT 0, source_records BIGINT NOT NULL DEFAULT 0, native_records BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(run_id,metric,currency)',
            'snapshot' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id CHAR(32) NOT NULL, source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, payload LONGTEXT NOT NULL, checksum CHAR(64) NOT NULL, UNIQUE KEY(run_id,table_name,source_key), INDEX(run_id,id)',
            'snapshot_link' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, snapshot_id BIGINT UNSIGNED NOT NULL, run_id CHAR(32) NOT NULL, table_name VARCHAR(64) NOT NULL, field_name VARCHAR(64) NOT NULL, target_key VARCHAR(191) NOT NULL, UNIQUE KEY(snapshot_id,field_name), INDEX lookup_run(run_id,table_name,field_name,target_key)',
            'credit_state' => "run_id CHAR(32) PRIMARY KEY, after_id BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'scanning'",
            'credit_result' => 'run_id CHAR(32) NOT NULL, source_key VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, message TEXT NULL, attempts INT NOT NULL DEFAULT 1, PRIMARY KEY(run_id,source_key)',
            'legacy_reference' => 'source_id BIGINT UNSIGNED NOT NULL, table_name VARCHAR(64) NOT NULL, source_key VARCHAR(30) NOT NULL, kind VARCHAR(20) NOT NULL, reference_hash CHAR(64) NOT NULL, PRIMARY KEY(source_id,table_name,source_key,kind), INDEX(source_id,kind,reference_hash)',
        ];
        foreach ($tables as $name => $columns) {
            // DDL is restricted to extension-owned tables; no core schema changes or global migrations.
            $pdo->exec('CREATE TABLE IF NOT EXISTS `whmcsmigrator_' . $name . '` (' . $columns . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }
}
