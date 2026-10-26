<?php

declare(strict_types=1);

namespace Drush\Commands;

use Consolidation\SiteAlias\SiteAliasInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @file
 * Drush commands for the CI database snapshot of a project.
 */

/**
 * Tests and imports the scrubbed production database snapshot.
 *
 * The server side is misc/snapshot/ci-snapshot.sh, normally reached through
 * ci-snapshot-dispatch.sh, the forced command of the shared CI key.
 */
final class CiSnapshotCommands extends DrushCommands {

  /**
   * The usage line for the shared key on STDIN.
   */
  public const KEY_USAGE = 'ddev drush ci-snapshot:check < /path/to/key';

  /**
   * Tests the CI endpoint the same way CI uses it.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int
   *   The exit code: 0 when SSH succeeded and the dump is a gzip file.
   */
  #[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
  #[CLI\Command(name: 'ci-snapshot:check')]
  #[CLI\Option(name: 'project', description: 'Project name to send to the dispatcher. Defaults to the DDEV project name.')]
  #[CLI\Usage(name: 'ddev drush ci-snapshot:check < /path/to/key', description: 'Send the project name with the shared key and report what comes back.')]
  public function check(array $options = ['project' => NULL]): int {
    $this->assertDdev();
    $project = $this->projectName($this->stringOption($options, 'project'));
    $alias = $this->productionAlias();
    $key = $this->readKey();

    $dir = $this->tempDir();
    try {
      $key_file = $dir . '/key';
      if (file_put_contents($key_file, $key) === FALSE) {
        throw new \RuntimeException(sprintf('Cannot write the key to %s.', $key_file));
      }
      chmod($key_file, 0600);
      $dump = $dir . '/snapshot.sql.gz';

      // The shared CI key, the dispatcher as forced command and the project
      // name as SSH command: exactly what CI does.
      $process = Drush::process([
        'ssh',
        '-i', $key_file,
        '-o', 'IdentitiesOnly=yes',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=30',
        $alias->remoteHostWithUser(),
        $project,
      ], NULL, NULL, NULL, NULL);

      $stderr = $this->runStreaming($process, $dump);
      $size = (int) filesize($dump);
      $gzip_ok = $size > 0 && $this->gzipTest($dump);

      $io = $this->io();
      $io->writeln(sprintf('Project: %s', $project));
      $io->writeln(sprintf('Remote: %s', $alias->remoteHostWithUser()));
      $io->writeln(sprintf('Exit code: %d', (int) $process->getExitCode()));
      $io->writeln(sprintf('Downloaded: %d bytes', $size));
      $io->writeln(sprintf('gzip -t: %s', $gzip_ok ? 'OK' : 'FAILED'));
      if (trim($stderr) !== '') {
        $io->section('Remote stderr');
        $io->writeln(rtrim($stderr));
      }
      $host = $alias->remoteHost();
      foreach ($this->checkHints($stderr, is_string($host) ? $host : '') as $hint) {
        $io->warning($hint);
      }

      return $process->isSuccessful() && $gzip_ok
        ? self::EXIT_SUCCESS
        : self::EXIT_FAILURE;
    }
    finally {
      (new Filesystem())->remove($dir);
    }
  }

