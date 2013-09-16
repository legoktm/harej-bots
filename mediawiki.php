<?php

require_once 'Page.php';

class mediawiki {
	protected $username;	/* @string - The bot's username (if it has one). */
	protected $password;	/* @string - The bot's password (if it has one). */
	protected $loggedin;	/* @bool   - Is the bot logged in? */
	protected $edittoken;   /* @string - Our edit token (see http://www.mediawiki.org/wiki/Manual:Edit_token). */
	protected $url;		 /* @string - The url to api.php. */
	protected $lasterror;   /* @string - Contains the last error the bot had. */
	public $http;		   /* @class  - Our http client. */

	/**
	 * @desc Our constructor. Sets up the http client for us to use and sets up all the variables. Will also log the user in if a username and password are suppied.
	 * @param string (default=http://en.wikipedia.org/w/api.php) $url - A url to mediawiki's api.php.
	 * @param string (default=null) $username  - The account's username.
	 * @param string (default=null) $password  - The account's password.
	 * @param int (default=0) $maxlag - The maxlag level to use when making an edit.
	 */
	public function __construct ( $username = null, $password = null, $url = 'http://en.wikipedia.org/w/api.php', $maxlag = 0 ) {
		require_once 'http.php';
		$this->http = new http();
		$this->http->useragent = 'PHP Mediawiki Client';
		
		$this->ectimestamp = null;
		$this->edittoken   = null;
		$this->username	= $username;
		$this->password	= $password;
		$this->loggedin	= false;
		$this->url		 = $url;
		$this->lasterror   = null;
		$this->maxlag	  = $maxlag;
		
		if ( $username != null && $password != null )
		{
			$return = $this->login( $username, $password );
			if ( ! $return )
				die( 'Error logging in: ' . $this->lasterror );
		}
	}

	/**
	 * @desc Returns the last error the script ran into.
	 * @returns string
	 */
	public function lasterror () {
		return $this->lasterror;
	}

	public function query ($query, $post = null) {
		$query = $this->queryString($query);
		if ( $post == null )
			$data = $this->http->get($this->url . $query);
		else
			$data = $this->http->post($this->url . $query, $post);
		return unserialize($data);
	}

	protected function queryString ($query) {
		$return = "?format=php";
		foreach ($query as $key => $value) {
			$return .=  "&" . urlencode($key) . "=" . urlencode($value);
		}
		return $return;
	}


	/**
	 * @desc Logs the account into mediawiki.
	 * @param string $username - The account's username.
	 * @param string $password - The account's password.
	 * @returns bool - Returns true on success, false on failure.
	 */
	public function login ( $username, $password ) {
		$post = array(
					  'lgname'	 => $username,
					  'lgpassword' => $password
					 );
					 
		while (true) {
			$return = $this->query( array('action' => 'login'), $post );
			var_dump($return);
			if ($return['login']['result'] == 'Success') {
					$this->loggedin = true;
					return true;
			} elseif ( $return['login']['result'] == 'NeedToken' ) {
				$post['lgtoken'] = $return['login']['token'];
			} else {
				$this->lasterror = $return['login']['code'];
				return false;
			}
		}
	}

	/**
	 * @desc Logs the account out of the wiki and destroys all their session data.
	 */
	public function logout () {
		$this->query( array('action' => 'logout') );
		$this->edittoken   = null;
		$this->lasterror   = null;
		$this->loggedin	= false;
	}

	public function page ($title) {
		return new MediawikiPage($this,$title);
	}

	/**
	 * @desc Returns the bot's edit token.
	 * @param bool (default=false) $force - Force the script to get a fresh edit token.
	 * @returns mixed - Returns the account's edittoken on success or false on failure.
	 */
	public function getedittoken ($force = false) {
		if ( $this->edittoken != null && $force == false )
			return $this->edittoken;
		$x = $this->query( array('action' => 'query', 'prop' => 'info', 'intoken' => 'edit', 'titles' => 'Main Page' ) );
		@$id = key( $x['query']['pages'] );
		if ( isset( $x['query']['pages'][$id]['edittoken'] ) )
			return $x['query']['pages'][$id]['edittoken'];

		$this->lasterror = 'notoken';
		return false;
	}

	/**
	 * @desc Our destructor, logs the account out if it is still logged in.
	 */
	public function __destruct ()
	{
		if ( $this->loggedin )
			$this->logout();
	}
}

