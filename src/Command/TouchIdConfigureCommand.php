<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WpConsulting\TouchIdBundle\Installer\ProjectConfigurator;
use WpConsulting\TouchIdBundle\TouchIdBundle;

#[AsCommand(
    name: 'touch-id:configure',
    description: 'Wire Touch ID into the host app (skeleton, user_class, User interface, Stimulus, access_control, DB table).',
)]
final class TouchIdConfigureCommand extends Command
{
    public function __construct(
        private readonly ProjectConfigurator $configurator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-class', null, InputOption::VALUE_REQUIRED, 'FQCN of the User entity')
            ->addOption('identifier-field', null, InputOption::VALUE_REQUIRED, 'Doctrine identifier field', 'email')
            ->addOption('no-db', null, InputOption::VALUE_NONE, 'Skip touch-id:install / migrations')
            ->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'Host project directory (default: cwd)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = $this->resolveProjectDir($input);
        $bundleRoot = (new TouchIdBundle())->getPath();

        $io->title('WP Consulting Touch ID — configure');

        $created = $this->configurator->publishSkeleton($projectDir, $bundleRoot);
        foreach ($created as $file) {
            $io->writeln(sprintf('  <info>Created</info> %s', $file));
        }

        if ($this->configurator->ensureBundleRegistered($projectDir)) {
            $io->writeln('  <info>Registered</info> TouchIdBundle in config/bundles.php');
        }

        $userClass = $input->getOption('user-class');
        if (!\is_string($userClass) || $userClass === '') {
            $userClass = $this->configurator->readConfiguredUserClassFromProject($projectDir);
        }

        if ($userClass === null) {
            $candidates = $this->configurator->discoverUserClassCandidates($projectDir);
            $default = $candidates[0] ?? ProjectConfigurator::DEFAULT_USER_CLASS;

            if ($candidates !== []) {
                $io->writeln('Detected User candidates:');
                foreach ($candidates as $i => $candidate) {
                    $io->writeln(sprintf('  <comment>%d)</comment> %s', $i + 1, $candidate));
                }
            }

            if (!$input->isInteractive()) {
                $io->warning(sprintf(
                    'Non-interactive: set user_class in config/packages/wp_consulting_touch_id.yaml (e.g. %s) then re-run.',
                    $default
                ));

                return Command::FAILURE;
            }

            $userClass = $io->ask(
                'user_class (FQCN of your auth entity)',
                $default,
                static function (?string $answer) use ($default): string {
                    $answer = trim((string) $answer);
                    if ($answer === '') {
                        $answer = $default;
                    }
                    if (!ProjectConfigurator::isValidUserClassFqcn($answer)) {
                        throw new \InvalidArgumentException('Invalid FQCN. Example: App\\Entity\\User');
                    }

                    return $answer;
                }
            );
        }

        $identifierDefault = $input->getOption('identifier-field');
        if (!\is_string($identifierDefault) || $identifierDefault === '') {
            $identifierDefault = $this->configurator->guessIdentifierField($projectDir, $userClass) ?? 'email';
        }

        $identifierField = $input->isInteractive()
            ? (string) $io->ask('user_identifier_field', $identifierDefault)
            : $identifierDefault;
        $identifierField = trim($identifierField) ?: $identifierDefault;

        if (!$this->configurator->writeUserClassConfig($projectDir, $userClass, $identifierField)) {
            $io->error('Could not write config/packages/wp_consulting_touch_id.yaml');

            return Command::FAILURE;
        }
        $io->writeln(sprintf(
            '  <info>Configured</info> user_class=<comment>%s</comment>, user_identifier_field=<comment>%s</comment>',
            $userClass,
            $identifierField
        ));

        $wire = $this->configurator->ensureTouchIdUserInterface($projectDir, $userClass);
        if ($wire['error'] !== null && $wire['path'] === null) {
            $io->warning($wire['error'].' — add TouchIdUserInterface manually.');
        } elseif ($wire['changed']) {
            $io->writeln(sprintf('  <info>Wired</info> TouchIdUserInterface on <comment>%s</comment>', $userClass));
        } else {
            $io->writeln(sprintf('  TouchIdUserInterface already present on <comment>%s</comment>', $userClass));
        }

        if ($this->configurator->ensureStimulusControllers($projectDir)) {
            $io->writeln('  <info>Enabled</info> Stimulus controllers in assets/controllers.json');
        } else {
            $io->warning('Could not update assets/controllers.json');
        }

        if ($this->configurator->ensureWebauthnLoginAccess($projectDir)) {
            $io->writeln('  <info>Ensured</info> PUBLIC_ACCESS for ^/webauthn/login in security.yaml');
        } else {
            $io->note('Add access_control PUBLIC_ACCESS for ^/webauthn/login in security.yaml if missing.');
        }

        if (!$input->getOption('no-db')) {
            [$ok] = $this->configurator->runConsole($projectDir, ['touch-id:install', '-n']);
            if ($ok) {
                $io->writeln('  <info>Database</info> web_authn_credential ready (touch-id:install).');
            } else {
                [$migrateOk] = $this->configurator->runConsole($projectDir, [
                    'doctrine:migrations:diff', '--no-interaction',
                ]);
                [$migrateOk2] = $this->configurator->runConsole($projectDir, [
                    'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration',
                ]);
                if ($migrateOk2 || $migrateOk) {
                    $io->writeln('  <info>Database</info> via doctrine:migrations.');
                } else {
                    $io->note('Run: php bin/console touch-id:install');
                }
            }
        }

        $io->success('Touch ID configure complete.');
        $io->writeln('Twig includes still to add if missing:');
        $io->listing([
            "{% include '@TouchId/touch_id/_login_button.html.twig' %}",
            "{% include '@TouchId/touch_id/_manage.html.twig' with { credentials: touch_id_credentials(app.user) } %}",
        ]);

        return Command::SUCCESS;
    }

    private function resolveProjectDir(InputInterface $input): string
    {
        $option = $input->getOption('project-dir');
        if (\is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }

        return getcwd() ?: '.';
    }
}
