<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbDumpCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:dump')
            ->setDescription('Create a local dump of the remote database');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A custom filename for the dump')
            ->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to include')
            ->addOption('exclude-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to exclude')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Dump only schemas, no data');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->setHiddenAliases(['sql-dump', 'environment:sql-dump']);
        $this->addExample('Create an SQL dump file');
        $this->addExample('Create a gzipped SQL dump file named "test.sql.gz"', '-z -f test.sql.gz');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);
        $timestamp = $input->getOption('timestamp') ? date('Ymd-His-T') : null;
        $gzip = $input->getOption('gzip');
        $includedTables = $input->getOption('table');
        $excludedTables = $input->getOption('exclude-table');
        $schemaOnly = $input->getOption('schema-only');

        $dumpFile = null;
        if (!$input->getOption('stdout')) {
            // Determine a default dump filename.
            $defaultFilename = $project->id . '--' . $environment->id;
            if ($appName !== null) {
                $defaultFilename .= '--' . $appName;
            }
            if ($includedTables) {
                $defaultFilename .= '--' . implode(',', $includedTables);
            }
            if ($excludedTables) {
                $defaultFilename .= '--excl-' . implode(',', $excludedTables);
            }
            if ($schemaOnly) {
                $defaultFilename .= '--schema';
            }
            if ($timestamp !== null) {
                $defaultFilename .= '--' . $timestamp;
            }
            $defaultFilename .= '--dump.sql';
            if ($gzip) {
                $defaultFilename .= '.gz';
            }
            $projectRoot = $this->getProjectRoot();
            $directory = $projectRoot ?: getcwd();
            $dumpFile = $directory . '/' . $defaultFilename;

            // Process the user --file option.
            if ($input->getOption('file')) {
                $dumpFile = rtrim($input->getOption('file'), '/');

                // Make the filename absolute.
                /** @var \Platformsh\Cli\Service\Filesystem $fs */
                $fs = $this->getService('fs');
                $dumpFile = $fs->makePathAbsolute($dumpFile);

                // Ensure the filename is not a directory.
                if (is_dir($dumpFile)) {
                    $dumpFile .= '/' . $defaultFilename;
                }

                // Insert a timestamp into the filename.
                if ($timestamp) {
                    $basename = basename($dumpFile);
                    $prefix = substr($dumpFile, 0, - strlen($basename));
                    if ($dotPos = strrpos($basename, '.')) {
                        $basename = substr($basename, 0, $dotPos) . '--' . $timestamp . substr($basename, $dotPos);
                    } else {
                        $basename .= '--' . $timestamp;
                    }
                    $dumpFile = $prefix . $basename;
                }
            }
        }

        if ($dumpFile) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped SQL dump' : 'SQL dump',
                $dumpFile
            ));
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');

        $database = $relationships->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = 'pg_dump --clean ' . $relationships->getSqlCommandArgs('pg_dump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --schema-only';
                }
                foreach ($includedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg('--table=' . $table);
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg('--exclude-table=' . $table);
                }
                break;

            default:
                $dumpCommand = 'mysqldump --no-autocommit --single-transaction --opt --quote-names '
                    . $relationships->getSqlCommandArgs('mysqldump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --no-data';
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg(sprintf('--ignore-table=%s.%s', $database['path'], $table));
                }
                if ($includedTables) {
                    $dumpCommand .= ' --tables ' . implode(' ', array_map('escapeshellarg', $includedTables));
                }
                break;
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshCommand = $ssh->getSshCommand();

        if ($gzip) {
            $dumpCommand .= ' | gzip --stdout';
        } else {
            $sshCommand .= ' -C';
        }

        set_time_limit(0);

        $command = $sshCommand
            . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($dumpCommand);
        if ($dumpFile) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        return $shell->executeSimple($command);
    }
}
