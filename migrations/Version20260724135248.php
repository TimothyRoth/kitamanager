<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the IP-based display assignment with a user-defined 4-digit PIN:
 * drops user.device_ip and adds user.device_pin (unique). TVs enter the PIN
 * once on /slider/display and keep the link via a cookie.
 */
final class Version20260724135248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user.device_ip with user.device_pin (TV pairing via 4-digit PIN).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6498E7E13A9');
        $this->addSql('ALTER TABLE "user" DROP COLUMN device_ip');
        $this->addSql('ALTER TABLE "user" ADD COLUMN device_pin VARCHAR(4) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6495F7BF76A ON "user" (device_pin)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6495F7BF76A');
        $this->addSql('ALTER TABLE "user" DROP COLUMN device_pin');
        $this->addSql('ALTER TABLE "user" ADD COLUMN device_ip VARCHAR(45) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6498E7E13A9 ON "user" (device_ip)');
    }
}
