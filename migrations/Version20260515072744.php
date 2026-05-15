<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515072744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE content ADD COLUMN User_Id VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Type VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Image_Url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Content CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Created_At DATETIME NOT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN Last_Updated_At DATETIME NOT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, duration_between_slides INTEGER NOT NULL)');
        $this->addSql('INSERT INTO user (id, username, roles, password) SELECT id, username, roles, password FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME ON user (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649989D9B62 ON user (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__content AS SELECT id FROM content');
        $this->addSql('DROP TABLE content');
        $this->addSql('CREATE TABLE content (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO content (id) SELECT id FROM __temp__content');
        $this->addSql('DROP TABLE __temp__content');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password FROM "user"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO "user" (id, username, roles, password) SELECT id, username, roles, password FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME ON "user" (username)');
    }
}
