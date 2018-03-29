<?php

namespace DamianLewis\OctoberTester;

use Artisan;
use Dotenv\Dotenv;
use Exception;
use Mail;
use ReflectionClass;
use System\Classes\PluginManager;
use System\Classes\UpdateManager;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;
use October\Rain\Database\Model as ActiveRecord;

abstract class OctoberTestCase extends FoundationTestCase
{
    /**
     * Cache for storing which plugins have been loaded and refreshed.
     *
     * @var array
     */
    protected $pluginTestCaseLoadedPlugins = [];

    /**
     * Perform test case set up.
     *
     * @return void
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function setUp()
    {
        /*
         * Force reload of October singletons
         */
        PluginManager::forgetInstance();
        UpdateManager::forgetInstance();

        /*
         * Create application instance
         */
        parent::setUp();

        /*
         * Switch to the testing environment
         */
        if (file_exists(base_path($this->envTestingFile()))) {
            if (file_get_contents(base_path('.env')) !== file_get_contents(base_path($this->envTestingFile()))) {
                $this->switchEnvironment();
            }

            $this->refreshEnvironment();
        }

        /*
         * Ensure system is up to date
         */
        $this->runOctoberUpCommand();

        /*
         * Detect plugin from test and autoload it
         */
        $this->pluginTestCaseLoadedPlugins = [];
        $pluginCode = $this->guessPluginCodeFromTest();

        if ($pluginCode !== false) {
            $this->runPluginRefreshCommand($pluginCode, false);
        }

        /*
         * Disable mailer
         */
        Mail::pretend();
    }

    /**
     * Creates the application.
     *
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        $app['cache']->setDefaultDriver('array');
        $app->setLocale('en');

        /*
         * Modify the plugin path away from the test context
         */
        $app->setPluginsPath(realpath(base_path() . config('cms.pluginsPath')));

        return $app;
    }

    /**
     * Flush event listeners and collect garbage.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function tearDown()
    {
        $this->flushModelEventListeners();
        parent::tearDown();
        unset($this->app);

        /*
         * Restore environment
         */
        if (file_exists(base_path($this->envTestingFile())) && file_exists(base_path('.env.backup'))) {
            $this->restoreEnvironment();
        }
    }

    /**
     * Boot the testing helper traits.
     *
     * @return array
     */
    protected function setUpTraits()
    {
        $uses = parent::setUpTraits();

        if (isset($uses[RefreshOctoberDatabase::class])) {
            $this->refreshDatabase();
        }

        return $uses;
    }

    /**
     * Migrate database using october:up command.
     *
     * @return void
     */
    protected function runOctoberUpCommand()
    {
        Artisan::call('october:up');
    }

    /**
     * Since the test environment has loaded all the test plugins
     * natively, this method will ensure the desired plugin is
     * loaded in the system before proceeding to migrate it.
     *
     * @return void
     * @throws \Exception
     */
    protected function runPluginRefreshCommand($code, $throwException = true)
    {
        if (!preg_match('/^[\w+]*\.[\w+]*$/', $code)) {
            if (!$throwException) {
                return;
            }
            throw new Exception(sprintf('Invalid plugin code: "%s"', $code));
        }

        $manager = PluginManager::instance();
        $plugin = $manager->findByIdentifier($code);

        /*
         * First time seeing this plugin, load it up
         */
        if (!$plugin) {
            $namespace = '\\' . str_replace('.', '\\', strtolower($code));
            $path = array_get($manager->getPluginNamespaces(), $namespace);

            if (!$path) {
                if (!$throwException) {
                    return;
                }
                throw new Exception(sprintf('Unable to find plugin with code: "%s"', $code));
            }

            $plugin = $manager->loadPlugin($namespace, $path) ?? null;
        }

        /*
         * Spin over dependencies and refresh them too
         */
        $this->pluginTestCaseLoadedPlugins[$code] = $plugin;

        if (!empty($plugin->require)) {
            foreach ((array)$plugin->require as $dependency) {

                if (isset($this->pluginTestCaseLoadedPlugins[$dependency])) {
                    continue;
                }

                $this->runPluginRefreshCommand($dependency);
            }
        }

        /*
         * Execute the command
         */
        Artisan::call('plugin:refresh', ['name' => $code]);
    }

    /**
     * Returns a plugin object from its code, useful for registering events, etc.
     *
     * @param string $code
     *
     * @return \System\Classes\PluginBase
     * @throws \ReflectionException
     */
    protected function getPluginObject($code = null)
    {
        if ($code === null) {
            $code = $this->guessPluginCodeFromTest();
        }

        if (isset($this->pluginTestCaseLoadedPlugins[$code])) {
            return $this->pluginTestCaseLoadedPlugins[$code];
        }
    }

    /**
     * The models in October use a static property to store their events, these
     * will need to be targeted and reset ready for a new test cycle.
     * Pivot models are an exception since they are internally managed.
     *
     * @return void
     * @throws \ReflectionException
     */
    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if ($class == 'October\Rain\Database\Pivot') {
                continue;
            }

            $reflectClass = new ReflectionClass($class);
            if (
                !$reflectClass->isInstantiable() ||
                !$reflectClass->isSubclassOf('October\Rain\Database\Model') ||
                $reflectClass->isSubclassOf('October\Rain\Database\Pivot')
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    /**
     * Locates the plugin code based on the test file location.
     *
     * @return string|bool
     * @throws \ReflectionException
     */
    protected function guessPluginCodeFromTest()
    {
        $reflect = new ReflectionClass($this);
        $path = $reflect->getFilename();
        $basePath = $this->app->pluginsPath();

        $result = false;

        if (strpos($path, $basePath) === 0) {
            $result = ltrim(str_replace('\\', '/', substr($path, strlen($basePath))), '/');
            $result = implode('.', array_slice(explode('/', $result), 0, 2));
        }

        return $result;
    }

    /**
     * Backup the current environment file and switch to the testing environment.
     *
     * @return void
     */
    protected function switchEnvironment()
    {
        copy(base_path('.env'), base_path('.env.backup'));

        copy(base_path($this->envTestingFile()), base_path('.env'));
    }

    /**
     * Restore the backed-up environment file.
     *
     * @return void
     */
    protected function restoreEnvironment()
    {
        copy(base_path('.env.backup'), base_path('.env'));

        unlink(base_path('.env.backup'));
    }

    /**
     * Refresh the current environment variables.
     *
     * @return void
     */
    protected function refreshEnvironment()
    {
        (new Dotenv(base_path()))->overload();
    }

    /**
     * Get the name of the testing file for the environment.
     *
     * @return string
     */
    protected function envTestingFile()
    {
        return '.env.testing';
    }
}
