<?php

/**
 * PHP client for Apache Tika Application running in server mode.
 * License: FSF GPLv3 or later
 * Copyright (c) 2012, Vitaliy Filippov
 */
class TikaClient
{
    const VERSION = '2012-09-03';

    protected $tikaServer, $tikaPort;
    protected $mimeTypes, $mimeRegexp;
    protected $verbose, $logfile;

    /**
     * Create a client object.
     *
     * @param string $tikaServer IP and port server is listening to
     * @param string $mimeTypes Space-separated list of wildcards for supported MIME types
     * @param string $logfile Filename for error logging, or NULL to use STDERR
     * @param boolean $verbose If true, empty response will be treated as error,
     *   and also success responses will be logged
     */
    public function __construct($tikaServer, $mimeTypes, $logfile = NULL, $verbose = false)
    {
        $this->mimeTypes = $mimeTypes;
        $this->logfile = $logfile;
        $this->verbose = $verbose;
        if (strpos($tikaServer, ':') === false)
        {
            throw new Exception("Tika server address '$tikaServer' has incorrect format - correct format is 'server:port'");
        }
        list($this->tikaServer, $this->tikaPort) = explode(':', $tikaServer, 2);
        // Build regexp for MIME types
        $mimes = preg_split('/\s+/', trim($mimeTypes));
        foreach ($mimes as &$m)
        {
            $m = '^'.str_replace(array('\\*', '/'), array('.*', '\\/'), preg_quote($m)).'$';
        }
        $this->mimeRegexp = '/'.implode('|', $mimes).'/is';
    }

    /**
     * Get error message for socket
     * @param string $msg
     * @param resource $socket
     * @return string
     */
    protected function socketErr($msg, $socket)
    {
        $errno = socket_last_error($socket);
        $errstr = socket_strerror($errno);
        return $msg.($errno ? ": [$errno] $errstr" : '');
    }

    /**
     * Log message to file or to STDERR
     * @param string $msg
     */
    protected function log($msg)
    {
        $msg = date("[Y-m-d H:i:s] ").$msg."\n";
        file_put_contents($this->logfile ?: "php://stderr", $msg, FILE_APPEND);
    }

    /**
     * Extract plaintext from binary data $data using Tika
     *
     * @param string $data Input binary data
     * @param string &$err Error message will be placed here on error, or 'false' on success
     * @return string $text Extracted text
     */
    public function extractText($data, &$err)
    {
        $fsize = strlen($data);
        // Connect to Tika
        $s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($s, $this->tikaServer, $this->tikaPort);
        socket_set_nonblock($s);
        // Tika is somewhat delicate about network IO
        // So read and write using select(2) system call
        $text = '';
        $err = false;
        do
        {
            $read = $except = array($s);
            $write = $data === false ? NULL : array($s);
            socket_select($read, $write, $except, NULL);
            if ($read)
            {
                $part = socket_read($s, 65536);
                if ($part === false)
                {
                    // Read failure
                    $err = $this->socketErr("Error reading from Tika server", $s);
                    break;
                }
                elseif ($part === '')
                {
                    // EOF
                    break;
                }
                $text .= $part;
            }
            if ($write)
            {
                $l = socket_write($s, $data);
                if ($l !== false)
                {
                    $data = substr($data, $l);
                    if ($data === '' || $data === false)
                    {
                        // Shutdown output and forget about write events
                        $data = false;
                        socket_shutdown($s, 1);
                    }
                }
                else
                {
                    // Write failure
                    $err = $this->socketErr("Error writing to Tika server", $s);
                    break;
                }
            }
        } while (!$except); // except is also treated as EOF
        socket_close($s);
        if ($text === '' && $err === false && $this->verbose)
        {
            $err = 'Empty response from Tika server';
        }
        return $text;
    }

    /**
     * Extract plaintext content from a file using Tika, skipping unsupported mime types
     *
     * @param string $filename Filename to read data from
     * @param string $mimeType MIME type of this file
     * @param string $filenameForLog Equal to $filename by default
     * @return string $text Extracted text
     */
    public function extractTextFromFile($filename, $mimeType, $filenameForLog = NULL)
    {
        if ($filenameForLog === NULL)
        {
            $filenameForLog = $filename;
        }
        if (!preg_match($this->mimeRegexp, $mimeType))
        {
            // Tika can't handle this mime type, return nothing
            return '';
        }
        // Read file
        $data = file_get_contents($filename);
        $fsize = strlen($data);
        if (!$fsize)
        {
            // File is empty
            return '';
        }
        // Extract text
        $text = $this->extractText($data, $err);
        if ($err !== false)
        {
            $this->log("Error extracting text from $filenameForLog ($mimeType) of size $fsize: $err");
        }
        elseif ($this->verbose)
        {
            $this->log("Extracted ".strlen($text)." bytes from $filename ($mimeType) of size $fsize");
        }
        return $text;
    }
}
