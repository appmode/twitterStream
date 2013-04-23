<?php

// enable unit test mode
define("UNIT_TEST",  true);

// include the script
require_once("twitterStream.php");


//============================================================================//
// Tests
//============================================================================//

//----------------------------------------------------------------------------//
// Twitter stream class
//----------------------------------------------------------------------------//

class twitterTest extends PHPUnit_Framework_TestCase
{
    //------------------------------------------------------------------------//
    // backoff
    //------------------------------------------------------------------------//
    
    function testBackoff()
    {
        $objTwitter = new twitterTestStream("void", "void");

        // set up exceptions
        $objTcpException  = new NetworkTcpException();
        $objDnsException  = new NetworkDnsException();
        $objTimeException = new OutOfTimeException();
        
        // unusable exception should be re-thrown
        try
        {
            $objTwitter->public_backoff($objTimeException);
            $this->fail("an Exception was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        // 1st backoff should be 250ms
        $objTwitter->public_backoff($objTcpException);
        $this->assertEquals($objTwitter->intLastBackoff, 250);
        
        // linear backoff
        // 2nd backoff should be 500ms
        $objTwitter->public_backoff($objTcpException);
        $this->assertEquals($objTwitter->intLastBackoff, 500);
        
        // new error type should reset the backoff
        // 1st backoff should be 1000ms
        $objTwitter->public_backoff($objDnsException);
        $this->assertEquals($objTwitter->intLastBackoff, 1000);
        
        // exponential backoff
        // 2nd backoff should be 2000ms
        $objTwitter->public_backoff($objDnsException);
        $this->assertEquals($objTwitter->intLastBackoff, 2000);
        
        // exponential backoff
        // 3rd backoff should be 4000ms
        $objTwitter->public_backoff($objDnsException);
        $this->assertEquals($objTwitter->intLastBackoff, 4000);
        
        // 1st backoff should be 250ms
        $objTwitter->public_backoff($objTcpException);
        $this->assertEquals($objTwitter->intLastBackoff, 250);
        
        // max backoff should be 16000ms
        $objTwitter->intLastBackoff = 16000;
        $objTwitter->public_backoff($objTcpException);
        $this->assertEquals($objTwitter->intLastBackoff, 16000);
        
        // max retry should be 64
        $objTwitter->intBackoffCount = 63;
        $objTwitter->public_backoff($objTcpException);
        // 64th retry should be allowed
        $this->assertEquals($objTwitter->intLastBackoff, 16000);
        // 65th retry should throw an exception
        try
        {
            $objTwitter->public_backoff($objTcpException);
            $this->fail("a NetworkRetryException was expected");
        }
        catch (NetworkRetryException $e)
        {
        }
        
        // 1st backoff should be 1000ms
        $objTwitter->public_backoff($objDnsException);
        $this->assertEquals($objTwitter->intLastBackoff, 1000);

        // max time reached
        $objTwitter->setEndTime(time());
        try
        {
            $objTwitter->public_backoff($objTcpException);
            $this->fail("an OutOfTimeException was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        //TODO!!!! : we should test the output to stderr
        //TODO!!!! : we should test that the script is waiting the correct time
    }
    
    //------------------------------------------------------------------------//
    // checkIfWeNeedToDie
    //------------------------------------------------------------------------//
    
    function testCheckIfWeNeedToDie()
    {
        $objTwitter = new twitterTestStream("void", "void");
        
        // should not throw an exception if we have no reason to die
        $objTwitter->public_checkIfWeNeedToDie();
        
        // should not throw an exception if we have no tweets
        $objTwitter->setMaxTweets(5);
        $objTwitter->public_checkIfWeNeedToDie();
        
        // should not throw an exception if we have not reached maximum tweets
        $objTwitter->intTweets = 4;
        $objTwitter->public_checkIfWeNeedToDie();
        
        // should throw an exception if we have reached maximum tweets
        $objTwitter->intTweets = 5;
        try
        {
            $objTwitter->public_checkIfWeNeedToDie();
            $this->fail("an OutOfTweetsException was expected");
        }
        catch (OutOfTweetsException $e)
        {
        }
        
        // should not throw an exception if we have not reached maximum tweets
        $objTwitter->intTweets = 4;
        $objTwitter->public_checkIfWeNeedToDie();
        
        // should throw an exception if max time reached
        $objTwitter->setEndTime(time());
        try
        {
            $objTwitter->public_checkIfWeNeedToDie();
            $this->fail("an OutOfTimeException was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        // should throw an exception if max time exceeded
        $objTwitter->setEndTime(time() - 5);
        try
        {
            $objTwitter->public_checkIfWeNeedToDie();
            $this->fail("an OutOfTimeException was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        // should not throw an exception if max time not reached
        $objTwitter->setEndTime(time() + 5);
        $objTwitter->public_checkIfWeNeedToDie();
    }
    
    //------------------------------------------------------------------------//
    // getTimeout
    //------------------------------------------------------------------------//
    
    function testGetTimeout()
    {
        $objTwitter = new twitterTestStream("void", "void");
        
        // should return correct timeout if max time not set
        $this->assertEquals($objTwitter->public_getTimeout(30), 30);
        
        // should throw an exception if max time reached
        $objTwitter->setEndTime(time());
        try
        {
            $objTwitter->public_getTimeout(30);
            $this->fail("an OutOfTimeException was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        // should throw an exception if max time exceeded
        $objTwitter->setEndTime(time() - 5);
        try
        {
            $objTwitter->public_getTimeout(30);
            $this->fail("an OutOfTimeException was expected");
        }
        catch (OutOfTimeException $e)
        {
        }
        
        // should not throw an exception if max time not reached
        // should return a reduced timeout
        $objTwitter->setEndTime(time() + 5);
        $this->assertEquals($objTwitter->public_getTimeout(30), 5);
        
        // should not throw an exception if max time not reached
        // should return correct timeout
        $objTwitter->setEndTime(time() + 60);
        $this->assertEquals($objTwitter->public_getTimeout(30), 30);
    }
}

//============================================================================//
// Classes
//============================================================================//

//----------------------------------------------------------------------------//
// Twitter test stream class
//----------------------------------------------------------------------------//
/*
 * Adds public methods to access protected methods in the twitterStream class
 */
class twitterTestStream extends twitterStream
{
    public function public_getHostAddress()
    {
        return $this->getHostAddress();
    }
    
    public function public_readLine()
    {
        return $this->readLine();
    }
    
    public function public_bufferLine()
    {
        return $this->bufferLine();
    }
    
    public function public_reconnect($intTimeout)
    {
        return $this->reconnect($intTimeout);
    }
    
    public function public_backoff($objException)
    {
        return $this->backoff($objException);
    }
    
    public function public_checkIfWeNeedToDie()
    {
        return $this->checkIfWeNeedToDie();
    }
    
    public function public_getTimeout($numTimeout)
    {
        return $this->getTimeout($numTimeout);
    }
}

?>