  /**
   * Fetches the snapshot and imports it into the local database.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
  #[CLI\Command(name: 'ci-snapshot:pull')]
  #[CLI\OptionsetSql]
  #[CLI\Option(name: 'project', description: 'Project name to fetch. Defaults to the DDEV project name.')]
  #[CLI\Option(name: 'file', description: 'Import this snapshot file instead of fetching one over SSH.')]
  #[CLI\Option(name: 'deploy', description: 'Run <info>drush deploy</info> after the import.')]
  #[CLI\Usage(name: 'ddev drush ci-snapshot:pull', description: 'Replace the local database with the scrubbed production snapshot.')]
  #[CLI\Usage(
    name: 'ddev drush ci-snapshot:pull --file=/tmp/snapshot.sql.gz',
    description: 'Import a snapshot that was fetched earlier instead of fetching one.',
  )]
  public function pull(
    array $options = [
      'project' => NULL,
      'file' => NULL,
      'deploy' => FALSE,
    ],
  ): int {
    $this->assertDdev();
    $project = $this->projectName($this->stringOption($options, 'project'));
    $file = $this->stringOption($options, 'file');
    $deploy = !empty($options['deploy']);

    $question = 'Replace the local database with the production snapshot?';
    $question .= ' Run `ddev snapshot` first if you want to keep it.';
    if (!$this->io()->confirm($question, FALSE)) {
      throw new UserAbortException();
    }

    $dir = $this->tempDir();
    try {
      if ($file !== '') {
        if (!is_file($file) || !is_readable($file)) {
          throw new \RuntimeException(sprintf('Snapshot file %s is not readable.', $file));
        }
        // Drush's sql:query gunzips in place, which would delete the caller's
        // file, so the import always works on a copy.
        $snapshot = $dir . '/snapshot' . $this->suffix($file);
        if (!copy($file, $snapshot)) {
          throw new \RuntimeException(sprintf('Cannot copy %s to %s.', $file, $snapshot));
        }
        $this->io()->note(sprintf('Using the snapshot file %s.', $file));
      }
      else {
        $snapshot = $dir . '/snapshot.sql.gz';
        $this->fetchSnapshot($project, $snapshot);
      }

      $this->importSnapshot($snapshot, $deploy, $options);
      return self::EXIT_SUCCESS;
    }
    finally {
      (new Filesystem())->remove($dir);
    }
  }

  /**
   * Fetches a snapshot with the developer's own SSH access.
   *
   * @param string $project
   *   The project name, already validated.
   * @param string $target
   *   File to write the gzipped dump to.
   */
  private function fetchSnapshot(string $project, string $target): void {
    // Not the dispatcher: this is the developer's own access (ddev auth ssh),
    // so it may run the script itself. The server-side cache means this usually
    // does not dump the live database.
    $remote = sprintf('bash ~/apps/%s/current/misc/snapshot/ci-snapshot.sh', $project);
    $process = Drush::process([
      'ssh',
      '-o', 'BatchMode=yes',
      '-o', 'ConnectTimeout=30',
      $this->productionAlias()->remoteHostWithUser(),
      $remote,
    ], NULL, NULL, NULL, NULL);

    $stderr = $this->runStreaming($process, $target);
    if ($process->isSuccessful()) {
      return;
    }
    if (trim($stderr) !== '') {
      $this->io()->warning(rtrim($stderr));
    }
    $this->io()->warning('Run `ddev auth ssh` to load your SSH key into the container, then retry.');
    throw new \RuntimeException('Could not fetch the snapshot over SSH.');
  }

  /**
   * Replaces the local database with a snapshot.
   *
   * @param string $snapshot
   *   The gzipped (or plain) SQL dump to import.
   * @param bool $deploy
   *   Whether to run `drush deploy` after the import.
   * @param array<string, mixed> $options
   *   The command options, for the SQL target options.
   */
  private function importSnapshot(string $snapshot, bool $deploy, array $options): void {
    if (str_ends_with(strtolower($snapshot), '.gz') && !$this->gzipTest($snapshot)) {
      throw new \RuntimeException(sprintf('%s is not a valid gzip file.', $snapshot));
    }

    $sql_options = $this->sqlOptions($options);
    $self = Drush::aliasManager()->getSelf();
    $manager = Drush::processManager();

    $this->io()->section('Replacing the local database');
    $drop = $manager->drush($self, 'sql:drop', [], $sql_options);
    $drop->mustRun($drop->showRealtime());
    $import = $manager->drush($self, 'sql:query', [], $sql_options + ['file' => $snapshot]);
    $import->mustRun($import->showRealtime());

    // cache:rebuild and deploy accept neither --db-url nor --database, so they
    // can only ever act on the site's own database.
    if (isset($sql_options['db-url']) || isset($sql_options['database'])) {
      $this->io()->note('Another database was targeted, so the cache rebuild and deploy are skipped.');
    }
    else {
      $this->io()->section('Rebuilding caches');
      $rebuild = $manager->drush($self, 'cache:rebuild', [], ['yes' => TRUE]);
      $rebuild->mustRun($rebuild->showRealtime());

      if ($deploy) {
        $this->io()->section('Running deploy');
        $deploy_process = $manager->drush($self, 'deploy', [], ['yes' => TRUE]);
        $deploy_process->mustRun($deploy_process->showRealtime());
      }
    }

    $this->io()->success('Imported the production snapshot.');
  }

  /**
   * Forwards the SQL target options to the commands that import the dump.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return array<string, mixed>
   *   Options for the nested Drush calls.
   */
  private function sqlOptions(array $options): array {
    $result = ['yes' => TRUE];
    $db_url = $this->stringOption($options, 'db-url');
    if ($db_url !== '') {
      $result['db-url'] = $db_url;
    }
    $database = $this->stringOption($options, 'database');
    if ($database !== '' && $database !== 'default') {
      $result['database'] = $database;
    }
    return $result;
  }

  /**
   * Runs a process and streams its output into a file.
   *
   * Streaming keeps a large dump out of PHP's memory.
   *
   * @param \Symfony\Component\Process\Process $process
   *   The process to run.
   * @param string $target
   *   File to write the standard output to.
   *
   * @return string
   *   The standard error output.
   */
  private function runStreaming(Process $process, string $target): string {
    $handle = fopen($target, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Cannot write to %s.', $target));
    }
    $stderr = '';
    try {
      $process->run(function (string $type, string $buffer) use ($handle, &$stderr): void {
        if ($type === Process::ERR) {
          $stderr .= $buffer;
        }
        else {
          fwrite($handle, $buffer);
        }
      });
    }
    finally {
      fclose($handle);
    }
    return $stderr;
  }

