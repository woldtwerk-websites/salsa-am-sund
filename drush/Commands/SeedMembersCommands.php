<?php

declare(strict_types=1);

namespace Drush\Commands;

use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Exceptions\UserAbortException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeds demo members and realistic course participation.
 *
 * The command only writes data that a real dance school could have produced:
 * members register before the season, join the course that matches their
 * level, sometimes visit a second course and miss lessons now and then.
 */
final class SeedMembersCommands extends DrushCommands {

  /**
   * The role of the demo members.
   */
  private const string MEMBER_ROLE = 'member';

  /**
   * The mail domain that marks demo members.
   */
  private const string MAIL_DOMAIN = 'seed.invalid';

  /**
   * The course that absolute beginners join and usually stay in.
   */
  private const string COURSE_BEGINNER = 'Anfänger';

  /**
   * The course for members who want to dance to the music.
   */
  private const string COURSE_FEELING = 'Tanzfeeling u. mehr';

  /**
   * The course that teaches figures and elements.
   */
  private const string COURSE_FIGURES = 'Figuren + Elemente';

  /**
   * The course for members who already know the basics.
   */
  private const string COURSE_ADVANCED = 'Leicht Fortgeschrittene';

  /**
   * The relative size of the primary course groups.
   *
   * The beginner course is the largest, while the other three are roughly
   * equally popular. The values are weights, not fixed counts.
   */
  private const array COURSE_WEIGHTS = [
    self::COURSE_BEGINNER => 38,
    self::COURSE_FEELING => 21,
    self::COURSE_FIGURES => 22,
    self::COURSE_ADVANCED => 19,
  ];

  /**
   * First names for the demo members.
   */
  private const array FIRST_NAMES = [
    'Anna', 'Lena', 'Marie', 'Sophie', 'Laura', 'Julia', 'Katharina',
    'Christina', 'Sabine', 'Petra', 'Claudia', 'Andrea', 'Susanne',
    'Michaela', 'Nicole', 'Jana', 'Lea', 'Sarah', 'Lisa', 'Hanna', 'Emma',
    'Mia', 'Paula', 'Clara', 'Frieda', 'Jonas', 'Lukas', 'Felix',
    'Maximilian', 'Tobias', 'Daniel', 'Michael', 'Thomas', 'Stefan',
    'Andreas', 'Markus', 'Sebastian', 'Johannes', 'Florian', 'Philipp',
    'Matthias', 'Christian', 'Martin', 'Alexander', 'David', 'Simon',
    'Julian', 'Nils', 'Jan', 'Tim', 'Moritz', 'Hendrik', 'Ole', 'Jörg',
    'Björn', 'Kai', 'Uwe',
  ];

  /**
   * Last names for the demo members.
   */
  private const array LAST_NAMES = [
    'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner',
    'Becker', 'Schulz', 'Hoffmann', 'Schröder', 'Koch', 'Bauer', 'Richter',
    'Klein', 'Wolf', 'Neumann', 'Schwarz', 'Zimmermann', 'Braun', 'Krüger',
    'Hofmann', 'Hartmann', 'Lange', 'Schmitt', 'Werner', 'Schmitz', 'Krause',
    'Meier', 'Lehmann', 'Schulte', 'König', 'Walter', 'Mayer', 'Huber',
    'Kaiser', 'Fuchs', 'Peters', 'Lang', 'Scholz', 'Möller', 'Weiß', 'Jung',
    'Hahn', 'Vogel', 'Friedrich', 'Keller', 'Günther', 'Frank', 'Berger',
    'Winkler', 'Roth', 'Beck', 'Lorenz', 'Baumann', 'Franke', 'Albrecht',
    'Schuster', 'Simon', 'Ludwig',
  ];

