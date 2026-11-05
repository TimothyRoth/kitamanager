<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105212514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let Doctrine clean user_publish_target on user deletion (drop the unenforced ON DELETE CASCADE) and remove rows left behind by deleted users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM user_publish_target WHERE source_user_id NOT IN (SELECT id FROM "user") OR target_user_id NOT IN (SELECT id FROM "user")');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_publish_target AS SELECT source_user_id, target_user_id FROM user_publish_target');
        $this->addSql('DROP TABLE user_publish_target');
        $this->addSql('CREATE TABLE user_publish_target (source_user_id INTEGER NOT NULL, target_user_id INTEGER NOT NULL, PRIMARY KEY (source_user_id, target_user_id), CONSTRAINT FK_ACCF5081EEB16BFD FOREIGN KEY (source_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_ACCF50816C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_publish_target (source_user_id, target_user_id) SELECT source_user_id, target_user_id FROM __temp__user_publish_target');
        $this->addSql('DROP TABLE __temp__user_publish_target');
        $this->addSql('CREATE INDEX IDX_ACCF50816C066AFE ON user_publish_target (target_user_id)');
        $this->addSql('CREATE INDEX IDX_ACCF5081EEB16BFD ON user_publish_target (source_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_publish_target AS SELECT source_user_id, target_user_id FROM user_publish_target');
        $this->addSql('DROP TABLE user_publish_target');
        $this->addSql('CREATE TABLE user_publish_target (source_user_id INTEGER NOT NULL, target_user_id INTEGER NOT NULL, PRIMARY KEY (source_user_id, target_user_id), CONSTRAINT FK_ACCF5081EEB16BFD FOREIGN KEY (source_user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_ACCF50816C066AFE FOREIGN KEY (target_user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_publish_target (source_user_id, target_user_id) SELECT source_user_id, target_user_id FROM __temp__user_publish_target');
        $this->addSql('DROP TABLE __temp__user_publish_target');
        $this->addSql('CREATE INDEX IDX_ACCF5081EEB16BFD ON user_publish_target (source_user_id)');
        $this->addSql('CREATE INDEX IDX_ACCF50816C066AFE ON user_publish_target (target_user_id)');
    }
}
