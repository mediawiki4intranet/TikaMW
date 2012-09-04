<?php
/**
 * TikaMW - Apache Tika for MediaWiki.
 *
 * Copyright 2012+ Vitaliy Filippov <vitalif@mail.ru>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @file
 * @ingroup Extensions
 * @author Vitaliy Filippov <vitalif@mail.ru>
 */

/**
 * This is an extension to use Apache Tika (http://tika.apache.org/)
 * to extract plain-text data from binary documents uploaded into MediaWiki
 * and search them based on their content using normal MediaWiki search.
 *
 * The most simpler way to try it is to use MediaWiki4Intranet MediaWiki bundle:
 * it already includes Tika and this extension.
 *
 * MANUAL INSTALLATION:
 * 1) Install Java Virtual Machine (JVM) on the server
 * 2) Download a fixed copy of Apache Tika application, better from:
 *    http://code.google.com/p/mediawiki4intranet/downloads/detail?name=tika-app-1.2-fix-TIKA709-TIKA964.jar
 *    or
 *    http://wiki.4intra.net/public/tika-app-1.2-fix-TIKA709-TIKA964.jar
 * 3) Add Tika server to autostart / init-scripts on your server, like this:
 *    java -jar tika-app-1.2-fix-TIKA709-TIKA964.jar -p 127.0.0.1:8072 -t -eutf-8
 * 4) Put following lines into your LocalSettings.php:
 *
 *    require_once "$IP/extensions/TikaMW/TikaMW.php";
 *
 *    // Server address, should be same as one on the tika-app.jar command line
 *    $egTikaServer = '127.0.0.1:8072';
 *
 *    // If your Tika is newer and supports more formats than 1.2,
 *    // you can override supported mime types with $egTikaMimeTypes (see below).
 *
 * 5) If you install it on a MediaWiki that already has uploaded files, you should
 *    rebuild the fulltext index - use maintenance/rebuildtextindex.php on stock
 *    mediawiki, extensions/SphinxSearchEngine/rebuild-sphinx.php if you use
 *    SphinxSearchEngine or maybe other script for some other engine.
 */

$wgHooks['SearchUpdate'][] = 'efTikaSearchUpdate';
$wgAutoloadClasses['TikaClient'] = __DIR__.'/TikaClient.php';

$egTikaServer = '127.0.0.1:8072';
$egTikaMimeTypes = '
    text/*
    application/*+xml
    application/xml
    application/vnd.oasis.opendocument.*
    application/vnd.openxmlformats
    application/vnd.ms-*
    application/msaccess
    application/msword
    application/pdf
    application/rtf';

function efTikaSearchUpdate($id, $namespace, $title, &$text)
{
    if ($namespace == NS_FILE)
    {
        global $egTikaServer, $egTikaMimeTypes, $egTikaLogFile, $haclgEnableTitleCheck;
        $cli = new TikaClient($egTikaServer, $egTikaMimeTypes, NULL, 'wfDebug', true);
        if (defined('HACL_HALOACL_VERSION'))
        {
            // Compatibility with IntraACL extension -- skip right checks here
            $etc = $haclgEnableTitleCheck;
            $haclgEnableTitleCheck = false;
        }
        $file = wfFindFile($title);
        if ($file && file_exists($file->getPath()))
        {
            $filetext = $cli->extractTextFromFile($file->getPath(), $file->getMimeType());
            if (defined('HACL_HALOACL_VERSION'))
            {
                $haclgEnableTitleCheck = $etc;
            }
            if ($filetext)
            {
                $text .= ' '.$filetext;
            }
        }
        else
        {
            wfDebug("TikaMW search update: file $title does not exist\n");
        }
    }
    return true;
}
