<?php declare(strict_types = 1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20180213080202 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE piece (id INT AUTO_INCREMENT NOT NULL, category INT NOT NULL, type VARCHAR(255) NOT NULL, color INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `set` (id INT AUTO_INCREMENT NOT NULL, no INT NOT NULL, price DOUBLE PRECISION NOT NULL, obsolete TINYINT(1) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE set_piece (set_id INT NOT NULL, piece_id INT NOT NULL, INDEX IDX_D03BF74510FB0D18 (set_id), INDEX IDX_D03BF745C40FCFA8 (piece_id), PRIMARY KEY(set_id, piece_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE set_piece ADD CONSTRAINT FK_D03BF74510FB0D18 FOREIGN KEY (set_id) REFERENCES `set` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE set_piece ADD CONSTRAINT FK_D03BF745C40FCFA8 FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE set_piece DROP FOREIGN KEY FK_D03BF745C40FCFA8');
        $this->addSql('ALTER TABLE set_piece DROP FOREIGN KEY FK_D03BF74510FB0D18');
        $this->addSql('DROP TABLE piece');
        $this->addSql('DROP TABLE `set`');
        $this->addSql('DROP TABLE set_piece');
    }
}
