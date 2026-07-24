<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user.device_ip: the IP address of the display device (TV) that should
 * show this user's slider. /slider/display resolves the requesting device to
 * a user via this value; unique so one device maps to exactly one user.
 */
final class Version20260724133312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.device_ip for resolving display devices (TVs) to their kita.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN device_ip VARCHAR(45) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6498E7E13A9 ON "user" (device_ip)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6498E7E13A9');
        $this->addSql('ALTER TABLE "user" DROP COLUMN device_ip');
    }
}
