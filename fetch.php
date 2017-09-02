<?php

require __DIR__ . '/vendor/autoload.php';


use Behat\Mink\Mink,
    Behat\Mink\Session,
    Behat\Mink\Driver\Selenium2Driver;

function getEbuildUrls() {
    $bash = <<<EOF
#!/bin/bash
source /etc/portage/make.conf
eval `
find /usr/portage/ \
	-name oracle-jre-bin*ebuild \
	-or -name oracle-jdk-bin*ebuild |
	xargs cat | grep -m2 -h -e ^JDK_URI -e ^JRE_URI
`
echo "{
    'downloads': '/home/\$USER/Downloads',
    'DISTDIR': '\$DISTDIR',
    'JRE_URI': '\$JRE_URI',
    'JDK_URI': '\$JDK_URI' }" | tr \' \"
EOF;
    exec($bash, $helper);
    return json_decode(join('', $helper));
}


/** @return \Stringy\Stringy; */
function s($e) { return new \Stringy\Stringy($e); }

function waitForFile($file, Session $session, $helper) {
    echo "fetching. $file\n";;
    $tempFile = sprintf("$helper->downloads/%s.crdownload", basename($file));
    $finishedFile = sprintf("$helper->downloads/%s", basename($file));
    $s300 = 300000000;
    while (is_file($tempFile) && ! is_file($finishedFile)) {
        $session->wait(500);
        usleep(500000);
        $s300 -= 500000;
        if ($s300 < 0 ) break;
    }
    if (is_file($finishedFile)) {
        rename($finishedFile, "$helper->DISTDIR/" . basename($file));
    }
}

function main(Mink $mink, $helper) {
    /** @var \Behat\Mink\Element\NodeElement $e */

    $s = $mink->getSession('selenium2');
    $s->resizeWindow(200, 200);
    $s->resizeWindow(1400, 1400);
    $file = [];

    foreach ([ $helper->JDK_URI, $helper->JRE_URI] as $tarball) {
        $s->visit($tarball);
        $page = $s->getPage();
        $form = $s->getPage()->find('css', '.lic_form');
        foreach ($page->findAll('css', 'form') as $e) {
            if (s($e->getAttribute('name'))->contains('agreement')) {
                $input = $e->find('css', 'input[value=on]');
                $input->selectOption('on');

                $link = $page->findLink('linux-x64.tar.gz');
                $link->click();

                $file[] = $link->getAttribute('href');
                break;
            }
        }
    }

    foreach ($file as $f) {
        waitForFile($f, $s, $helper);
    }
}


try {

    $seleniumDriverUrl = 'http://localhost:4444/wd/hub';
    $driver = new Selenium2Driver('chrome', null, $seleniumDriverUrl);
    $session = new Session($driver);
    $mink = new Mink(array( 'selenium2' => $session ));

    declare(ticks=1);
    $closeSession = function() use ($session) {
        $shouldStop = is_object($session) && $session->isStarted();
        if (! $shouldStop) exit(0);
        try { if ($shouldStop) $session->stop(); exit(0); }
        catch (Exception $e) { elog($e); exit(1);};
        exit(2);
    };
    pcntl_signal(SIGINT, $closeSession);
    pcntl_signal(SIGQUIT, $closeSession);
    register_shutdown_function($closeSession);

    main($mink, getEbuildUrls());
} catch (Exception $e) {
    echo 'FAIL: ' . $e->getMessage();
} finally {
    $closeSession();
}
