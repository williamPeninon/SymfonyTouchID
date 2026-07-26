<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates web_authn_credential for wpconsulting/touch-id-bundle.
 * Adjust the FK target table/column if your users table is not `user` / `id`.
 */
final class Version20260726210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add web_authn_credential table for Touch ID / passkey login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE web_authn_credential (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, credential_id VARCHAR(512) NOT NULL, public_key LONGTEXT NOT NULL, sign_count INT NOT NULL, name VARCHAR(100) NOT NULL, aaguid VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_WEBAUTHN_CREDENTIAL_ID (credential_id), INDEX IDX_WEBAUTHN_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE web_authn_credential ADD CONSTRAINT FK_WEBAUTHN_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE web_authn_credential DROP FOREIGN KEY FK_WEBAUTHN_USER');
        $this->addSql('DROP TABLE web_authn_credential');
    }
}
