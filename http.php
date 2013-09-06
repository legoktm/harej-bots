<?php

/**
 * This class is designed to provide a simplified interface to cURL which maintains cookies.
 * @author Chris
 **/
class http {
    protected $curl;
    protected $cookiejar;
    public $useragent;
    
	public function http_code () {
		return curl_getinfo( $this->ch, CURLINFO_HTTP_CODE );
	}
    
    public function __construct () {
        $this->useragent = 'PHP cURL';
        $this->curl = curl_init();
        
        $this->cookiejar = '/tmp/http.cookies.'.dechex( rand( 0,99999999 ) ).'.dat';
        touch( $this->cookiejar );
        chmod( $this->cookiejar, 0600 );
        curl_setopt( $this->curl, CURLOPT_COOKIEJAR,$this->cookiejar );
        curl_setopt( $this->curl, CURLOPT_COOKIEFILE,$this->cookiejar );
    }
    
    /**
     * Sends a GET request
     * @param $url is the address of the page you are looking for
     * @returns the page you asked for
     **/
    public function get ($url) {
        curl_setopt( $this->curl, CURLOPT_URL, $url );
        curl_setopt( $this->curl, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $this->curl, CURLOPT_MAXREDIRS, 10 );
        curl_setopt( $this->curl, CURLOPT_HEADER, false );
        curl_setopt( $this->curl, CURLOPT_HTTPGET, true );
        curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $this->curl, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $this->curl, CURLOPT_TIMEOUT, 40 );
        curl_setopt( $this->curl, CURLOPT_USERAGENT, $this->useragent );
        return curl_exec( $this->curl );
    }
    
    /**
     * Sends a POST request
     * @param $url is the address of the page you are looking for
     * @param $data is the post data.
     * @returns the page you asked for
     **/
    public function post ($url, $data) {
        curl_setopt( $this->curl, CURLOPT_URL, $url );
        curl_setopt( $this->curl, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $this->curl, CURLOPT_MAXREDIRS, 10 );
        curl_setopt( $this->curl, CURLOPT_HEADER, false );
        curl_setopt( $this->curl, CURLOPT_POST, true );
        curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $data );
        curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $this->curl, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $this->curl, CURLOPT_TIMEOUT, 40 );
        curl_setopt( $this->curl, CURLOPT_USERAGENT, $this->useragent );
        curl_setopt( $this->curl, CURLOPT_HTTPHEADER, array( 'Expect:' ) );
        return curl_exec( $this->curl );
    }
    
    public function __destruct () {
        curl_close( $this->curl );
        @unlink( $this->cookiejar );
    }
}

