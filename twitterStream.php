#!/usr/bin/php
<?php
//----------------------------------------------------------------------------//
// twitterStream
//----------------------------------------------------------------------------//
/*
 * (c) Copyright 2013 Flame Herbohn
 * 
 * a command line PHP script that outputs tweets to standard output
 * 
 */

//----------------------------------------------------------------------------//
// Configuration
//----------------------------------------------------------------------------//

// Connection
define("CONNECT_BASE_URL",         "stream.twitter.com");
define("CONNECT_ENDPOINT",         "/1.1/statuses/sample.json");
define("CONNECT_PORT",             443);
define("CONNECT_TIMEOUT",          30);
define("CONNECT_IDLE_TIMEOUT",     90);

// Script options
define("SCRIPT_NAME",              $_SERVER['argv'][0]);
define("SCRIPT_OPTIONS",           "u:p:n:t:");

// help message
define("SCRIPT_HELP", 
"Usage: ".SCRIPT_NAME." -u USER_NAME -p PASSWORD [OPTION]...
  -n=#    exit after # tweets have been output. 
  -t=#    exit after # seconds.

Connects to the twitter streaming API and outputs tweets from the 
statuses/sample endpoint.

Important note : Twitter will disconnect clients that do not consume the stream
fast enough. The connection may be disconnected if you do anything that causes 
the collection of tweets to slow down, such as piping the output in a way that 
causes the output buffer to fill up and start blocking, for example:
".SCRIPT_NAME." | more
");

// error messages
define("ERROR_USERNAME_MISSING",   "missing username");
define("ERROR_PASSWORD_MISSING",   "missing password");
define("ERROR_HTTP_ERROR",         "HTTP error");
define("ERROR_SOCKET_ERROR",       "connection error");
define("ERROR_SOCKET_TIMEOUT",     "connection timed out");
define("ERROR_SOCKET_EOF",         "remote server disconnected (EOF)");
define("ERROR_CONNECT_RETRY_TOP",  "maximum backoff time reached");
define("ERROR_CONNECT_RETRY"    ,  "connection error, waiting for reconnect");
define("ERROR_DNS_HOST_NOT_FOUND", "host not found");
define("ERROR_OUT_OF_TIME",        "maximum execution time reached");
define("ERROR_OUT_OF_TWEETS",      "maximum tweet count reached");

// verbosity (for debugging)
define("SCRIPT_VERBOSITY",         0);

// Define non-configurable constants 
// (constant definitions can be found at the end of the script)
define_constants();

//----------------------------------------------------------------------------//
// Exception & Error Handlers
//----------------------------------------------------------------------------//

function exception_handler($objException)
{
    switch (get_class($objException))
    {
        case "OptionHelpException":
            error_log(SCRIPT_HELP);
            die(0);
            break;
        case "OptionMissingException":
            error_log(SCRIPT_NAME.": ".$objException->getMessage()."\n".
                      "Try '".SCRIPT_NAME." --help' for more information.");
            break;
        case "NetworkRetryException":
            error_log(SCRIPT_NAME." reached the maximum retry limit while".
                      " trying to reconnect after a connection error : (". 
                      $objException->getCode().") ".
                      $objException->getMessage());
            break;
        default:
            error_log(SCRIPT_NAME." encountered an unexpected error : (". 
                      $objException->getCode().") ".
                      $objException->getMessage());
            break;
    }
    die(1);
}

set_exception_handler("exception_handler");

//----------------------------------------------------------------------------//
// Script
//----------------------------------------------------------------------------//

if (!defined("UNIT_TEST") || true !== UNIT_TEST)
{
    main();
}

function main()
{
    //--------------------------------------------------------------------//
    // get options
    //--------------------------------------------------------------------//

    $objScript = new getOptions(SCRIPT_OPTIONS);

    // username (required)
    $strUserName = $objScript->getOption("u", ERROR_USERNAME_MISSING);

    // password (required)
    $strPassword = $objScript->getOption("p", ERROR_PASSWORD_MISSING);

    // maxTweets (optional)
    $intMaxTweets = (int) $objScript->getOption("n");

    // maxTime (optional)
    $intMaxTime = (int) $objScript->getOption("t");
    if ($intMaxTime > 0)
    {
        // set a backup time limit on the script.
        // Note : the script should always time out gracefully, but if it 
        //        doesn't this will ensure that it times out. 
        //        set_time_limit() is not always accurate, so we only use it as 
        //        a backup.
        set_time_limit($intMaxTime + 5);
        
        $intMaxTime = $intMaxTime + time();
    }

    //--------------------------------------------------------------------//
    // connect to Twitter
    //--------------------------------------------------------------------//

    $objTwitter = new twitterStream($strUserName, $strPassword, 
                                    CONNECT_BASE_URL, CONNECT_PORT);
                                        
    $objTwitter->setEndTime($intMaxTime);

    $objTwitter->setMaxTweets($intMaxTweets);

    $objTwitter->setVerbosity(SCRIPT_VERBOSITY);

    $objTwitter->connect(CONNECT_ENDPOINT, CONNECT_TIMEOUT);

    //--------------------------------------------------------------------//
    // consume twitter stream
    //--------------------------------------------------------------------//
     
    while ($objTweet = $objTwitter->getTweet())
    {
        // output tweet to stdout
        if (false !== $objTweet)
        {
            echo $objTweet["user"]["screen_name"], " : ", 
                 urldecode($objTweet["text"]), "\n";
        }
    }
}

//============================================================================//
// Classes
//============================================================================//

//----------------------------------------------------------------------------//
// script options class
//----------------------------------------------------------------------------//
/*
 * A class for working with options passed on the command line
 */
class getOptions
{
    //------------------------------------------------------------------------//
    // __construct
    //------------------------------------------------------------------------//
    /**
     * __construct()
     *
     * Initialize a getOptions object 
     *
     * Automatically checks if the --help option was set on the command line. If
     * the --help option was set an exception of type OptionHelpException will 
     * be thrown. 
     *
     * @param    string     $strOptions       options in the format used by the
     *                                        php getopt() method for short
     *                                        (single character) options.
     *
     * @return   void
     */
    public function __construct($strOptions)
    {
        $this->arrOptions = getopt($strOptions, Array("help"));
        
        // check for a help request
        if (isset($this->arrOptions["help"]))
        {
            throw new OptionHelpException();
        }
    }
    
    //------------------------------------------------------------------------//
    // getOption
    //------------------------------------------------------------------------//
    /**
     * getOption()
     *
     * Get the value of a single option set on the command line.
     *
     * Returns the value set on the command line for the option $strOption. If 
     * an error string $strError is passed to the method, and the option was 
     * not set on the command line, an exception of type OptionMissingException 
     * will be thrown. 
     *
     * @param    string     $strOption        single character used to define
     *                                        the option.
     * @param    string     $strError         optional error message to display
     *                                        if the option was not set when
     *                                        the script was executed.
     *                                        default = null
     *
     * @return   string    The value set on the command line for the option
     *           bool      true   the option was set without a value
     *                     false  the option was not set      
     */
    public function getOption($strOption, $strError=null)
    {
        if (isset($this->arrOptions[$strOption]))
        {
            if ($this->arrOptions[$strOption] === false)
            {
                // an option without a value = false in the options array
                return true;
            }
            return $this->arrOptions[$strOption];
        }
        if (null != $strError)
        {
            throw new OptionMissingException($strError);
        }
        return false;
    } 
} 


//----------------------------------------------------------------------------//
// Twitter stream class
//----------------------------------------------------------------------------//
/*
 * A class for working with the Twitter streaming API
 * 
 * Example usage:
 * 
 * $objTwitter = new twitterStream('username', 'password'); 
 * $objTwitter->connect('/1.1/statuses/sample.json');
 * while ($objTweet = $objTwitter->getTweet())
 * {
 *     // do something with the tweet...
 *     // Note : Do not process tweets here, they should be queued for  
 *     //        asynchronous processing so as not to slow down the collection 
 *     //        of tweets from the stream.
 * }
 * 
 */
class twitterStream
{
    //------------------------------------------------------------------------//
    // __construct
    //------------------------------------------------------------------------//
    /**
     * __construct()
     *
     * Initialize a twitterStream object
     *
     * Initializes a twitterStream object but does not make a connection.
     *
     * @param    string     $strUserName      username to connect to Twitter
     * @param    string     $strPassword      password to connect to Twitter
     * @param    string     $strHost          optional host name to connect to
     *                                        default = "stream.twitter.com"
     * @param    integer    $intPort          optional port to connect to
     *                                        default = 443
     *
     * @return   void
     */
    public function __construct($strUserName, $strPassword, 
                                $strHost="stream.twitter.com", $intPort=443)
    {
        $this->strAuth         = base64_encode("{$strUserName}:{$strPassword}");
        $this->strHost         = $strHost;
        $this->intPort         = $intPort;
        $this->strProtocol     = (443 == $intPort) ? "ssl://" : "tcp://";
        $this->intStopAt       = 0;
        $this->intTweets       = 0;
        $this->intMaxTweets    = 0;
        $this->intVerbosity    = 0;
        $this->strBuffer       = "";
        $this->intBackoffType  = null;
        $this->intLastBackoff  = 0;
        $this->intBackoffCount = 0;
        $this->socTwitter      = null;
    }

    //------------------------------------------------------------------------//
    // connect
    //------------------------------------------------------------------------//
    /**
     * connect()
     *
     * Connect to the Twitter streaming API
     *
     * The connect() method will only make a single attemt to connect, if the 
     * connection fails an exception will be thrown.
     * 
     * If a DNS error occurs an exception of type NetworkDnsException will be 
     * thrown.
     * 
     * If a TCP/IP connection error occurs an exception of type 
     * NetworkTcpException will be thrown.
     * 
     * If a HTTP connection error occurs an exception of type
     * NetworkHttpException will be thrown.
     *
     * @param    string     $strEndPoint      the streaming API endpoint to 
     *                                        connect to (not including the
     *                                        host name)
     * @param    integer    $intTimeout       optional the connection timeout
     *                                        in seconds
     *                                        default = 30
     *
     * @return   void
     */
    public function connect($strEndPoint, $intTimeout=30)
    {
        // cache the endpoint for use by the reconnect method
        $this->strEndPoint = $strEndPoint;
        
        // disconnect any existing connection
        $this->disconnect();
        
        // make sure the timeout is less than the remaining execution time
        $intTimeout = $this->getTimeout($intTimeout);
        
        // connect
        @$this->socTwitter = fsockopen($this->getHostAddress(), $this->intPort, 
                                       $intError, $strError, $intTimeout);
        if(!$this->socTwitter)
        {
            // throw a TCP/IP exception
            throw new NetworkTcpException($strError, $intError);
        }
        
        // set stream to non-blocking
        stream_set_blocking($this->socTwitter, 0);
        
        // send endpoint request
        $strRequest  = "GET {$strEndPoint} HTTP/1.1\r\n";
        $strRequest .= "Host: {$this->strHost}\r\n";
        $strRequest .= "Authorization: Basic {$this->strAuth}\r\n";
        $strRequest .= "User-Agent: ".SCRIPT_NAME."\r\n";
        $strRequest .= "\r\n";
        fwrite($this->socTwitter, $strRequest);
        
        // check http status code
        $strLine = $this->readLine();
        $arrLine = preg_split('/\s+/', trim($strLine), 3);
        if (!isset($arrLine[1]) || ERRNO_HTTP_OK != $arrLine[1])
        {
            // throw a HTTP exception
            $intError = isset($arrLine[1]) ? $arrLine[1] : ERRNO_HTTP_ERROR;
            $strError = isset($arrLine[2]) ? $arrLine[2] : ERROR_HTTP_ERROR;
            throw new NetworkHttpException($strError, $intError);
        }
        
        // clear any backoff previously set
        $this->intBackoffType  = null;
        $this->intLastBackoff  = 0;
        $this->intBackoffCount = 0;
        
        // strip the rest of the headers
        while (trim($this->readLine()))
        {
        }
    }
    
    //------------------------------------------------------------------------//
    // disconnect
    //------------------------------------------------------------------------//
    /**
     * disconnect()
     *
     * Disconnect from the Twitter streaming API
     *
     * The disconnect() method will NOT return an error or throw an exception,
     * therefore it may be called without first checking if a connection
     * actually exists.
     *
     * @return   void
     */
    public function disconnect()
    {
        @fclose($this->socTwitter);
        $this->socTwitter = null;
    }
    
    //------------------------------------------------------------------------//
    // getTweet
    //------------------------------------------------------------------------//
    /**
     * getTweet()
     *
     * get the next tweet from the Twitter stream
     *
     * Returns only actual tweets, all other data from the stream is ignored.
     * 
     * The getTweet() method will block and wait for the next tweet to become
     * available.
     * 
     * If the setEndTime() and/or setMaxTweets() methods have been used the 
     * getTweet() method will disconnect from the twitter stream and return 
     * false when the specified end time or maximum number of tweets has been 
     * reached.
     * 
     * If the connection is dropped or is idle for too long the getTweet() 
     * method will automatically try to reconnect. If the connection can not
     * be reestablished (after the maximum number or reconnection attempts) an
     * excepption will be trhown of type NetworkDnsException, 
     * NetworkTcpException or NetworkHttpException.
     *
     * @return   object    a Tweets object (see
     *                     https://dev.twitter.com/docs/platform-objects/tweets
     *                     for details)
     *           bool      false   the end time or maximum number of tweets has 
     *                             been reached.  
     */
    public function getTweet()
    {
        while (true)
        {
            try
            {
                $this->checkIfWeNeedToDie();

                // get next line from socket        
                $strLine = trim($this->readLine());
            }
            catch (OutOfTweetsException $objException)
            {
                // exit after maximum tweet count reached 
                return false;
            }
            catch (OutOfTimeException $objException)
            {
                // exit maximum run time has been reached
                return false;
            }
            catch (Exception $objException)
            {
                // try to reconnect
                try
                {
                    $this->reconnect();
                }
                catch (Exception $objException)
                {
                    $this->disconnect();
                    throw $objException;
                }
            }
            
            // we only care about lines that contain a json object
            if ($strLine && "{" == $strLine[0])
            {
                $objData = json_decode($strLine, true);
                
                // we only care about lines that are actually a tweet
                if (is_array($objData) && 
                    isset($objData["text"]) && 
                    isset($objData["user"]) && 
                    isset($objData["user"]["screen_name"]))
                {
                    $this->intTweets++;
                    return $objData;
                }
            }
        }
    }
    
    //------------------------------------------------------------------------//
    // setMaxTweets
    //------------------------------------------------------------------------//
    /**
     * setMaxTweets()
     *
     * Set the maximum number of tweets to be collected
     *
     * The setMaxTweets() method resets the tweet collection counter, so that
     * any previously collected tweets will not count towards the number of 
     * tweets to be collected.
     *
     * @param    integer    $intTweets        maximum number of tweets to be 
     *                                        collected
     *
     * @return   void
     */
    public function setMaxTweets($intTweets)
    {
        $this->intTweets    = 0;
        $this->intMaxTweets = $intTweets;
    }
        
    //------------------------------------------------------------------------//
    // setEndTime
    //------------------------------------------------------------------------//
    /**
     * setEndTime()
     *
     * set the end time for collection of tweets
     *
     * @param    integer    $intTimestamp     unix timestamp (in seconds)
     *
     * @return   void
     */
    public function setEndTime($intTimestamp)
    {
        $this->intStopAt    = $intTimestamp;
    }
    
    //------------------------------------------------------------------------//
    // setVerbosity
    //------------------------------------------------------------------------//
    /**
     * setVerbosity()
     *
     * set runtime verbosity level for debugging
     *
     * not for use in production environment
     *
     * @param    integer    $intVerbosity     a higher number = more verbosity
     *
     * @return   void
     */
    public function setVerbosity($intVerbosity)
    {
        $this->intVerbosity = $intVerbosity;
    }
    
    //------------------------------------------------------------------------//
    // getHostAddress  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * getHostAddress()
     *
     * get an IP address from a hostname
     *
     * If the hostname has multiple IP addresses, one address will be randomly
     * selected
     *
     * @return   string
     */
    protected function getHostAddress()
    {
        // add a "." to the end of the hostname to prevent local domain search
        $arrIP = gethostbynamel($this->strHost.".");
        
        if(empty($arrIP))
        {
            throw new NetworkDnsException(ERROR_DNS_HOST_NOT_FOUND,
                                          ERRNO_DNS_HOST_NOT_FOUND);
        }

        // there may be more than 1 IP address, so select one at random
        $strIP = array_rand(array_flip($arrIP));
    
        return $this->strProtocol.$strIP;
    }
        
    //------------------------------------------------------------------------//
    // readLine  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * readLine()
     *
     * read a single line from the socket
     *
     * The readLine() method will block and wait for the next line to become
     * available in the socket buffer. Lines must be "\r\n" (CRLF) terminated.
     * 
     * If a socket error occurs an exception will be thrown of type 
     * NetworkSocketException with an error code of ERRNO_SOCKET_EOF
     * 
     * If the socket reaches EOF an exception will be thrown of type 
     * NetworkSocketException with an error code of ERRNO_SOCKET_ERROR
     * 
     * If the socket times out without receiving a full line of data an 
     * exception will be thrown of type NetworkSocketException with an error
     * code of ERRNO_SOCKET_TIMEOUT
     *
     * The socket timeout takes into consideration the end time set by the 
     * setEndTime() method, so a timeout may occur because the maximum execution
     * time has been reached.
     * 
     * If end time has been set and the end time has been reached, the socket
     * will be disconnected and an exception will be thrown of type 
     * OutOfTimeException
     *
     * @return   string    a single \r\n terminated line read from the socket.
     *                     The return value includes the \r\n line ending.
     */
    protected function readLine()
    {
        // check if a line of data is available on the socket 
        while (!$strLine = $this->bufferLine())
        {
            // check for EOF
            if (stream_get_meta_data($this->socTwitter)["eof"])
            {
                throw new NetworkSocketException(ERROR_SOCKET_EOF, 
                                                 ERRNO_SOCKET_EOF);
            }
        
            // wait for data to be available on the socket
            $null       = null;
            $arrSockets = Array($this->socTwitter);
            $intTimeout = $this->getTimeout(CONNECT_IDLE_TIMEOUT);
            $intData    = stream_select($arrSockets, $null, $null, $intTimeout);
            if (0 === $intData)
            {
                // socket timed out without recieving any data
                throw new NetworkSocketException(ERROR_SOCKET_TIMEOUT, 
                                                 ERRNO_SOCKET_TIMEOUT);
            }
            elseif (false === $intData)
            {
                // there was an error
                throw new NetworkSocketException(ERROR_SOCKET_ERROR, 
                                                 ERRNO_SOCKET_ERROR);
            }
        }
        $this->debug($strLine, 99);
        return $strLine;
    }
    
    //------------------------------------------------------------------------//
    // bufferLine  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * bufferLine()
     *
     * buffer data from the socket and return it as a full line.
     * 
     * The bufferLine() method will NOT block. It will return immediately with 
     * either a single full line from the socket or boolean false. Lines must 
     * be "\r\n" (CRLF) terminated.
     * 
     * The bufferLine() method will return boolean false if it can not read from
     * the socket for any reason (including EOF or any errors). You should check
     * the socket for EOF after you have called bufferLine().
     *
     * @return   string    a single \r\n terminated line read from the socket.
     *                     The return value includes the \r\n line ending.
     *           bool      false   a full line has not been received yet, or
     *                             an error has occured. 
     */
    protected function bufferLine()
    {
        // grab data from the socket (may not be a full line)
        $strLine = fgets($this->socTwitter);
        if (!$strLine)
        {
            // an error or no more data or no more data available yet
            return false;
        }
        elseif ("\r\n" == substr($strLine, -2, 2))
        {
            // a full line has been received, return it
            $strBuffer       = $this->strBuffer.$strLine;
            $this->strBuffer = "";
            return $strBuffer;
        }
        // add partial line to buffer
        $this->strBuffer .= $strLine;
        return false;
    }
    
    //------------------------------------------------------------------------//
    // reconnect  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * reconnect()
     *
     * Reconnect the socket connection
     *
     * Reconnect the socket after a sucessfull connection has been dropped or 
     * been idle for too long.
     * 
     * The reconnect() method will automatically make multiple attempts to 
     * reconnect (if required) and will back-off connection attempts as per
     * the Twitter streaming API reconnection guidelines at: 
     * https://dev.twitter.com/docs/streaming-apis/connecting#Reconnecting
     * 
     * If the maximum number of retrys has been reached, an exception will be 
     * thrown of type NetworkRetryException with the error code and message
     * from the most recent connection error.
     * 
     * If end time has been set and the end time has been reached, the socket
     * will be disconnected and an exception will be thrown of type 
     * OutOfTimeException
     *
     * @param    integer    $intTimeout       optional the connection timeout
     *                                        in seconds
     *                                        default = 30
     *
     * @return   void
     */
    protected function reconnect($intTimeout=30)
    {
        // keep trying to reconnect
        while (true)
        {
            // try to reconnect
            try
            {
                $this->connect($this->strEndPoint, $intTimeout);
                // break if we get a connection
                break;
            }
            catch (Exception $objException)
            {
                // backoff before trying to reconnect
                $this->backoff($objException);
            }
        }
    }
    
    //------------------------------------------------------------------------//
    // backoff  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * backoff()
     *
     * A backoff timer 
     *
     * Calculates the correct backoff time based on the exception type and
     * current backoff state, then waits for that amount of time to allow 
     * backoff of reconnection attempts.
     * 
     * The backoff time calculation is based on the Twitter streaming API 
     * reconnection guidelines at: 
     * https://dev.twitter.com/docs/streaming-apis/connecting#Reconnecting
     *
     * If the maximum number of retrys has been reached, an exception will be 
     * thrown of type NetworkRetryException with the error code and message
     * from the most recent connection error.
     * 
     * If end time has been set and the end time has been reached, the socket
     * will be disconnected and an exception will be thrown of type 
     * OutOfTimeException
     * 
     * @param    object     $objException     an exception object
     *
     * @return   void
     */
    protected function backoff($objException)
    {
        // set some defaults
        $intMaxTrys     = 10; 
        $intMaxBackoff  = 0; 
        $bolExponential = true;
        
        // set backoff values based on error type
        switch (get_class($objException))
        {
            // TCP/IP level network errors.
            case "NetworkTcpException":
                // Increase the delay in reconnects by 250ms each attempt, 
                // up to 16 seconds.
                $intType        = ERRNO_TCP_ERROR;
                $intBackoff     = 250;
                $intMaxTrys     = 64;
                $intMaxBackoff  = 16000;
                $bolExponential = false;
                break;
                
            // HTTP errors
            case "NetworkHttpException":
                switch ($objException->getCode())
                {
                    // HTTP 420 errors.
                    case ERRNO_HTTP_ENHANCE_YOUR_CALM:
                    case ERRNO_HTTP_TOO_MANY_REQUESTS: // (RFC 6585)
                        // Start with a 1 min wait and double each attempt.
                        $intType        = ERRNO_HTTP_ENHANCE_YOUR_CALM;
                        $intBackoff     = 60000;
                        $intMaxTrys     = 5;
                        break;
                    // All other interesting HTTP errors
                    case ERRNO_HTTP_ERROR:
                    case ERRNO_HTTP_UNAUTHORIZED:
                    case ERRNO_HTTP_FORBIDDEN:
                    case ERRNO_HTTP_NOT_FOUND:
                    case ERRNO_HTTP_REQUEST_TIMEOUT:
                    case ERRNO_HTTP_IM_A_TEAPOT:
                    case ERRNO_HTTP_INTERNAL_SERVER_ERROR:
                    case ERRNO_HTTP_NOT_IMPLEMENTED:
                    case ERRNO_HTTP_BAD_GATEWAY:
                    case ERRNO_HTTP_SERVICE_UNAVAILABLE:
                    case ERRNO_HTTP_GATEWAY_TIMEOUT:
                        // Start with a 5 sec wait, doubling each attempt, 
                        // up to 320 seconds.
                        $intType        = ERRNO_HTTP_ERROR;
                        $intBackoff     = 5000;
                        $intMaxTrys     = 7;
                        $intMaxBackoff  = 320000;
                        break;
                    default:
                        // any other HTTP error we don't retry
                        throw $objException;
                        break;
                }
                break;
            
            // DNS errors
            case "NetworkDnsException":
                $intType        = ERRNO_DNS_ERROR;
                $intBackoff     = 1000;
                $intMaxTrys     = 8;
                $intMaxBackoff  = 8000;
                break;
                
            // out of time
            case "OutOfTimeException":
            // any other errors
            default:
                // die in a fire
                throw $objException;
                break;
        }
        
        // check if we are already in a backoff of this type    
        if ($this->intBackoffType == $intType)
        {
            // increment the backoff count
            $this->intBackoffCount++;
            
            // increase backoff time for successive Backoff requests
            if (true == $bolExponential)
            {
                // increase exponentialy
                $intBackoff = $this->intLastBackoff * 2;
            }
            else
            {
                // increase linearly
                $intBackoff = $this->intLastBackoff + $intBackoff;
            }
            
            // throw an exception if we have reached the maximum retry limit
            if ($this->intBackoffCount > $intMaxTrys)
            {
                throw new NetworkRetryException($objException->getMessage(), 
                                                $objException->getCode());
            }
        }
        else
        {
            // cache details for initial backoff request
            $this->intBackoffType  = $intType;
            $this->intBackoffCount = 1;
        }
                
        // limit backoff time to specified maximum
        if ($intMaxBackoff > 0 && $intMaxBackoff <= $intBackoff)
        {
            $intBackoff = $intMaxBackoff;
            
            // notify that the maximum reconnect time has been reached
            error_log(ERROR_CONNECT_RETRY_TOP);
            error_log($objException->getCode()." : ".
                      $objException->getMessage());
        }
        
        // notify every time we backoff without a specified maximum
        if (0 == $intMaxBackoff)
        {
            error_log(ERROR_CONNECT_RETRY);
            error_log($objException->getCode()." : ".
                      $objException->getMessage());
        }
        
        // limit backoff time to remaining execution time
        $intBackoff= $this->getTimeout($intBackoff / 1000) * 1000;

        $this->intLastBackoff = $intBackoff;
        
        // wait around for a bit
        usleep($this->intLastBackoff * 1000);
    }
    
    //------------------------------------------------------------------------//
    // checkIfWeNeedToDie  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * checkIfWeNeedToDie()
     *
     * Check if max tweets or end time have been reached
     *
     * If max tweets has been set and the max tweets have been reached, the 
     * socket will be disconnected and an exception will be thrown of type 
     * OutOfTweetsException
     * 
     * If end time has been set and the end time has been reached, the socket
     * will be disconnected and an exception will be thrown of type 
     * OutOfTimeException
     *
     * @return   void
     */
    protected function checkIfWeNeedToDie()
    {
        // check if we have reached max tweets
        if ($this->intMaxTweets > 0 &&
            $this->intMaxTweets == $this->intTweets)
        {
            $this->debug('max tweets reached');
            $this->disconnect();
            throw new OutOfTweetsException(ERROR_OUT_OF_TWEETS, 
                                           ERRNO_OUT_OF_TWEETS);
        }
        
        // check if we have reached the end time
        $this->getTimeout(1);
    }
    
    //------------------------------------------------------------------------//
    // getTimeout  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * getTimeout()
     *
     * Get a valid timeout lenght in seconds
     *
     * Calculates a valid timeout length, given a requested timeout length and 
     * taking in to consideration the end time set using the setEndTime()
     * method (if an end time has been set).
     * 
     * If end time has been set and the end time has been reached, the socket
     * will be disconnected and an exception will be thrown of type 
     * OutOfTimeException
     *
     * @param    number     $numTimeout       requested timeout length (seconds)
     *
     * @return   number    number of seconds to wait before timing out
     */
    protected function getTimeout($numTimeout)
    {
        // no end time set, return specified timeout
        if (0 == $this->intStopAt)
        {
            return $numTimeout;
        }
        
        // end time is set, check how long we have left
        $intRemaining = $this->intStopAt - time();
        if ($intRemaining < 1)
        {
            // we are out of time, so we throw an exception
            $this->debug('max runtime reached');
            $this->disconnect();
            throw new OutOfTimeException(ERROR_OUT_OF_TIME, ERRNO_OUT_OF_TIME);
        }
        
        // return the lower of remaining time and specified timeout
        return min($intRemaining, $numTimeout);
    }
    
    //------------------------------------------------------------------------//
    // debug  (PROTECTED METHOD)
    //------------------------------------------------------------------------//
    /**
     * debug()
     *
     * displays a message for debugging
     *
     * By default the runtime verbosity level is set to 0 and no debug messages 
     * are displayed. The runtime verbosity level can be inreased using the 
     * setVerbosity() method.
     * 
     * Set a higher verbosity level for less important or more descriptive
     * debug messages.
     *
     * @param    string     $strMessage       message to be displayed
     * @param    integer    $intVerbosity     optional verbosity level required
     *                                        for the message to be displayed
     *                                        default = 5
     *
     * @return   void
     */
    protected function debug($strMessage, $intVerbosity=5)
    {
        if ($this->intVerbosity >= $intVerbosity)
        {
            error_log(rtrim($strMessage));
        }
    }
}


//----------------------------------------------------------------------------//
// Exception classes
//----------------------------------------------------------------------------//

class OptionMissingException extends Exception {}
class OptionHelpException    extends Exception {}
class NetworkTcpException    extends Exception {}
class NetworkHttpException   extends Exception {}
class NetworkDnsException    extends Exception {}
class NetworkSocketException extends Exception {}
class NetworkRetryException  extends Exception {}
class OutOfTimeException     extends Exception {}
class OutOfTweetsException   extends Exception {}


//============================================================================//
// Constants
//============================================================================//

function define_constants()
{
    // TCP/IP error codes
    define("ERRNO_TCP_ERROR",                   111);

    // HTTP error codes
    define("ERRNO_HTTP_OK",                     200);
    define("ERRNO_HTTP_UNAUTHORIZED",           401);
    define("ERRNO_HTTP_FORBIDDEN",              403);
    define("ERRNO_HTTP_NOT_FOUND",              404);
    define("ERRNO_HTTP_REQUEST_TIMEOUT",        408);
    define("ERRNO_HTTP_IM_A_TEAPOT",            418);
    define("ERRNO_HTTP_ENHANCE_YOUR_CALM",      420);
    define("ERRNO_HTTP_TOO_MANY_REQUESTS",      429);
    define("ERRNO_HTTP_INTERNAL_SERVER_ERROR",  500);
    define("ERRNO_HTTP_NOT_IMPLEMENTED",        501);
    define("ERRNO_HTTP_BAD_GATEWAY",            502);
    define("ERRNO_HTTP_SERVICE_UNAVAILABLE",    503);
    define("ERRNO_HTTP_GATEWAY_TIMEOUT",        504);
    define("ERRNO_HTTP_ERROR",                  600);

    // Socket error codes
    define("ERRNO_SOCKET_ERROR",                1000);
    define("ERRNO_SOCKET_TIMEOUT",              1010);
    define("ERRNO_SOCKET_EOF",                  1020);

    // connection error codes
    define("ERRNO_CONNECT_RETRY_MAX",           2010);
    
    // DNS error codes
    define("ERRNO_DNS_ERROR",                   3000);
    define("ERRNO_DNS_HOST_NOT_FOUND",          3010);
    
    define("ERRNO_OUT_OF_TIME",                 4000);
    define("ERRNO_OUT_OF_TWEETS",               5000);
}

?>
