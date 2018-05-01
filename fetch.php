<?php

require __DIR__ . '/vendor/autoload.php';


use Behat\Mink\Mink,
    Behat\Mink\Session,
    Behat\Mink\Driver\Selenium2Driver;

function getEbuildUrls() {
    $bash = <<<EOF
#!/bin/bash
source /etc/portage/make.conf
export JDK_URI=$(ebuild `equery which oracle-jdk-bin` nofetch |& grep -m1 -oe http://.*downloads.*$)
export JRE_URI=$(ebuild `equery which oracle-jre-bin` nofetch |& grep -m1 -oe http://.*downloads.*$)

export jre_link=$(ebuild  `equery which oracle-jre-bin` nofetch  |& grep -o j.*tar.gz)
export jdk_link=$(ebuild  `equery which oracle-jdk-bin` nofetch  |& grep -o j.*tar.gz)


echo "{
    'downloads': '/home/\$USER/Downloads',
    'DISTDIR': '\$DISTDIR',
    'jre_link': '\$jre_link',
    'jdk_link': '\$jdk_link',
    'JRE_URI': '\$JRE_URI',
    'JDK_URI': '\$JDK_URI' }" | tr \' \"
EOF;
    exec($bash, $json);
	$helper = json_decode(join('', $json));
	if (empty($helper->JRE_URI)) die("JRE_URI ?\n");
	if (empty($helper->JDK_URI)) die("JDK_URI ?\n");
	if (! is_dir($helper->downloads)) die("not a dire: $helper->downloads\n");
	if (! is_dir($helper->DISTDIR)) die("not a dire: $helper->DISTDIR\n");
    return $helper;
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
    $file = [];
    foreach ([ $helper->JDK_URI, $helper->JRE_URI] as $downloadPage) {
    	echo "going to $downloadPage\n";
        $s->visit($downloadPage);
        $page = $s->getPage();
        foreach ($page->findAll('css', 'form.lic_form') as $e) {
            if (s($e->getAttribute('name'))->contains('agreement')) {
	            foreach($e->findAll('css', 'input[value=on]') as $input) {
	            	if ($input->isVisible()) {
			            $input->selectOption('on');
		            }
	            }
            }
        }

	    foreach ([ $helper->jre_link, $helper->jdk_link] as $linkName) {
		    if ($link = $page->findLink($linkName)) {
			    $link->click();
			    $file[] = $link->getAttribute('href');
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
