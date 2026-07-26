<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WpConsulting\TouchIdBundle\Entity\WebAuthnCredential;

#[AsCommand(
    name: 'touch-id:install',
    description: 'Create the web_authn_credential table if it does not exist (safe: does not alter other tables).',
)]
final class TouchIdInstallCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dump CREATE SQL instead of executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist(['web_authn_credential'])) {
            $io->success('Table web_authn_credential already exists.');

            return Command::SUCCESS;
        }

        $metadata = [$this->entityManager->getClassMetadata(WebAuthnCredential::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $sql = $schemaTool->getCreateSchemaSql($metadata);

        if ($input->getOption('dump-sql')) {
            $io->writeln($sql);

            return Command::SUCCESS;
        }

        foreach ($sql as $query) {
            $connection->executeStatement($query);
        }

        $io->success('Table web_authn_credential created.');
        $io->note([
            'Also ensure:',
            '1) config/packages/wp_consulting_touch_id.yaml (user_class + user_repository)',
            '2) config/routes/touch_id.yaml imports @TouchIdBundle/config/routes.yaml',
            '3) security access_control PUBLIC_ACCESS for ^/webauthn/login',
            '4) assets/controllers.json enables @wpconsulting/touch-id-bundle',
            '5) User implements TouchIdUserInterface ; repository implements TouchIdUserRepositoryInterface',
        ]);

        return Command::SUCCESS;
    }
}