  /**
   * Creates demo members and fills the lessons that already took place.
   *
   * @param int $count
   *   The total number of demo members.
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'seed:members', aliases: ['seed-members'])]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Argument(name: 'count', description: 'Total number of demo members. Defaults to 80.')]
  #[CLI\Option(name: 'reset', description: 'Delete the previously seeded demo members and their participation first.')]
  #[CLI\Option(name: 'seed', description: 'Random seed, so that repeated runs produce the same data.')]
  #[CLI\Option(name: 'password', description: 'Password of every demo member. Defaults to <info>seed</info>.')]
  #[CLI\Option(name: 'include-future', description: 'Also fill lessons that have not taken place yet. Not realistic, but useful for demos.')]
  #[CLI\Usage(name: 'ddev drush seed:members', description: 'Create 80 demo members and fill every lesson that has already taken place.')]
  #[CLI\Usage(name: 'ddev drush seed:members --reset', description: 'Delete the previous demo members and seed them from scratch.')]
  #[CLI\Usage(name: 'ddev drush seed:members 120 --seed=42', description: 'Create 120 demo members with a different deterministic distribution.')]
  public function seed(
    int $count = 80,
    array $options = [
      'reset' => FALSE,
      'seed' => 1,
      'password' => 'seed',
      'include-future' => FALSE,
    ],
  ): int {
    if ($count < 1 || $count > 1000) {
      throw new \RuntimeException('The member count must be between 1 and 1000.');
    }

    if (!$this->memberRoleExists()) {
      throw new \RuntimeException(sprintf('The "%s" role does not exist. Install the workspace_events module first.', self::MEMBER_ROLE));
    }

    $reset = (bool) ($options['reset'] ?? FALSE);
    $password = $this->stringOption($options, 'password', 'seed');
    $seed_option = $options['seed'] ?? 1;
    $seed = is_numeric($seed_option) ? (int) $seed_option : 1;
    $include_future = (bool) ($options['include-future'] ?? FALSE);
    $randomizer = new Randomizer(new Mt19937($seed));
    // A separate stream for the member data keeps the participation stable
    // when a second run reuses the members of the first one.
    $user_randomizer = new Randomizer(new Mt19937($seed + 1));

    if ($reset) {
      if (!$this->io()->confirm('Delete the previously seeded demo members and their participation?', FALSE)) {
        throw new UserAbortException();
      }
      $this->io()->note(sprintf('Deleted %d demo members.', $this->deleteSeedUsers()));
    }

    $courses = $this->loadCourses();
    $lessons = $this->loadLessons($courses, $include_future);
    $now = \Drupal::time()->getRequestTime();

    $users = $this->loadSeedUsers();
    if (count($users) > $count) {
      throw new \RuntimeException(sprintf(
        'There are already %d demo members, more than the requested %d. Run with --reset to recreate them.',
        count($users),
        $count,
      ));
    }

    $created_range = $this->registrationWindow($lessons, $now);
    $this->createMissingUsers($count, $users, $created_range, $password, $user_randomizer);
    $users = $this->loadSeedUsers();
    $seed_uids = array_map('intval', array_keys($users));

    $this->io()->section(sprintf('Seeding %d demo members', count($users)));
    $attendance = $this->buildAttendance($users, $lessons, $randomizer);
    $this->saveAttendance($attendance, $seed_uids, $lessons);
    $this->printSummary($lessons, $attendance);

    $this->io()->success(sprintf(
      'Seeded %d demo members with %d participations across %d lessons.',
      count($users),
      array_sum(array_map('count', $attendance)),
      count($attendance),
    ));

    return self::EXIT_SUCCESS;
  }

  /**
   * Checks that the member role exists.
   *
   * @return bool
   *   TRUE when the role exists.
   */
  private function memberRoleExists(): bool {
    return \Drupal::entityTypeManager()
      ->getStorage('user_role')
      ->load(self::MEMBER_ROLE) !== NULL;
  }

  /**
   * Loads the four courses by title.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   *   The courses keyed by title.
   */
  private function loadCourses(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $titles = array_keys(self::COURSE_WEIGHTS);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('title', $titles, 'IN')
      ->execute();

    $courses = [];
    /** @var \Drupal\node\NodeInterface $node */
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($node->bundle() === 'course') {
        $courses[(string) $node->label()] = $node;
      }
    }

    $missing = array_diff($titles, array_keys($courses));
    if ($missing !== []) {
      throw new \RuntimeException(sprintf(
        'These courses are missing: %s. Create them or adjust the course titles in this command.',
        implode(', ', $missing),
      ));
    }

