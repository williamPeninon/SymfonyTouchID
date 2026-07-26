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
    description: 'Create or update the web_authn_credential table (Doctrine schema for this entity only).',
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
        $this->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dump SQL instead of executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $metadata = [$this->entityManager->getClassMetadata(WebAuthnCredential::class)];
        $schemaTool = new SchemaTool($this->entityManager);

        $sql = $this->getUpdateSql($schemaTool, $metadata);

        if ($sql === []) {
            $io->success('Schema is already up to date for web_authn_credential.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dump-sql')) {
            $io->writeln($sql);

            return Command::SUCCESS;
        }

        $this->updateSchema($schemaTool, $metadata);
        $io->success('web_authn_credential table created/updated.');
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

    /**
     * @param list<object> $metadata
     *
     * @return list<string>
     */
    private function getUpdateSql(SchemaTool $schemaTool, array $metadata): array
    {
        try {
            /** @var list<string> $sql */
            $sql = $schemaTool->getUpdateSchemaSql($metadata);

            return $sql;
        } catch (\ArgumentCountError) {
            /** @var list<string> $sql */
            $sql = $schemaTool->getUpdateSchemaSql($metadata, true);

            return $sql;
        }
    }

    /**
     * @param list<object> $metadata
     */
    private function updateSchema(SchemaTool $schemaTool, array $metadata): void
    {
        try {
            $schemaTool->updateSchema($metadata);
        } catch (\ArgumentCountError) {
            $schemaTool->updateSchema($metadata, true);
        }
    }
}
