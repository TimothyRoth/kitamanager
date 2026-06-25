<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Move from "personal + global content" to audience-based delivery.
 *
 * - Adds user.publish_to_all and content.audience_all.
 * - Introduces user_publish_target (creator -> allowed target) and slider_item
 *   (per-consumer delivery row with its own display_order / is_enabled).
 * - Migrates existing data:
 *     * personal content  -> one slider_item for its creator (consumer = creator)
 *     * global content     -> creator becomes the admin, audience_all = 1, and one
 *                             slider_item per normal user (appended after their
 *                             personal items); admin gets publish_to_all = 1.
 * - Makes content.User_Id (the creator) NOT NULL and drops the now per-consumer
 *   columns display_order / is_enabled from content.
 */
final class Version20260625115430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audience-based content delivery (slider_item, user_publish_target, creator + data migration).';
    }

    public function up(Schema $schema): void
    {
        // 1) New columns (non-destructive).
        $this->addSql('ALTER TABLE "user" ADD COLUMN publish_to_all BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE content ADD COLUMN audience_all BOOLEAN DEFAULT 0 NOT NULL');

        // 2) Stash the future slider rows (no FK) computed from the OLD content
        //    columns, before they get dropped in the content rebuild below.
        $this->addSql('CREATE TABLE _migr_slider_seed (content_id INTEGER NOT NULL, consumer_id INTEGER NOT NULL, display_order INTEGER NOT NULL, is_enabled BOOLEAN NOT NULL)');

        // 2a) Personal content -> consumer is the creator, keep its order/status.
        $this->addSql('INSERT INTO _migr_slider_seed (content_id, consumer_id, display_order, is_enabled)
            SELECT id, User_Id, display_order, is_enabled FROM content WHERE User_Id IS NOT NULL');

        // 2b) Global content -> one row per normal (non-admin) user, appended
        //     after that user\'s personal items, preserving global ordering.
        $this->addSql('INSERT INTO _migr_slider_seed (content_id, consumer_id, display_order, is_enabled)
            SELECT c.id, u.id,
                (SELECT COUNT(*) FROM content p WHERE p.User_Id = u.id)
                + (SELECT COUNT(*) FROM content g
                   WHERE g.User_Id IS NULL
                     AND (g.display_order < c.display_order
                          OR (g.display_order = c.display_order AND g.id <= c.id))),
                c.is_enabled
            FROM content c
            CROSS JOIN "user" u
            WHERE c.User_Id IS NULL
              AND u.roles LIKE \'%"ROLE_USER"%\'');

        // 3) Mark global content as dynamic "all" and let the admin publish to all.
        $this->addSql('UPDATE content SET audience_all = 1 WHERE User_Id IS NULL');
        $this->addSql('UPDATE "user" SET publish_to_all = 1 WHERE roles LIKE \'%"ROLE_ADMIN"%\'');

        // 4) Assign the admin as the creator of former global content.
        $this->addSql('UPDATE content SET User_Id = (
                SELECT id FROM "user" WHERE roles LIKE \'%"ROLE_ADMIN"%\' ORDER BY id LIMIT 1
            ) WHERE User_Id IS NULL');

        // 5) Rebuild content: User_Id NOT NULL, drop display_order/is_enabled,
        //    keep audience_all. No slider_item exists yet, so dropping content is safe.
        $this->addSql('CREATE TEMPORARY TABLE __temp__content AS SELECT id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, audience_all FROM content');
        $this->addSql('DROP TABLE content');
        $this->addSql('CREATE TABLE content (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, User_Id INTEGER NOT NULL, Type VARCHAR(255) NOT NULL, Image_Url VARCHAR(255) DEFAULT NULL, Title VARCHAR(255) DEFAULT NULL, Content CLOB DEFAULT NULL, Created_At DATETIME NOT NULL, Last_Updated_At DATETIME NOT NULL, audience_all BOOLEAN DEFAULT 0 NOT NULL, CONSTRAINT FK_FEC530A9FD57CEAB FOREIGN KEY (User_Id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO content (id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, audience_all) SELECT id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At, audience_all FROM __temp__content');
        $this->addSql('DROP TABLE __temp__content');
        $this->addSql('CREATE INDEX IDX_FEC530A9FD57CEAB ON content (User_Id)');

        // 6) Create the new tables.
        $this->addSql('CREATE TABLE slider_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, display_order INTEGER NOT NULL, is_enabled BOOLEAN DEFAULT 1 NOT NULL, content_id INTEGER NOT NULL, consumer_id INTEGER NOT NULL, CONSTRAINT FK_788595CE84A0A3ED FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_788595CE37FDBD6D FOREIGN KEY (consumer_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_788595CE84A0A3ED ON slider_item (content_id)');
        $this->addSql('CREATE INDEX IDX_788595CE37FDBD6D ON slider_item (consumer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SLIDER_ITEM_CONTENT_CONSUMER ON slider_item (content_id, consumer_id)');
        $this->addSql('CREATE TABLE user_publish_target (source_user_id INTEGER NOT NULL, target_user_id INTEGER NOT NULL, PRIMARY KEY (source_user_id, target_user_id), CONSTRAINT FK_ACCF5081EEB16BFD FOREIGN KEY (source_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_ACCF50816C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_ACCF5081EEB16BFD ON user_publish_target (source_user_id)');
        $this->addSql('CREATE INDEX IDX_ACCF50816C066AFE ON user_publish_target (target_user_id)');

        // 7) Populate slider_item from the seed, then drop the seed table.
        $this->addSql('INSERT INTO slider_item (content_id, consumer_id, display_order, is_enabled)
            SELECT content_id, consumer_id, display_order, is_enabled FROM _migr_slider_seed');
        $this->addSql('DROP TABLE _migr_slider_seed');
    }

    public function down(Schema $schema): void
    {
        // Stash data needed to reconstruct the old columns before dropping things.
        $this->addSql('CREATE TABLE _migr_down_order (content_id INTEGER NOT NULL, display_order INTEGER NOT NULL, is_enabled BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO _migr_down_order (content_id, display_order, is_enabled)
            SELECT si.content_id, si.display_order, si.is_enabled
            FROM slider_item si
            JOIN content c ON c.id = si.content_id
            WHERE si.consumer_id = c.User_Id');
        $this->addSql('CREATE TABLE _migr_down_global (content_id INTEGER NOT NULL)');
        $this->addSql('INSERT INTO _migr_down_global (content_id) SELECT id FROM content WHERE audience_all = 1');

        // Drop the new tables (this also clears slider_item which we already stashed).
        $this->addSql('DROP TABLE slider_item');
        $this->addSql('DROP TABLE user_publish_target');

        // Rebuild content with nullable User_Id and the old per-content columns.
        $this->addSql('CREATE TEMPORARY TABLE __temp__content AS SELECT id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At FROM content');
        $this->addSql('DROP TABLE content');
        $this->addSql('CREATE TABLE content (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, User_Id INTEGER DEFAULT NULL, Type VARCHAR(255) NOT NULL, Image_Url VARCHAR(255) DEFAULT NULL, Title VARCHAR(255) DEFAULT NULL, Content CLOB DEFAULT NULL, Created_At DATETIME NOT NULL, Last_Updated_At DATETIME NOT NULL, display_order INTEGER NOT NULL DEFAULT 0, is_enabled BOOLEAN DEFAULT 1 NOT NULL, CONSTRAINT FK_FEC530A9FD57CEAB FOREIGN KEY (User_Id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO content (id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At) SELECT id, User_Id, Type, Image_Url, Title, Content, Created_At, Last_Updated_At FROM __temp__content');
        $this->addSql('DROP TABLE __temp__content');
        $this->addSql('CREATE INDEX IDX_FEC530A9FD57CEAB ON content (User_Id)');

        // Restore per-content order/status from the creator\'s slider item.
        $this->addSql('UPDATE content SET
                display_order = COALESCE((SELECT display_order FROM _migr_down_order o WHERE o.content_id = content.id), 0),
                is_enabled = COALESCE((SELECT is_enabled FROM _migr_down_order o WHERE o.content_id = content.id), 1)');
        // Former global content goes back to a NULL creator.
        $this->addSql('UPDATE content SET User_Id = NULL WHERE id IN (SELECT content_id FROM _migr_down_global)');

        // Rebuild user to drop publish_to_all.
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password, slug, duration_between_slides FROM "user"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, duration_between_slides INTEGER NOT NULL)');
        $this->addSql('INSERT INTO "user" (id, username, roles, password, slug, duration_between_slides) SELECT id, username, roles, password, slug, duration_between_slides FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649989D9B62 ON "user" (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME ON "user" (username)');

        $this->addSql('DROP TABLE _migr_down_order');
        $this->addSql('DROP TABLE _migr_down_global');
    }
}