    return $courses;
  }

  /**
   * Loads the lessons to fill, grouped by course title.
   *
   * @param array<string, \Drupal\node\NodeInterface> $courses
   *   The courses keyed by title.
   * @param bool $include_future
   *   Whether lessons that have not taken place yet are included.
   *
   * @return array<string, array<int, array{id: int, title: string, start: int, end: int}>>
   *   The lessons keyed by course title, sorted by date.
   */
  private function loadLessons(array $courses, bool $include_future): array {
    $course_ids = [];
    foreach ($courses as $title => $course) {
      $course_ids[$title] = (int) $course->id();
    }

    $season_storage = \Drupal::entityTypeManager()->getStorage('course_season');
    $season_ids = $season_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course', array_values($course_ids), 'IN')
      ->execute();

    // The lesson stores only the season, so map every season back to its
    // course title.
    $season_titles = [];
    foreach ($season_storage->loadMultiple($season_ids) as $season) {
      if (!$season instanceof SeasonInterface) {
        continue;
      }
      $course = $season->getCourse();
      if (!$course instanceof NodeInterface) {
        continue;
      }
      $title = (string) $course->label();
      if (isset($courses[$title])) {
        $season_titles[(int) $season->id()] = $title;
      }
    }
    if ($season_titles === []) {
      return [];
    }

    $lesson_storage = \Drupal::entityTypeManager()->getStorage('lesson');
    $lesson_ids = $lesson_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('season', array_keys($season_titles), 'IN')
      ->execute();

    $now = \Drupal::time()->getRequestTime();
    $lessons = [];
    /** @var \Drupal\workspace_events\Entity\LessonInterface $lesson */
    foreach ($lesson_storage->loadMultiple($lesson_ids) as $lesson) {
      $season_id = (int) $lesson->get('season')->getString();
      $title = $season_titles[$season_id] ?? NULL;
      $date = $lesson->get('field_lesson_date')->first();
      if ($title === NULL || $date === NULL) {
        continue;
      }

      $start = $this->dateTimestamp((string) $date->get('value')->getString());
      $end = $this->dateTimestamp((string) $date->get('end_value')->getString());
      if ($start === NULL || $end === NULL) {
        continue;
      }
      if (!$include_future && $end > $now) {
        continue;
      }
      if ($lesson->isCancelled()) {
        continue;
      }

      $lessons[$title][(int) $lesson->id()] = [
        'id' => (int) $lesson->id(),
        'title' => (string) $lesson->label(),
        'start' => $start,
        'end' => $end,
      ];
    }

    foreach ($lessons as &$course_lessons) {
      uasort($course_lessons, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
      // Re-index so that the attendance algorithm can use the position as the
      // lesson number of the course.
      $course_lessons = array_values($course_lessons);
    }
    unset($course_lessons);

    return $lessons;
  }

  /**
   * Converts a stored date to a timestamp.
   *
   * @param string $value
   *   The value in the format of the date field storage.
   *
   * @return int|null
   *   The timestamp, or NULL for an empty value.
   */
  private function dateTimestamp(string $value): ?int {
    if ($value === '') {
      return NULL;
    }

    $date = \DateTimeImmutable::createFromFormat(
      'Y-m-d\TH:i:s',
      $value,
      new \DateTimeZone('UTC'),
    );

    return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : NULL;
  }

  /**
   * Loads the previously seeded demo members.
   *
   * @return array<int, \Drupal\user\UserInterface>
   *   The demo members keyed by user ID, ordered by user ID.
   */
  private function loadSeedUsers(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $ids = $this->seedUserIds();

    $users = [];
    /** @var \Drupal\user\UserInterface $user */
    foreach ($storage->loadMultiple($ids) as $user) {
      $users[(int) $user->id()] = $user;
    }
    ksort($users);

    return $users;
  }

  /**
   * Returns the user IDs of the demo members.
   *
   * @return int[]
   *   The user IDs.
   */
  private function seedUserIds(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', '%@' . self::MAIL_DOMAIN, 'LIKE')
      // Never touch the anonymous user, the site owner or the current user,
      // even if one of them somehow uses the demo mail domain.
      ->condition('uid', [0, 1, (int) \Drupal::currentUser()->id()], 'NOT IN')
      ->sort('uid')
      ->execute();

    return array_map('intval', array_values($ids));
  }

  /**
   * Returns the time window in which demo members registered.
   *
   * @param array<string, array<int, array{id: int, title: string, start: int, end: int}>> $lessons
   *   The lessons keyed by course title.
   * @param int $now
   *   The request time.
   *
   * @return array{from: int, to: int, season_start: int}
   *   The first and last registration timestamp and the start of the season.
   */
  private function registrationWindow(array $lessons, int $now): array {
    $starts = [];
    foreach ($lessons as $course_lessons) {
      foreach ($course_lessons as $lesson) {
        $starts[] = $lesson['start'];
      }
    }

    // Members register in the weeks before the season starts.
    $season_start = $starts === [] ? $now : min($starts);
    $from = $season_start - 60 * 86400;
    $to = max($from + 1, $now - 86400);
    // Without a lesson to start from, every member registered in the past.
    $season_start = min($season_start, $to);

    return ['from' => $from, 'to' => $to, 'season_start' => $season_start];
  }

  /**
   * Creates the demo members that do not exist yet.
   *
   * @param int $count
   *   The requested total number of demo members.
   * @param array<int, \Drupal\user\UserInterface> $existing
   *   The demo members that already exist, keyed by user ID.
   * @param array{from: int, to: int, season_start: int} $created_range
   *   The registration window.
   * @param string $password
   *   The password of every new member.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   */
  private function createMissingUsers(int $count, array $existing, array $created_range, string $password, Randomizer $randomizer): void {
    $missing = $count - count($existing);
    if ($missing <= 0) {
      return;
    }

    $used_mails = [];
    $used_names = [];
    foreach ($existing as $user) {
      $used_mails[(string) $user->getEmail()] = TRUE;
      $used_names[$this->nameKey($user)] = TRUE;
    }

    $index = 1;
    for ($created = 0; $created < $missing; $created++) {
      while (isset($used_mails[sprintf('demo.%03d@%s', $index, self::MAIL_DOMAIN)])) {
        $index++;
      }
      $mail = sprintf('demo.%03d@%s', $index, self::MAIL_DOMAIN);
      $used_mails[$mail] = TRUE;

      [$first_name, $last_name] = $this->randomName($used_names, $randomizer);
      $used_names[$this->nameKey($first_name, $last_name)] = TRUE;

      $user = \Drupal::entityTypeManager()->getStorage('user')->create([
        'name' => $first_name . ' ' . $last_name,
        'mail' => $mail,
        'init' => $mail,
        'field_first_name' => $first_name,
        'field_last_name' => $last_name,
        'pass' => $password,
        'status' => TRUE,
        'roles' => [self::MEMBER_ROLE],
        'created' => $this->randomRegistrationTime($created_range, $randomizer),
        'timezone' => 'Europe/Berlin',
      ]);
      \assert($user instanceof UserInterface);
      $user->save();
    }

    $this->io()->note(sprintf('Created %d new demo members, %d already existed.', $missing, count($existing)));
  }

  /**
   * Draws a registration timestamp.
   *
   * Most members register before the season starts, so the first lessons are
   * not emptier than the later ones. A smaller share joins during the season.
   *
   * @param array{from: int, to: int, season_start: int} $range
   *   The registration window.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   *
   * @return int
   *   The registration timestamp.
   */
  private function randomRegistrationTime(array $range, Randomizer $randomizer): int {
    if ($range['season_start'] > $range['from'] && $range['season_start'] <= $range['to'] && $randomizer->getInt(1, 100) <= 70) {
      return $randomizer->getInt($range['from'], $range['season_start'] - 1);
    }

    return $randomizer->getInt($range['season_start'], $range['to']);
  }

  /**
   * Draws a first and last name that is not in use yet.
   *
   * @param array<string, true> $used_names
   *   The names that are already taken, keyed by the name key.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   *
   * @return array{0: string, 1: string}
   *   The first and last name.
   */
  private function randomName(array $used_names, Randomizer $randomizer): array {
    $attempts = 0;
    do {
      $first_name = self::FIRST_NAMES[$randomizer->getInt(0, count(self::FIRST_NAMES) - 1)];
      $last_name = self::LAST_NAMES[$randomizer->getInt(0, count(self::LAST_NAMES) - 1)];
      $attempts++;
    } while (isset($used_names[$this->nameKey($first_name, $last_name)]) && $attempts < 500);

    return [$first_name, $last_name];
  }

  /**
   * Returns the key used to compare names.
   *
   * @param \Drupal\user\UserInterface|string $user
   *   The user, or the first name.
   * @param string|null $last_name
   *   The last name, when a first name was passed.
   *
   * @return string
   *   The comparison key.
   */
  private function nameKey(UserInterface|string $user, ?string $last_name = NULL): string {
    if ($user instanceof UserInterface) {
      return mb_strtolower(sprintf(
        '%s|%s',
        trim($user->get('field_first_name')->getString()),
        trim($user->get('field_last_name')->getString()),
      ));
    }

    return mb_strtolower($user . '|' . ($last_name ?? ''));
  }

  /**
   * Builds the participation of every lesson.
   *
   * @param array<int, \Drupal\user\UserInterface> $users
   *   The demo members keyed by user ID.
   * @param array<string, array<int, array{id: int, title: string, start: int, end: int}>> $lessons
   *   The lessons keyed by course title.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   *
   * @return array<int, int[]>
   *   The participant user IDs keyed by lesson ID.
   */
  private function buildAttendance(array $users, array $lessons, Randomizer $randomizer): array {
    $primary_courses = $this->assignPrimaryCourses($users, $randomizer);
    $attendance = [];

    foreach ($users as $uid => $user) {
      $uid = (int) $uid;
      $created = (int) $user->getCreatedTime();
      $primary = $primary_courses[$uid];
      $primary_lessons = $lessons[$primary] ?? [];
      if ($primary_lessons === []) {
        continue;
      }

      $start = $this->startIndex($primary_lessons, $created);
      // Some members register early but only join the course a week or two
      // later.
      if ($created < $primary_lessons[0]['start'] && $randomizer->getInt(1, 100) <= 15) {
        $start += $randomizer->getInt(1, 2);
      }

      // A small share drops out of its primary course after a few lessons.
      $drop_after = $randomizer->getInt(1, 100) <= 5
        ? $start + $randomizer->getInt(1, 3)
        : NULL;

      $this->addAttendance(
        $attendance,
        $primary_lessons,
        $uid,
        $start,
        $randomizer->getInt(78, 99) / 100,
        $drop_after,
        $randomizer,
      );

      // Beginners stay in their course. A few start visiting the advanced
      // course after they have practiced for a few weeks.
      if ($primary === self::COURSE_BEGINNER && $randomizer->getInt(1, 100) <= 30) {
        $progression_start = max($created, $primary_lessons[0]['start']) + 14 * 86400;
        $this->addSecondaryCourse(
          $attendance,
          $lessons[self::COURSE_ADVANCED] ?? [],
          $uid,
          $this->startIndex($lessons[self::COURSE_ADVANCED] ?? [], $progression_start),
          $randomizer,
        );
      }

      // The two level-one courses are often visited together.
      if ($primary === self::COURSE_FEELING && $randomizer->getInt(1, 100) <= 70) {
        $this->addSecondaryCourse(
          $attendance,
          $lessons[self::COURSE_FIGURES] ?? [],
          $uid,
          $this->startIndex($lessons[self::COURSE_FIGURES] ?? [], $created),
          $randomizer,
        );
      }
      if ($primary === self::COURSE_FIGURES && $randomizer->getInt(1, 100) <= 70) {
        $this->addSecondaryCourse(
          $attendance,
          $lessons[self::COURSE_FEELING] ?? [],
          $uid,
          $this->startIndex($lessons[self::COURSE_FEELING] ?? [], $created),
          $randomizer,
        );
      }

      // Some advanced members still join the figures course now and then.
      if ($primary === self::COURSE_ADVANCED && $randomizer->getInt(1, 100) <= 25) {
        $this->addSecondaryCourse(
          $attendance,
          $lessons[self::COURSE_FIGURES] ?? [],
          $uid,
          $this->startIndex($lessons[self::COURSE_FIGURES] ?? [], $created),
          $randomizer,
        );
      }
    }

    foreach ($attendance as &$uids) {
      $uids = array_values(array_unique($uids));
      sort($uids);
    }
    unset($uids);

    return $attendance;
  }

  /**
   * Assigns every member a primary course.
   *
   * @param array<int, \Drupal\user\UserInterface> $users
   *   The demo members keyed by user ID.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   *
   * @return array<int, string>
   *   The course title keyed by user ID.
   */
  private function assignPrimaryCourses(array $users, Randomizer $randomizer): array {
    $uids = $randomizer->shuffleArray(array_keys($users));
    $counts = $this->splitCount(count($users), self::COURSE_WEIGHTS);

    $primary = [];
    $offset = 0;
    foreach ($counts as $course_title => $count) {
      foreach (array_slice($uids, $offset, $count) as $uid) {
        $primary[(int) $uid] = $course_title;
      }
      $offset += $count;
    }

    return $primary;
  }

  /**
   * Splits a total into integer counts by the given weights.
   *
   * @param int $total
   *   The total.
   * @param array<string, int> $weights
   *   The weights keyed by course title.
   *
   * @return array<string, int>
   *   The counts keyed by course title.
   */
  private function splitCount(int $total, array $weights): array {
    $sum = array_sum($weights);
    $counts = [];
    $remainders = [];
    foreach ($weights as $title => $weight) {
      $exact = $total * $weight / $sum;
      $counts[$title] = (int) floor($exact);
      $remainders[$title] = $exact - $counts[$title];
    }

    $left = $total - array_sum($counts);
    arsort($remainders);
    foreach (array_keys($remainders) as $title) {
      if ($left <= 0) {
        break;
      }
      $counts[$title]++;
      $left--;
    }

    return $counts;
  }

  /**
   * Returns the first lesson index a member can attend.
   *
   * @param array<int, array{id: int, title: string, start: int, end: int}> $lessons
   *   The lessons of a course, sorted by date.
   * @param int $created
   *   The registration timestamp.
   *
   * @return int
   *   The index of the first lesson, or the number of lessons when the member
   *   registered after the last one.
   */
  private function startIndex(array $lessons, int $created): int {
    foreach ($lessons as $index => $lesson) {
      if ($lesson['start'] >= $created) {
        return $index;
      }
    }

    return count($lessons);
  }

  /**
   * Adds a secondary course with a lower attendance probability.
   *
   * @param array<int, int[]> $attendance
   *   The attendance keyed by lesson ID.
   * @param array<int, array{id: int, title: string, start: int, end: int}> $lessons
   *   The lessons of the course.
   * @param int $uid
   *   The user ID.
   * @param int $start
   *   The first lesson index.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   */
  private function addSecondaryCourse(array &$attendance, array $lessons, int $uid, int $start, Randomizer $randomizer): void {
    if ($lessons === []) {
      return;
    }

    $this->addAttendance(
      $attendance,
      $lessons,
      $uid,
      $start,
      $randomizer->getInt(55, 85) / 100,
      NULL,
      $randomizer,
    );
  }

  /**
   * Adds one member to the lessons of a course.
   *
   * @param array<int, int[]> $attendance
   *   The attendance keyed by lesson ID.
   * @param array<int, array{id: int, title: string, start: int, end: int}> $lessons
   *   The lessons of the course, sorted by date.
   * @param int $uid
   *   The user ID.
   * @param int $start
   *   The first lesson index.
   * @param float $reliability
   *   The probability of attending a lesson, between 0 and 1.
   * @param int|null $drop_after
   *   Stop attending from this lesson index on, or NULL to keep attending.
   * @param \Random\Randomizer $randomizer
   *   The randomizer.
   */
  private function addAttendance(array &$attendance, array $lessons, int $uid, int $start, float $reliability, ?int $drop_after, Randomizer $randomizer): void {
    $skip_next = FALSE;
    foreach ($lessons as $index => $lesson) {
      if ($index < $start) {
        continue;
      }
      if ($drop_after !== NULL && $index >= $drop_after) {
        break;
      }
      if ($skip_next) {
        $skip_next = FALSE;
        continue;
      }

      if ($randomizer->getInt(1, 100) > (int) round($reliability * 100)) {
        // A missed lesson often becomes two missed lessons in a row.
        $skip_next = $randomizer->getInt(1, 100) <= 35;
        continue;
      }

      $attendance[$lesson['id']][] = $uid;
    }
  }

  /**
   * Writes the attendance to the lessons.
   *
   * Existing participants that are not demo members are kept. Every target
   * lesson is saved, so that demo members of a previous run are removed even
   * when the new run does not add any participant to that lesson.
   *
   * @param array<int, int[]> $attendance
   *   The participant user IDs keyed by lesson ID.
   * @param int[] $seed_uids
   *   The user IDs of the demo members.
   * @param array<string, array<int, array{id: int, title: string, start: int, end: int}>> $lessons
   *   The target lessons keyed by course title.
   */
  private function saveAttendance(array $attendance, array $seed_uids, array $lessons): void {
    $lesson_ids = [];
    foreach ($lessons as $course_lessons) {
      foreach ($course_lessons as $lesson) {
        $lesson_ids[] = $lesson['id'];
      }
    }

    $storage = \Drupal::entityTypeManager()->getStorage('lesson');
    $seed_uids = array_flip($seed_uids);

    /** @var \Drupal\workspace_events\Entity\LessonInterface $lesson */
    foreach ($storage->loadMultiple($lesson_ids) as $lesson) {
      $participants = [];
      foreach ($lesson->get('field_participants') as $item) {
        $target_id = (int) $item->get('target_id')->getString();
        if ($target_id > 0 && !isset($seed_uids[$target_id])) {
          $participants[$target_id] = $target_id;
        }
      }
      foreach ($attendance[(int) $lesson->id()] ?? [] as $uid) {
        $participants[$uid] = $uid;
      }
      ksort($participants);

      $lesson->set('field_participants', array_values($participants));
      $lesson->setSyncing(TRUE);
      $lesson->save();
    }
  }

  /**
   * Deletes the demo members and removes them from every lesson.
   *
   * @return int
   *   The number of deleted members.
   */
  private function deleteSeedUsers(): int {
    $seed_uids = $this->seedUserIds();
    if ($seed_uids === []) {
      return 0;
    }

    $seed_lookup = array_flip($seed_uids);
    $lesson_storage = \Drupal::entityTypeManager()->getStorage('lesson');
    $lesson_ids = $lesson_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_participants.target_id', $seed_uids, 'IN')
      ->execute();

    /** @var \Drupal\workspace_events\Entity\LessonInterface $lesson */
    foreach ($lesson_storage->loadMultiple($lesson_ids) as $lesson) {
      $participants = [];
      foreach ($lesson->get('field_participants') as $item) {
        $target_id = (int) $item->get('target_id')->getString();
        if ($target_id > 0 && !isset($seed_lookup[$target_id])) {
          $participants[] = $target_id;
        }
      }
      $lesson->set('field_participants', $participants);
      $lesson->setSyncing(TRUE);
      $lesson->save();
    }

    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $storage->delete($storage->loadMultiple($seed_uids));

    return count($seed_uids);
  }

  /**
   * Prints the participant count of every seeded lesson.
   *
   * @param array<string, array<int, array{id: int, title: string, start: int, end: int}>> $lessons
   *   The lessons keyed by course title.
   * @param array<int, int[]> $attendance
   *   The attendance keyed by lesson ID.
   */
  private function printSummary(array $lessons, array $attendance): void {
    $timezone_name = \Drupal::config('system.date')->get('timezone.default');
    $timezone = new \DateTimeZone(is_string($timezone_name) && $timezone_name !== '' ? $timezone_name : 'UTC');

    $rows = [];
    foreach ($lessons as $course_title => $course_lessons) {
      foreach ($course_lessons as $lesson) {
        $rows[] = [
          $course_title,
          (new \DateTimeImmutable('@' . $lesson['start']))->setTimezone($timezone)->format('d.m.Y H:i'),
          (string) count($attendance[$lesson['id']] ?? []),
        ];
      }
    }

    if ($rows === []) {
      $this->io()->warning('No lesson took place yet, so no participation was recorded. Use --include-future to seed upcoming lessons as well.');
      return;
    }

    $this->io()->table(['Course', 'Lesson (site time)', 'Participants'], $rows);
  }

  /**
   * Returns a string option.
   *
   * @param array<string, mixed> $options
   *   The command options.
   * @param string $name
   *   The option name.
   * @param string $default
   *   The fallback value.
   *
   * @return string
   *   The option value.
   */
  private function stringOption(array $options, string $name, string $default): string {
    $value = $options[$name] ?? NULL;
    return is_string($value) && $value !== '' ? $value : $default;
  }

}
