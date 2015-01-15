<?php
/**
 * ownCloud - News
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 */

namespace OCA\News\AppInfo;

require_once __DIR__ . '/autoload.php';

use HTMLPurifier;
use HTMLPurifier_Config;

use \PicoFeed\Config\Config as PicoFeedConfig;
use \PicoFeed\Reader\Reader as PicoFeedReader;

use \OC\Files\View;
use \OCP\AppFramework\App;

use \OCA\News\Config\AppConfig;
use \OCA\News\Config\Config;

use \OCA\News\Service\FeedService;

use \OCA\News\Db\MapperFactory;

use \OCA\News\Fetcher\Fetcher;
use \OCA\News\Fetcher\FeedFetcher;

use \OCA\News\ArticleEnhancer\Enhancer;
use \OCA\News\ArticleEnhancer\XPathArticleEnhancer;
use \OCA\News\ArticleEnhancer\RegexArticleEnhancer;

use \OCA\News\Explore\RecommendedSites;


class Application extends App {

    public function __construct(array $urlParams=[]) {
        parent::__construct('news', $urlParams);

        $container = $this->getContainer();

        /**
         * Mappers
         */
        $container->registerService('OCA\News\Db\ItemMapper', function($c) {
            return $c->query('OCA\News\Db\MapperFactory')->getItemMapper(
                $c->query('OCP\IDb')
            );
        });


        /**
         * App config parser
         */
        $container->registerService('OCA\News\Config\AppConfig', function($c) {
            $config = new AppConfig(
                $c->query('OCP\INavigationManager'),
                $c->query('OCP\IURLGenerator')
            );

            $config->loadConfig(__DIR__ . '/info.xml');

            return $config;
        });

        /**
         * Core
         */
        $container->registerService('LoggerParameters', function($c) {
            return ['app' => $c->query('AppName')];
        });

        $container->registerService('DatabaseType', function($c) {
            return $c->query('OCP\\IConfig')->getSystemValue('dbtype');
        });


        /**
         * Utility
         */
        $container->registerService('ConfigView', function() {
            $view = new View('/news/config');
            if (!$view->file_exists('')) {
                $view->mkdir('');
            }

            return $view;
        });

        $container->registerService('ConfigPath', function() {
            return 'config.ini';
        });

        $container->registerService('OCA\News\Config\Config', function($c) {
            $config = new Config(
                $c->query('ConfigView'),
                $c->query('OCP\ILogger'),
                $c->query('LoggerParameters')
            );
            $config->read($c->query('ConfigPath'), true);
            return $config;
        });

        $container->registerService('HTMLPurifier', function($c) {
            $directory = $c->query('OCP\IConfig')
                ->getSystemValue('datadirectory') . '/news/cache/purifier';

            if(!is_dir($directory)) {
                mkdir($directory, 0770, true);
            }

            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.ForbiddenAttributes', 'class');
            $config->set('Cache.SerializerPath', $directory);
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp',
                '%^(?:https?:)?//(' .
                'www.youtube(?:-nocookie)?.com/embed/|' .
                'player.vimeo.com/video/)%'); //allow YouTube and Vimeo
            return new HTMLPurifier($config);
        });

        $container->registerService('OCA\News\ArticleEnhancer\Enhancer', function($c) {
            $enhancer = new Enhancer();

            // register simple enhancers from config json file
            $xpathEnhancerConfig = file_get_contents(
                __DIR__ . '/../articleenhancer/xpathenhancers.json'
            );

            $xpathEnhancerConfig = json_decode($xpathEnhancerConfig, true);
            foreach($xpathEnhancerConfig as $feed => $config) {
                $articleEnhancer = new XPathArticleEnhancer(
                    $c->query('OCA\News\Utility\PicoFeedClientFactory'),
                    $config
                );
                $enhancer->registerEnhancer($feed, $articleEnhancer);
            }

            $regexEnhancerConfig = file_get_contents(
                __DIR__ . '/../articleenhancer/regexenhancers.json'
            );
            $regexEnhancerConfig = json_decode($regexEnhancerConfig, true);
            foreach($regexEnhancerConfig as $feed => $config) {
                foreach ($config as $matchArticleUrl => $regex) {
                    $articleEnhancer =
                        new RegexArticleEnhancer($matchArticleUrl, $regex);
                    $enhancer->registerEnhancer($feed, $articleEnhancer);
                }
            }

            $enhancer->registerGlobalEnhancer(
                $c->query('OCA\News\ArticleEnhancer\GlobalArticleEnhancer')
            );

            return $enhancer;
        });

        /**
         * Fetchers
         */
        $container->registerService('PicoFeed\Config\Config', function($c) {
            // FIXME: move this into a separate class for testing?
            $config = $c->query('OCA\News\Config\Config');
            $appConfig = $c->query('OCA\News\Config\AppConfig');
            $proxy =  $c->query('OCA\News\Utility\ProxyConfigParser');

            $userAgent = 'ownCloud News/' . $appConfig->getConfig('version') .
                         ' (+https://owncloud.org/; 1 subscriber;)';

            $pico = new PicoFeedConfig();
            $pico->setClientUserAgent($userAgent)
                ->setClientTimeout($config->getFeedFetcherTimeout())
                ->setMaxRedirections($config->getMaxRedirects())
                ->setMaxBodySize($config->getMaxSize())
                // enable again if we can distinguish between security and
                // content filtering
                //->setContentFiltering(false)
                ->setParserHashAlgo('md5');

            // proxy settings
            $proxySettings = $proxy->parse();
            $host = $proxySettings['host'];
            $port = $proxySettings['port'];
            $user = $proxySettings['user'];
            $password = $proxySettings['password'];

            if ($host) {
                $pico->setProxyHostname($host);

                if ($port) {
                    $pico->setProxyPort($port);
                }
            }

            if ($user) {
                $pico->setProxyUsername($user)
                    ->setProxyPassword($password);
            }

            return $pico;
        });

        $container->registerService('OCA\News\Fetcher\Fetcher', function($c) {
            $fetcher = new Fetcher();

            // register fetchers in order
            // the most generic fetcher should be the last one
            $fetcher->registerFetcher($c->query('OCA\News\Fetcher\YoutubeFetcher'));
            $fetcher->registerFetcher($c->query('OCA\News\Fetcher\FeedFetcher'));

            return $fetcher;
        });

        $container->registerService('OCA\News\Fetcher\FeedFetcher', function($c) {
            return new FeedFetcher(
                $c->query('PicoFeed\Reader\Reader'),
                $c->query('OCA\News\Utility\PicoFeedFaviconFactory'),
                $c->query('OCP\IL10N'),
                $c->query('OCP\AppFramework\Utility\ITimeFactory')
            );
        });

        $container->registerService('OCA\News\Explore\RecommendedSites', function($c) {
            return new RecommendedSites(__DIR__ . '/../explore');
        });
    }

    public function getAppConfig() {
        return $this->getContainer()->query('OCA\News\Config\AppConfig');
    }


}

