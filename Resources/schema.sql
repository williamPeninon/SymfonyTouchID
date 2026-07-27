-- Reference schema for web_authn_credential (MySQL / MariaDB).
-- Prefer: php bin/console doctrine:migrations:diff && doctrine:migrations:migrate
-- (requires user_class so the FK to User resolves).
-- Fallback: php bin/console passkey:install

CREATE TABLE web_authn_credential (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    credential_id VARCHAR(512) NOT NULL,
    public_key LONGTEXT NOT NULL,
    sign_count INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    aaguid VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX UNIQ_WEBAUTHN_CREDENTIAL_ID (credential_id),
    INDEX IDX_WEBAUTHN_USER (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE web_authn_credential
    ADD CONSTRAINT FK_WEBAUTHN_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
