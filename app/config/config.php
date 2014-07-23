<?php
//Note Mautic specific bundles so they can be applied as needed without having to specify them individually
$buildBundles = function($namespace, $bundle) use ($container) {
    if (strpos($namespace, 'Mautic') !== false) {
        $bundleBase = str_replace('Mautic', '', $bundle);
        $v = array(
            "base"      => str_replace('Bundle', '', $bundleBase),
            "bundle"    => $bundleBase,
            "namespace" => $namespace,
            "directory" => $container->getParameter('kernel.root_dir').'/bundles/'. $bundleBase
        );
        return $v;
    }
    return false;
};
$symfonyBundles = $container->getParameter('kernel.bundles');
$mauticBundles  = array_filter(
    array_map($buildBundles , $symfonyBundles, array_keys($symfonyBundles)),
    function ($v) { return (!empty($v)); }
);

$setBundles = array();
foreach ($mauticBundles as $bundle) {
    $setBundles[$bundle['base']] = $bundle;
}
//Core bundle should be first for assetic generation
$coreBundle = $setBundles['Core'];
unset($setBundles['Core']);
$setBundles = array_merge(array('Core' => $coreBundle), $setBundles);

$container->setParameter('mautic.bundles', $setBundles);
$loader->import('parameters.php');
$container->loadFromExtension('mautic_core');

$container->loadFromExtension('framework', array(
    'secret'               => '%mautic.secret%',
    'router'               => array(
        'resource'            => '%kernel.root_dir%/config/routing.php',
        'strict_requirements' => null,
    ),
    'form'                 => null,
    'csrf_protection'      => true,
    'validation'           => array(
        'enable_annotations' => true
    ),
    'templating'           => array(
        'engines' => array(
            'twig',
            'mautic'
        ),
        'form' => array(
            'resources' => array(
                'MauticCoreBundle:Form',
            ),
        ),
        /*
        'assets_base_urls' => array(
            'http' => array('/assets/'),
            'ssl'  => array('/assets/')
        )
        */
    ),
    'default_locale'       => '%mautic.locale%',
    'translator'           => array(
        'enabled'  => true,
        'fallback' => 'en'
    ),
    'trusted_hosts'        => '%mautic.trusted_hosts%',
    'trusted_proxies'      => '%mautic.trusted_proxies%',
    'session'              => array( //handler_id set to null will use default session handler from php.ini
        'handler_id' => null
    ),
    'fragments'            => null,
    'http_method_override' => true,

    /*'validation'           => array(
        'static_method' => array('loadValidatorMetadata')
    )*/
));

//Twig Configuration
$container->loadFromExtension('twig', array(
    'debug'            => '%kernel.debug%',
    'strict_variables' => '%kernel.debug%',
));

//Doctrine Configuration
$container->loadFromExtension('doctrine', array(
    'dbal' => array(
        'driver'   => '%mautic.db_driver%',
        'host'     => '%mautic.db_host%',
        'port'     => '%mautic.db_port%',
        'dbname'   => '%mautic.db_name%',
        'user'     => '%mautic.db_user%',
        'password' => '%mautic.db_password%',
        'charset'  => 'UTF8',
        //if using pdo_sqlite as your database driver, add the path in parameters.php
        //e.g. 'database_path' => '%kernel.root_dir%/data/data.db3'
        //'path'    => '%db_path%'
        'types'    => array(
            'datetime' => 'Mautic\CoreBundle\Doctrine\Type\UTCDateTimeType'
        )
    ),

    'orm'  => array(
        'auto_generate_proxy_classes' => '%kernel.debug%',
        'auto_mapping'                => true
    )
));



//Swiftmailer Configuration
$container->loadFromExtension('swiftmailer', array(
    'transport' => '%mautic.mailer_transport%',
    'host'      => '%mautic.mailer_host%',
    'username'  => '%mautic.mailer_user%',
    'password'  => '%mautic.mailer_password%',
    'spool'     => array(
        'type' => 'memory'
    )
));

//Also Assetic does not allow variables when loading resources in templates because it renders the media at compilation time and thus
//the appropriate variables are not populated.  In order to not have to manually add each bundles' media files to
//MauticBaseBundle's base.html.php file, we are doing the following which works because of Symfony's caching.

//For production, you must dump the assets via php app/console assetic:dump --env=prod

$css    = array();
$js     = array();

foreach ($setBundles as $bundle => $details) {
   $bundleDir = $details['directory'];
    //define the function for use with CSS and JS files
    $getFiles = function ($type) use ($bundleDir, &$css, &$js) {
        $typeDir = "$bundleDir/Resources/public/$type/";

        if (file_exists($typeDir)) {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()->in($typeDir)->name('*.'.$type)->depth('== 0');
            foreach ($finder as $file) {
                //add the file to be loaded
                ${$type}[] = $file->getRealpath();
            }
        }
    };

    $getFiles('css');
    $getFiles('js');
}

//Assetic Configuration
$container->loadFromExtension('assetic', array(
    'debug'          => '%kernel.debug%',
    'use_controller' => false,
    'read_from'      => '%kernel.root_dir%/../',
    'write_to'       => '%kernel.root_dir%/../',
    'filters'        => array(
        'cssrewrite' => array(
            'apply_to' => '\.css$',
        )
    ),
    'assets'         => array(
        'mautic_stylesheets' => array(
            'inputs'  => $css,
            'options' => array(
                'combine' => true,
                //'output'  => 'css/mautic.css'
            )
        ),
        'mautic_javascripts' => array(
            'inputs'  => $js,
            'options' => array(
                'combine' => true,
                //'output'  => 'js/mautic.js'
            )
        )
    )
));

//KnpMenu Configuration
$container->loadFromExtension('knp_menu', array(
    'twig' => false,
    'templating' => true,
    'default_renderer' => 'mautic'
));

if ($container->getParameter('mautic.api_enabled')) {
    //FOS Rest
    $container->loadFromExtension('fos_rest', array(
        'routing_loader' => array(
            'default_format' => 'json',
            'include_format' => false
        ),
        'view'           => array(
            'formats' => array(
                'json' => true,
                'xml'  => true,
                'html' => false
            ),
            'templating_formats' => array(
                'html' => false
            )
        ),
        'disable_csrf_role' => 'ROLE_API'
    ));

    //JMS Serializer
    $container->loadFromExtension('jms_serializer', array(
        'property_naming' => array(
            'separator'  => '',
            'lower_case' => false
        )
    ));

    $container->setParameter(
        'jms_serializer.camel_case_naming_strategy.class',
        'JMS\Serializer\Naming\IdenticalPropertyNamingStrategy'
    );
}