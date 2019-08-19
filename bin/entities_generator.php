<?php
use DI\ContainerBuilder;
use Symfony\Component\Console\Application;

function rrmdir($src) {
    $dir = opendir($src);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if ( is_dir($full) ) {
                rrmdir($full);
            }
            else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    rmdir($src);
}

require __DIR__.'/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);
$container = $containerBuilder->build();
$settings = $container->get('settings');

$classLoader = new \Doctrine\Common\ClassLoader('Entities', $settings['doctrine']['meta']['entity_path'][0]);
$classLoader->register();
$classLoader = new \Doctrine\Common\ClassLoader('Proxies', $settings['doctrine']['meta']['proxy_dir']);
$classLoader->register();

// config
$config = new \Doctrine\ORM\Configuration();
$config->setMetadataDriverImpl($config->newDefaultAnnotationDriver($settings['temporary_path'] . '/tmp_entity'));
$config->setMetadataCacheImpl(new \Doctrine\Common\Cache\ArrayCache);
$config->setProxyDir($settings['doctrine']['meta']['proxy_dir']);
$config->setProxyNamespace('Proxies');
$em = \Doctrine\ORM\EntityManager::create($settings['doctrine']['connection'], $config);
// custom datatypes (not mapped for reverse engineering)
$em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('set', 'string');
$em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
// fetch metadata
$driver = new \Doctrine\ORM\Mapping\Driver\DatabaseDriver(
    $em->getConnection()->getSchemaManager()
);
$driver->setNamespace('\\App\\Entity\\');
$em->getConfiguration()->setMetadataDriverImpl($driver);
$cmf = new \Doctrine\ORM\Tools\DisconnectedClassMetadataFactory($em);
$cmf->setEntityManager($em);
$classes = $driver->getAllClassNames();
$metadata = $cmf->getAllMetadata();
$generator = new Doctrine\ORM\Tools\EntityGenerator();
$generator->setUpdateEntityIfExists(true);
$generator->setGenerateStubMethods(true);
$generator->setGenerateAnnotations(true);
$generator->generate($metadata, $settings['temporary_path'] . '/tmp_entity');

// Move files in the good directory
$files = glob($settings['temporary_path'] . '/tmp_entity/App/Entity/*.{php}', GLOB_BRACE);
foreach($files as $fullpath) {
    $file = basename($fullpath);
    $src = file_get_contents($fullpath);
	if (preg_match('#^namespace\s+(.+?);$#sm', $src)) {
		continue;
	}
	$php = preg_replace("/<\?php/", "<?php\nnamespace App\\Entity;", $src);
	file_put_contents(__DIR__ . '/../src/Entity/' . $file, $php);
}

rrmdir($settings['temporary_path'] . '/tmp_entity');
echo '-------------------------------------------' . PHP_EOL;
echo ' Done! Generated entities to `src/Entity`  ' . PHP_EOL;
echo '-------------------------------------------' . PHP_EOL;
