<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2015 Spring Signage Ltd
 *
 * This file (xmds.php) is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

DEFINE('XIBO', true);
define('PROJECT_ROOT', realpath(__DIR__ . '/..'));

error_reporting(E_ALL);
ini_set('display_errors', 1);

require PROJECT_ROOT . '/vendor/autoload.php';

if (!file_exists(PROJECT_ROOT . '/web/settings.php')) {
    die('Not configured');
}

// We create a Slim Object ONLY for logging
// Create a logger
$logger = new \Xibo\Helper\AccessibleMonologWriter(array(
    'name' => 'XMDS',
    'handlers' => array(
        new \Xibo\Helper\DatabaseLogHandler()
    ),
    'processors' => [
        new \Monolog\Processor\UidProcessor(7)
    ]
));

// Slim Application
$app = new \Slim\Slim(array(
    'debug' => false,
    'log.writer' => $logger
));
$app->setName('api');

// Load the config
$app->configService = \Xibo\Service\ConfigService::Load(PROJECT_ROOT . '/web/settings.php');

// Set storage
\Xibo\Middleware\Storage::setStorage($app->container);

// Set state
\Xibo\Middleware\State::setState($app);

// Always have a version defined
$version = $app->sanitizerService->getInt('v', 3, $_REQUEST);

// Version Request?
if (isset($_GET['what']))
    die($app->configService->Version('XmdsVersion'));

// Is the WSDL being requested.
if (isset($_GET['wsdl']) || isset($_GET['WSDL'])) {
    $wsdl = new \Xibo\Xmds\Wsdl(PROJECT_ROOT . '/lib/Xmds/service_v' . $version . '.wsdl', $version);
    $wsdl->output();
    exit;
}

// We need a View for rendering GetResource Templates
// Twig templates
$twig = new \Slim\Views\Twig();
$twig->parserOptions = array(
    'debug' => true,
    'cache' => PROJECT_ROOT . '/cache'
);
$twig->parserExtensions = array(
    new \Slim\Views\TwigExtension(),
    new \Xibo\Twig\TransExtension(),
    new \Xibo\Twig\UrlDecodeTwigExtension()
);

// Configure a user
$app->user = $app->userFactory->getById(1);

// Configure the template folder
$twig->twigTemplateDirs = array_merge($app->moduleFactory->getViewPaths(), [PROJECT_ROOT . '/views']);
$app->view($twig);

// Check to see if we have a file attribute set (for HTTP file downloads)
if (isset($_GET['file'])) {
    // Check send file mode is enabled
    $sendFileMode = $app->configService->GetSetting('SENDFILE_MODE');

    if ($sendFileMode == 'Off') {
        $app->logService->notice('HTTP GetFile request received but SendFile Mode is Off. Issuing 404', 'services');
        header('HTTP/1.0 404 Not Found');
        exit;
    }

    // Check nonce, output appropriate headers, log bandwidth and stop.
    try {
        /** @var \Xibo\Entity\RequiredFile $file */
        $file = $app->requiredFileFactory->getByNonce($_REQUEST['file']);
        $file->bytesRequested = $file->bytesRequested + $file->size;
        $file->isValid();

        // Issue magic packet
        // Send via Apache X-Sendfile header?
        if ($sendFileMode == 'Apache') {
            $app->logService->notice('HTTP GetFile request redirecting to ' . $app->configService->GetSetting('LIBRARY_LOCATION') . $file->storedAs, 'services');
            header('X-Sendfile: ' . $app->configService->GetSetting('LIBRARY_LOCATION') . $file->storedAs);
        }
        // Send via Nginx X-Accel-Redirect?
        else if ($sendFileMode == 'Nginx') {
            header('X-Accel-Redirect: /download/' . $file->storedAs);
        }
        else {
            header('HTTP/1.0 404 Not Found');
        }

        // Log bandwidth
        $app->bandwidthFactory->createAndSave(4, $file->displayId, $file->size);
    }
    catch (\Exception $e) {
        if ($e instanceof \Xibo\Exception\NotFoundException || $e instanceof \Xibo\Exception\FormExpiredException) {
            $app->logService->notice('HTTP GetFile request received but unable to find XMDS Nonce. Issuing 404', 'services');
            // 404
            header('HTTP/1.0 404 Not Found');
        }
        else
            throw $e;
    }

    exit;
}


try {
    $wsdl = PROJECT_ROOT . '/lib/Xmds/service_v' . $version . '.wsdl';

    if (!file_exists($wsdl))
        throw new InvalidArgumentException(__('Your client is not the correct version to communicate with this CMS.'));

    // Create a log processor
    $logProcessor = new \Xibo\Xmds\LogProcessor();
    $app->logWriter->addProcessor($logProcessor);

    // Create a SoapServer
    //$soap = new SoapServer($wsdl);
    $soap = new SoapServer($wsdl, array('cache_wsdl' => WSDL_CACHE_NONE));
    $soap->setClass('\Xibo\Xmds\Soap' . $version,
        $logProcessor,
        $app->pool,
        $app->store,
        $app->logService,
        $app->dateService,
        $app->sanitizerService,
        $app->configService,
        $app->requiredFileFactory,
        $app->moduleFactory,
        $app->layoutFactory,
        $app->dataSetFactory,
        $app->displayFactory,
        $app->userFactory,
        $app->bandwidthFactory,
        $app->mediaFactory
    );
    $soap->handle();

    $app->logService->info('PDO stats: %s.', json_encode($app->store->stats()));

    if ($app->store->getConnection()->inTransaction())
        $app->store->getConnection()->commit();
}
catch (Exception $e) {
    $app->logService->error($e->getMessage());

    if ($app->store->getConnection()->inTransaction())
        $app->store->getConnection()->rollBack();

    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain');
    die (__('There has been an unknown error with XMDS, it has been logged. Please contact your administrator.'));
}