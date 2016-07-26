<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class G2APayAutoload
{
    protected static $instance;

    /**
     * @return G2APayAutoload
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register autoloader.
     */
    public static function register()
    {
        spl_autoload_register([G2APayAutoload::instance(), 'load']);
    }

    /**
     * @param $name
     */
    public function load($name)
    {
        if (strpos($name, 'G2APay') !== 0) {
            return;
        }

        $full_path = $this->getFullPath($name);
        if (file_exists($full_path)) {
            require_once $full_path;
        }
    }

    /**
     * @param $name
     * @return string
     */
    protected function getFullPath($name)
    {
        return $this->getBasePath() . DIRECTORY_SEPARATOR . $name . '.php';
    }

    /**
     * @return string
     */
    protected function getBasePath()
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'classes';
    }

    /**
     * Loads all module classes.
     */
    protected function loadAllClasses()
    {
        $classes = array(
            'Client',
            'Exception',
            'Helper',
            'Ipn',
            'Rest',
            'PaymentHistoryTable',
        );

        foreach ($classes as $class) {
            $this->load('G2APay' . $class);
        }
    }
}
