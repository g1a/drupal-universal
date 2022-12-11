<?php

/**
 * @file
 * Contains \DrupalUniversal\ComposerScripts.
 */

namespace DrupalUniversal;

use Composer\Script\Event;
use Composer\Semver\Comparator;
use Drupal\Core\Site\Settings;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

class ComposerScripts {

  /**
   * postUpdate
   *
   * After "composer update" runs, we have the opportunity to do additional
   * fixups to the project files.
   *
   * @param Composer\Script\Event $event
   *   The Event object passed in from Composer
   */
  public static function postUpdate(Event $event) {
    static::starterProjectConfiguration();
  }

  /**
   * starterProjectConfiguration
   *
   * If the top-level composer.json file has a config.starter element, then
   * we will do one-time project configuration to set things up for continuing
   * development. At the moment, there is ony one supported starter
   * configuration optoion: refine the starting project constraints.
   *
   * The 'refine-constraints' element contains a list of regular expressions
   * matching prjoects in the "require" or "require-dev" sections of the
   * composer.json file. Any matching project with a flexible major release
   * constraint will be rewritten to instead constrain to whatever major
   * version of that component was installed. For example, if Drupal is
   * constrained to version `*` (any version), and Drupal 9 is installed, then
   * the constraint will be updated to ^9. This keeps the site on Drupal 9
   * until the site owner modifies the composer.json file to allos Drupal 10.
   */
  public static function starterProjectConfiguration() {
    $composerJsonContents = file_get_contents("composer.json");
    $composerJson = json_decode($composerJsonContents, true);

    // Silently exit if we do not have any starter configuration
    if (!isset($composerJson['config']['starter']['refine-constraints'])) {
      return;
    }

    if (!file_exists('composer.lock')) {
      print "we need a composer.lock to work; please run 'composer install' or 'composer update'\n";
      return;
    }

    print "Configuring starter project\n";

    $composerLockContents = file_get_contents("composer.lock");
    $composerLock = json_decode($composerLockContents, true);

    // Refine the constraints
    $projectsToRefine = $composerJson['config']['starter']['refine-constraints'];
    $composerJson['require'] = static::refineConstraints($composerJson['require'], $projectsToRefine, $composerLock);
    $composerJson['require-dev'] = static::refineConstraints($composerJson['require-dev'], $projectsToRefine, $composerLock);

    // Remove the starter configuration; we only do this once
    unset($composerJson['config']['starter']);

    // Write the modified composer.json file
    $composerJsonContents = static::jsonEncodePretty($composerJson);
    file_put_contents("composer.json", $composerJsonContents . PHP_EOL);
  }

  /**
   * jsonEncodePretty
   *
   * Convert a nested array into a pretty-printed json-encoded string.
   *
   * @param array $data
   *   The data array to encode
   * @return string
   *   The pretty-printed encoded string version of the supplied data.
   */
  public static function jsonEncodePretty($data) {
    $prettyContents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $prettyContents = preg_replace('#": \[\s*("[^"]*")\s*\]#m', '": [\1]', $prettyContents);

    return $prettyContents;
  }

  /**
   * refineConstraints
   *
   * Alter the version constraints of a list of projects based on the current
   * composer.lock data.
   *
   * @param array $projects
   *   A mapping from a project name (e.g. "drupal/core-recommended") to its
   *   version constraint.
   * @param array $projectsToRefine
   *   A list of project names (or regular expressions) of projects to modify.
   * @param array $composerLock
   *   Contents of the composer.lock file.
   * @return array
   *   The $projects input array with altered version constriants where stipulated
   */
  public static function refineConstraints($projects, $projectsToRefine, $composerLock) {
    foreach ($projects as $project => $constraint) {
      if (static::isMatchingProject($project, $projectsToRefine)) {
        $versionFromLockFile = static::versionFromLockFile($project, $composerLock);
        $refinedConstraint = static::constraintFromLockedVersion($versionFromLockFile);
        print "  - $project: $refinedConstraint\n";
        $projects[$project] = $refinedConstraint;
      }
    }

    return $projects;
  }

  /**
   * isMatchingProject
   *
   * Determines if the specified project matches any of a series of project
   * regexs.
   *
   * @param string $project
   *   A project name, e.g. "drupal/core-recommended"
   * @param array $projectsToRefine
   *   A list of project names (or regular expressions) of projects to modify.
   * @return bool
   *   'true' if $project matches any regex in $projectsToRefine
   */
  public static function isMatchingProject($project, $projectsToRefine) {
    foreach ($projectsToRefine as $pattern) {
      if (preg_match("#$pattern#", $project)) {
        return true;
      }
    }
    return false;
  }

  /**
   * versionFromLockFile
   *
   * Look up the version that the specified project was installed at per the
   * data in the current composer.lock file.
   *
   * @param string $project
   *   A project name, e.g. "drupal/core-recommended"
   * @param array $composerLock
   *   Contents of the composer.lock file.
   * @return string
   *   Installed version of the requested project from the lock file data,
   *   or an empty string if not found.
   */
  public static function versionFromLockFile($project, $composerLock) {
    foreach (array_merge($composerLock['packages'], $composerLock['packages-dev']) as $package) {
      if ($package['name'] == $project) {
        return $package['version'];
      }
    }
    return '';
  }

