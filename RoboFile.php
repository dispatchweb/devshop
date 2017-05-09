<?php
/**
 * This file provides commands to the robo CLI for managing development and testing of devshop.
 *   1. Install robo CLI: http://robo.li/
 *   2. Clone this repo and change into the directory.
 *   3. Run `robo` to see the commands.
 *   4. If you have drush, docker, and docker compose, you can launch a devshop with `robo up`
 *
 * Available commands:
 *
 *   destroy             Destroy all containers, docker volumes, and aegir configuration.
 *   help                Displays help for a command
 *   launch              Launch devshop after running prep:host and prep:source. Use --build to build new local containers.
 *   list                Lists commands
 *   login               Get a one-time login link to Devamster.
 *   logs                Stream logs from the containers using docker-compose logs -f
 *   shell               Enter a bash shell in the devmaster container.
 *   stop                Stop devshop containers using docker-compose stop
 *   test                Run all devshop tests on the containers.
 *   up                  Launch devshop containers using docker-compose up and follow logs.
 *   prepare
 *   prepare:containers  Build aegir and devshop containers from the Dockerfiles. Detects your UID or you can pass as an argument.
 *   prepare:host        Check for docker, docker-compose and drush. Install them if they are missing.
 *   prepare:sourcecode  Clone all needed source code and build devmaster from the makefile.
 *
 * @see http://robo.li/
 */
class RoboFile extends \Robo\Tasks
{
  
  // Install this version first when testing upgrades.
  const UPGRADE_FROM_VERSION = '1.0.0-beta10';
  
  // The version of docker-compose to suggest the user install.
  const DOCKER_COMPOSE_VERSION = '1.10.0';
  
  // Defines where devmaster is installed.  'aegir-home/devmaster-$DEVSHOP_LOCAL_VERSION'
  const DEVSHOP_LOCAL_VERSION = '1.x';
  
  // Defines the URI we will use for the devmaster site.
  const DEVSHOP_LOCAL_URI = 'devshop.local.computer';
  
  /**
   * Launch devshop after running prep:host and prep:source. Use --build to build new local containers.
   *
   * If you only run one command, run this one.
   */
  public function launch($opts = ['build' => 0]) {
    $this->prepareHost();
    $this->prepareSourcecode();
    
    if ($opts['build']) {
      $this->prepareContainers();
    }
      
    $this->up(['follow' => TRUE]);
  }
  
