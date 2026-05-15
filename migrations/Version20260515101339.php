<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515101339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE content ADD COLUMN is_enabled BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__content AS SELECT id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, display_order, User_Id FROM content');
        $this->addSql('DROP TABLE content');
        $this->addSql('CREATE TABLE content (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, Type VARCHAR(255) NOT NULL, Image_Url VARCHAR(255) DEFAULT NULL, Title VARCHAR(255) DEFAULT NULL, Content CLOB DEFAULT NULL, Created_At DATETIME NOT NULL, Last_Updated_At DATETIME NOT NULL, display_order INTEGER NOT NULL, User_Id INTEGER NOT NULL, CONSTRAINT FK_FEC530A9FD57CEAB FOREIGN KEY (User_Id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO content (id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, display_order, User_Id) SELECT id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, display_order, User_Id FROM __temp__content');
        $this->addSql('DROP TABLE __temp__content');
        $this->addSql('CREATE INDEX IDX_FEC530A9FD57CEAB ON content (User_Id)');
    }
}