  /**
   * Returns an option value as a string.
   *
   * @param array<string, mixed> $options
   *   The command options.
   * @param string $name
   *   The option name.
   *
   * @return string
   *   The value, or an empty string when the option is not a string.
   */
  private function stringOption(array $options, string $name): string {
    $value = $options[$name] ?? '';
    return is_string($value) ? $value : '';
  }

  /**
   * Checks a file with gzip.
   *
   * @param string $file
   *   The file to test.
   *
   * @return bool
   *   Whether gzip accepts the file.
   */
  private function gzipTest(string $file): bool {
    $process = Drush::process(['gzip', '-t', $file], NULL, NULL, NULL, NULL);
    $process->run();
    return $process->isSuccessful();
  }

  /**
   * Reads the shared CI key from STDIN.
   *
   * @return string
   *   The key, ending in a newline.
   */
  private function readKey(): string {
    if (stream_isatty(STDIN)) {
      throw new \RuntimeException('No key on STDIN. Usage: ' . self::KEY_USAGE);
    }
    $key = stream_get_contents(STDIN);
    if ($key === FALSE || trim($key) === '') {
      throw new \RuntimeException('No key on STDIN. Usage: ' . self::KEY_USAGE);
    }
    return rtrim($key) . "\n";
  }

  /**
   * Resolves the project name to use.
   *
   * @param string $option
   *   The --project value, if any.
   *
   * @return string
   *   The validated project name.
   */
  private function projectName(string $option): string {
    $name = $option !== '' ? $option : (string) getenv('DDEV_SITENAME');
    // The same rule as ci-snapshot-dispatch.sh: the name ends up in a path and
    // in an SSH command, so only plain lowercase names are allowed.
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
      throw new \RuntimeException(sprintf('Invalid project name "%s".', $name));
    }
    return $name;
  }

  /**
   * Returns the @production site alias.
   *
   * @return \Consolidation\SiteAlias\SiteAliasInterface
   *   The production alias.
   */
  private function productionAlias(): SiteAliasInterface {
    $alias = Drush::aliasManager()->get('@production');
    if ($alias === FALSE) {
      throw new \RuntimeException('The @production site alias is missing; define it in drush/sites/self.site.yml.');
    }
    return $alias;
  }

  /**
   * Refuses to run outside DDEV.
   */
  private function assertDdev(): void {
    // `drush @production ci-snapshot:pull` is redispatched to the server, where
    // the SQL commands would replace the production database instead of the
    // local one.
    if (getenv('IS_DDEV_PROJECT') !== 'true') {
      throw new \RuntimeException('The ci-snapshot commands only run inside DDEV: use `ddev drush ci-snapshot:...`.');
    }
  }

  /**
   * Creates a private temporary directory.
   *
   * @return string
   *   Its absolute path.
   */
  private function tempDir(): string {
    $dir = sys_get_temp_dir() . '/ci-snapshot-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Cannot create the temporary directory %s.', $dir));
    }
    return $dir;
  }

  /**
   * Returns the file suffix to use for a copy of a snapshot.
   *
   * @param string $file
   *   The original file.
   *
   * @return string
   *   The suffix, so Drush knows whether to decompress.
   */
  private function suffix(string $file): string {
    return str_ends_with(strtolower($file), '.gz') ? '.sql.gz' : '.sql';
  }

  /**
   * Turns the remote messages into hints.
   *
   * @param string $stderr
   *   The remote standard error output.
   * @param string $host
   *   The remote host, for the known_hosts hint.
   *
   * @return string[]
   *   The hints to show.
   */
  private function checkHints(string $stderr, string $host): array {
    $hints = [];
    if (str_contains($stderr, 'invalid project name')) {
      $hints[] = 'The dispatcher rejected the project name; check --project.';
    }
    if (str_contains($stderr, 'unknown project')) {
      $hints[] = 'The project is not deployed under ~/apps on the server (yet).';
    }
    if (str_contains($stderr, 'No such file') && str_contains($stderr, 'ci-snapshot-dispatch')) {
      $hints[] = 'The dispatcher is not installed on the server; see the header of misc/snapshot/ci-snapshot-dispatch.sh.';
    }
    if (str_contains($stderr, 'command not found')) {
      $hints[] = 'The key matches an authorized_keys line without command=, so SSH ran the project name as a command.';
    }
    if (str_contains($stderr, 'Permission denied (publickey)')) {
      $hints[] = 'The key is not authorized; check the authorized_keys line for the shared CI key.';
    }
    if (str_contains($stderr, 'Host key verification failed')) {
      $hints[] = sprintf('The host key is not known in the container; add it with: ddev exec ssh-keyscan -H %s >> ~/.ssh/known_hosts', $host);
    }
    return $hints;
  }

}