  /**
   * constraintFromLockedVersion
   *
   * Convert from an installed version number to a version constraint
   *
   * @param string $versionFromLockFile
   *   A semver version from the lock file, e.g '9.4.9'
   * @return string
   *   Corresponding version constraint locked on the major version only,
   *   e.g. '^9'
   */
  public static function constraintFromLockedVersion($versionFromLockFile) {
    $versionParts = explode('.', $versionFromLockFile);

    return '^' . $versionParts[0];
  }

  /**
   * Add a dependency to the upstream-configuration section of a custom upstream.
   *
   * The upstream-configuration/composer.json is a place to put modules, themes
   * and other dependencies that will be inherited by all sites created from
   * the upstream. Separating the upstream dependencies from the site dependencies
   * has the advantage that changes can be made to the upstream without causing
   * conflicts in the downstream sites.
   *
   * To add a dependency to an upstream:
   *
   *    composer upstream-require drupal/modulename
   *
   * Important: Dependencies should only be removed from upstreams with caution.
   * The module / theme must be uninstalled from all sites that are using it
   * before it is removed from the code base; otherwise, the module cannot be
   * cleanly uninstalled.
   */
  public static function upstreamRequire(Event $event) {
    $io = $event->getIO();
    $composer = $event->getComposer();
    $name = $composer->getPackage()->getName();
    $gitRepoUrl = exec('git config --get remote.origin.url');

    // Refuse to run if:
    //   - This is a clone of the standard Pantheon upstream, and it hasn't been renamed
    //   - This is an local working copy of a Pantheon site instread of the upstream
    $isPantheonStandardUpstream = (strpos($name, 'pantheon-systems/drupal-universal') !== false);
    $isPantheonSite = (strpos($gitRepoUrl, '@codeserver') !== false);

    if ($isPantheonStandardUpstream || $isPantheonSite) {
      $io->writeError("<info>The upstream-require command can only be used with a custom upstream</info>");
      $io->writeError("<info>See https://pantheon.io/docs/create-custom-upstream for information on how to create a custom upstream.</info>" . PHP_EOL);
      throw new \RuntimeException("Cannot use upstream-require command with this project.");
    }

    // Find arguments that look like projects.
    $packages = [];
    foreach ($event->getArguments() as $arg) {
      if (preg_match('#[a-zA-Z][a-zA-Z0-9_-]*/[a-zA-Z][a-zA-Z0-9]:*[~^]*[0-9a-z._-]*#', $arg)) {
        $packages[] = $arg;
      }
    }

    // Insert the new projects into the upstream-configuration composer.json
    // without updating the lock file or downloading the projects
    $packagesParam = implode(' ', $packages);
    $cmd = "composer --working-dir=upstream-configuration require --no-update $packagesParam";
    $io->writeError($cmd . PHP_EOL);
    passthru($cmd);

    // Update composer.lock & etc. if present
    static::updateLocalDependencies($io, $packages);
  }

  /**
   * Prepare for Composer to update dependencies.
   *
   * Composer will attempt to guess the version to use when evaluating
   * dependencies for path repositories. This has the undesirable effect
   * of producing different results in the composer.lock file depending on
   * which branch was active when the update was executed. This can lead to
   * unnecessary changes, and potentially merge conflicts when working with
   * path repositories on Pantheon multidevs.
   *
   * To work around this problem, it is possible to define an environment
   * variable that contains the version to use whenever Composer would normally
   * "guess" the version from the git repository branch. We set this invariantly
   * to "dev-main" so that the composer.lock file will not change if the same
   * update is later ran on a different branch.
   *
   * @see https://github.com/composer/composer/blob/main/doc/articles/troubleshooting.md#dependencies-on-the-root-package
   */
  public static function preUpdate(Event $event) {
    $io = $event->getIO();

    // We will only set the root version if it has not already been overriden
    if (!getenv('COMPOSER_ROOT_VERSION')) {
      // This is not an error; rather, we are writing to stderr.
      $io->writeError("<info>Using version 'dev-main' for path repositories.</info>");

      putenv('COMPOSER_ROOT_VERSION=dev-main');
    }
  }

  /**
   * Update the composer.lock file and so on.
   *
   * Upstreams should *not* commit the composer.lock file. If a local working
   * copy
   */
  private static function updateLocalDependencies($io, $packages) {
    if (!file_exists('composer.lock')) {
      return;
    }

    $io->writeError("<warning>composer.lock file present; do not commit composer.lock to a custom upstream, but updating for the purpose of local testing.");

    // Remove versions from the parameters, if any
    $versionlessPackages = array_map(
      function ($package) {
        return preg_replace('/:.*/', '', $package);
      },
      $packages
    );

    // Update the project-level composer.lock file
    $versionlessPackagesParam = implode(' ', $versionlessPackages);
    $cmd = "composer update $versionlessPackagesParam";
    $io->writeError($cmd . PHP_EOL);
    passthru($cmd);
  }
}