  /**
   * Check for docker, docker-compose and drush. Install them if they are missing.
   */
  public function prepareHost() {
    // Check for docker
    $this->say('Checking for Docker...');
    if ($this->taskDockerRun('hello-world')->printed(FALSE)->run()->wasSuccessful()) {
      $this->_exec('docker -v');
      $this->say('Docker detected.');
    }
    else {
      $this->say('Could not run docker command. Find instructons for installing at https://www.docker.com/products/docker');
    }
    
    // Check for docker-compose
    $this->say('Checking for docker-compose...');
    if ($this->_exec('docker-compose -v')->wasSuccessful()) {
      $this->say('docker-compose detected.');
    }
    else {
      $this->yell('Could not run docker-compose command.', 40, 'red');
      $this->say("Run the following command as root to install it or see https://docs.docker.com/compose/install/ for more information.");
      
      $this->say('curl -L "https://github.com/docker/compose/releases/download/'  . self::DOCKER_COMPOSE_VERSION .'/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose && chmod +x /usr/local/bin/docker-compose');
    }
    
    // Check for drush
    $this->say('Checking for drush...');
    if ($this->_exec('drush --version')->wasSuccessful()) {
      $this->say('drush detected.');
    }
    else {
      $this->yell('Could not run drush.', 40, 'red');
      $this->say("Run the following command as root to install it or see http://www.drush.org/en/master/install/ for more information.");
      
      $this->say('php -r "readfile(\'https://s3.amazonaws.com/files.drush.org/drush.phar\');" > /usr/local/bin/drush
 && chmod +x /usr/local/bin/drush');
    }
  }
  
  private $repos = [
    'provision' => 'http://git.drupal.org/project/provision.git',
    'aegir-home/.drush/commands/registry_rebuild' => 'http://git.drupal.org/project/registry_rebuild.git',
    'documentation' => 'http://github.com/opendevshop/documentation.git',
    'dockerfiles' => 'http://github.com/opendevshop/dockerfiles.git',
    'aegir-dockerfiles' => 'http://github.com/aegir-project/dockerfiles.git',
  ];
  
  /**
   * Clone all needed source code and build devmaster from the makefile.
   */
  public function prepareSourcecode() {
    
    
    // Create the Aegir Home directory.
    if (file_exists("aegir-home/.drush/commands")) {
      $this->say("aegir-home/.drush/commands already exists.");
    }
    else {
      $this->taskExecStack()
        ->exec('mkdir -p aegir-home/.drush/commands')
        ->run();
    }
    
    // Clone all git repositories.
    foreach ($this->repos as $path => $url) {
      if (file_exists($path)) {
        $this->say("$path already exists.");
      }
      else {
        $this->taskGitStack()
          ->cloneRepo($url, $path)
          ->run();
      }
    }
    
    // Run drush make to build the devmaster stack.
    $make_destination = "aegir-home/devmaster-1.x";
    $makefile_path = "build-devmaster.make";

    if (file_exists($make_destination)) {
      $this->say("Path {$make_destination} already exists.");
    } else {
      
      $result = $this->_exec("drush make {$makefile_path} {$make_destination} --working-copy --no-gitinfofile");
      if ($result->wasSuccessful()) {
        $this->say('Built devmaster from makefile.');
        return TRUE;
      }
      else {
        $this->say("Drush make failed with the exit code " . $result->getExitCode());
        return FALSE;
      }
    }
  }
  
  /**
   * Build aegir and devshop containers from the Dockerfiles. Detects your UID or you can pass as an argument.
   */
  public function prepareContainers($user_uid = NULL) {
  
    if (is_null($user_uid)) {
      $user_uid = $this->_exec('id -u')->getMessage();
    }

    $this->say("Found UID $user_uid. Passing to docker build as a build-arg...");

    // aegir/hostmaster
    $this->taskDockerBuild('aegir-dockerfiles')
      ->option('file', 'aegir-dockerfiles/Dockerfile')
      ->option('build-arg', "AEGIR_UID=$user_uid")
      ->tag('aegir/hostmaster')
      ->run()
      ;
    // aegir/hostmaster:xdebug
    $this->taskDockerBuild('aegir-dockerfiles')
      ->option('file', 'aegir-dockerfiles/Dockerfile-xdebug')
      ->tag('aegir/hostmaster:xdebug')
      ->run()
      ;
    // devshop/devmaster
    $this->taskDockerBuild('dockerfiles')
      ->option('file', 'dockerfiles/Dockerfile')
      ->tag('devshop/devmaster')
      ->run()
      ;
    // devshop/devmaster:xdebug
    $this->taskDockerBuild('dockerfiles')
      ->option('file', 'dockerfiles/Dockerfile-xdebug')
      ->tag('devshop/devmaster:xdebug')
      ->run()
      ;
    // aegir/web
    $this->taskDockerBuild('aegir-dockerfiles')
      ->option('file', 'aegir-dockerfiles/Dockerfile-web')
      ->tag('aegir/web')
      ->run()
      ;
  }
  
  /**
   * Launch devshop containers using docker-compose up and follow logs.
   *
   * Use "--test" option to run tests instead of the hosting queue.
   */
  public function up($opts = [
    'follow' => 1,
    'test' => false,
    'test-upgrade' => false,

    // Set 'mode' => 'install.sh' to run a traditional OS install.
    'mode' => 'docker-compose',
    'install-sh-image' => 'ubuntu:14.04'
  ]) {
    
    if (!file_exists('aegir-home')) {
      if ($opts['no-interaction'] || $this->ask('aegir-home does not yet exist. Run "prepare:sourcecode" command?')) {
        if ($this->prepareSourcecode() == FALSE) {
          $this->say('Prepare source code failed.');
          exit(1);
        }
      }
      else {
        $this->say('aegir-home must exist for devshop to work. Not running docker-compose up.');
        return;
      }
    }

    $command = "/usr/share/devshop/tests/run-tests.sh";
    if ($opts['mode'] == 'docker-compose') {
    if ($opts['test']) {
      $cmd = "docker-compose run -e BEHAT_PATH={$_SERVER['BEHAT_PATH']} -e TERM=xterm devmaster '$command'";
    }
    elseif ($opts['test-upgrade']) {
      $version = self::UPGRADE_FROM_VERSION;
      $command .= ' --upgrade';

      // @TODO: Have this detect the branch and use that for the version.
      $root_target = '/var/aegir/devmaster-1.x';

//      $cmd = "docker-compose run -e UPGRADE_FROM_VERSION={$version} -e UPGRADE_TO_MAKEFILE= -e AEGIR_HOSTMASTER_ROOT=/var/aegir/devmaster-{$version} -e AEGIR_VERSION={$version} -e AEGIR_MAKEFILE=https://raw.githubusercontent.com/opendevshop/devshop/{$version}/build-devmaster.make -e TRAVIS_BRANCH={$_SERVER['TRAVIS_BRANCH']}  -e TRAVIS_REPO_SLUG={$_SERVER['TRAVIS_REPO_SLUG']} -e TRAVIS_PULL_REQUEST_BRANCH={$_SERVER['TRAVIS_PULL_REQUEST_BRANCH']} devmaster 'run-tests.sh' ";

      // Launch a devmaster container as if it were the last release, then run hostmaster-migrate on it, then run the tests.
      // @TODO: Instead of run-tests.sh, run a test-upgrade.sh script to run hostmaster-migrate, then run-tests.sh.
      $cmd = "docker-compose run -e BEHAT_PATH={$_SERVER['BEHAT_PATH']} -e TERM=xterm -e UPGRADE_FROM_VERSION={$version} -e AEGIR_HOSTMASTER_ROOT=/var/aegir/devmaster-{$version} -e AEGIR_HOSTMASTER_ROOT_TARGET=$root_target -e AEGIR_VERSION={$version} -e AEGIR_MAKEFILE=https://raw.githubusercontent.com/opendevshop/devshop/{$version}/build-devmaster.make -e PROVISION_VERSION=7.x-3.10 devmaster '$command'";
    }
    }
    elseif ($opts['mode'] == 'install.sh') {

      $init = [
        'centos:7' => '/usr/lib/systemd/systemd',
        'ubuntu:14.04' => '/sbin/init',
        'geerlingguy/docker-centos7-ansible' => '/sbin/init',
      ];

      # This is the list of test sites, set in .travis.yml.
      # This is so requests to these sites go back to localhost.
      if (empty($_SERVER['SITE_HOSTS'])) {
        $_SERVER['SITE_HOSTS'] = 'devshop.local.computer';
      }

      # Launch Server container
      if (!$this->taskDockerRun($opts['install-sh-image'])
        ->name('devshop_container')
        ->volume(__DIR__, '/usr/share/devshop')
        ->volume('aegir-home', '/var/aegir')
        ->option('--hostname', 'devshop.local.computer')
        ->option('--add-host', '"' . $_SERVER['SITE_HOSTS'] . '":127.0.0.1')
        ->option('--volume', '/sys/fs/cgroup:/sys/fs/cgroup:ro')
        ->detached()
        ->privileged()
        ->env('TERM', 'xterm')
        ->env('TRAVIS', true)
        ->env('TRAVIS_BRANCH', $_SERVER['TRAVIS_BRANCH'])
        ->env('TRAVIS_REPO_SLUG', $_SERVER['TRAVIS_REPO_SLUG'])
        ->env('TRAVIS_PULL_REQUEST_BRANCH', $_SERVER['TRAVIS_PULL_REQUEST_BRANCH'])
        ->exec('/usr/share/devshop/tests/run-tests.sh')
        ->exec($init[$opts['install-sh-image']])
        ->run()
        ->wasSuccessful() ) {
        $this->say('Docker Run failed.');
        exit(1);
      }

      # Install mysql first to ensure it is started.
      if ($opts['install-sh-image'] == 'ubuntu:14.04') {
        if (!$this->taskDockerExec('devshop_container')
          ->exec("sed -i 's/101/0/' /usr/sbin/policy-rc.d")
          ->run()
          ->wasSuccessful()
        ) {
          $this->say('Set init policy failed.');
          exit(1);
        }
      }

      # Run install script on the container.
      # @TODO: Run the last version on the container, then upgrade.
      if (!$this->taskDockerExec('devshop_container')
        ->exec('/usr/share/devshop/install.sh --server-webserver=apache')
        ->run()
        ->wasSuccessful() ) {
        $this->say('Docker Exec install.sh failed.');
        exit(1);
      }

      if ($opts['test']) {
        # Run test script on the container.
        if (!$this->taskDockerExec('devshop_container')
          ->exec('su - aegir -c  - drush @hostmaster vset hosting_queue_tasks_enabled 0 -y')
          ->run()
          ->wasSuccessful()
        ) {
          $this->say('Unable to disable hosting task queue.');
          exit(1);
        }

        # Run test script on the container.
        if (!$this->taskDockerExec('devshop_container')
          ->exec('su - aegir -c  - /usr/share/devshop/tests/run-tests.sh')
          ->run()
          ->wasSuccessful()
        ) {
          $this->say('Docker Exec run-tests.sh failed.');
          exit(1);
        }
      }
    }
    else {
      $cmd = "docker-compose up -d";
      if ($opts['follow']) {
        $cmd .= "; docker-compose logs -f";
      }
    }

    if (isset($cmd)) {
      if ($this->_exec($cmd)->wasSuccessful()) {
        exit(0);
      }
      else {
        exit(1);
      }
    }
  }
  
  /**
   * Stop devshop containers using docker-compose stop
   */
  public function stop() {
    $this->_exec('docker-compose stop');
  }
  
  /**
   * Destroy all containers, docker volumes, and aegir configuration.
   *
   * Running with --no-interaction will keep the drupal devmaster codebase in place.
   *
   * Running with --force
   */
  public function destroy($opts = ['force' => 0]) {
    $this->_exec('docker-compose kill');
    $this->_exec('docker-compose rm -fv');
    
    $version = self::DEVSHOP_LOCAL_VERSION;
    $uri = self::DEVSHOP_LOCAL_URI;
  
    if ( !$opts['force'] && ($opts['no-interaction'] || $this->confirm("Keep aegir-home/devmaster-{$version} folder?"))) {
      if ($this->taskFilesystemStack()
        ->remove('aegir-home/config')
        ->remove("aegir-home/projects")
        ->remove("aegir-home/.drush")
        ->run()
        ->wasSuccessful()) {
    
        // Remove devmaster site folder
        $this->_exec("sudo rm -rf aegir-home/devmaster-{$version}/sites/{$uri}");
        $this->_exec("sudo rm -rf aegir-home/devmaster-1.0.0-beta10/sites/{$uri}");
    
        $this->say("Deleted local folders. Source code is still in place.");
        $this->say("To launch a new instance, run `robo up`");
      }
      else {
        $this->yell('Unable to delete local folders! Remove manually to fully destroy your local install.');
      }
    }
    else {
      if ($this->_exec("sudo rm -rf aegir-home")->wasSuccessful()) {
        $this->say("Entire aegir-home folder deleted.");
      }
      else {
        $this->yell("Unable to delete aegir-home folder, even with sudo!");
      }
    }
  }
  
  /**
   * Stream logs from the containers using docker-compose logs -f
   */
  public function logs() {
    $this->_exec('docker-compose logs -f');
  }
  
  /**
   * Enter a bash shell in the devmaster container.
   */
  public function shell() {
    $this->say('Not yet implemented. Run the command:');
    $this->say('docker-compose exec devmaster bash');
  }
  
  /**
   * Run all devshop tests on the containers.
   */
  public function test() {
    $this->say('Not yet implemented. Run the command:');
    $this->say('docker-compose exec devmaster run-tests.sh');
  }
  
  /**
   * Get a one-time login link to Devamster.
   */
  public function login() {
    $this->_exec('docker-compose exec -T devmaster drush @hostmaster uli');
  }
}
