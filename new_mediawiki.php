<?php
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

	/**
	 * Make an API request
	 * @param $query array
	 * @param bool|null $post
	 * @return mixed
	 */
	public function query( $query, $post = null ) {
		$query = $this->queryString($query);
		if ( $post === null || $post === false ) {
			$data = $this->http->get($this->url . $query);
		} else {
			$data = $this->http->post($this->url . $query, $post);
		}
		return unserialize($data);
	}

	/**
	 * @param $query array
	 * @return string
	 */
	protected function queryString( $query ) {
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
	 * @return bool - Returns true on success, false on failure.
	 */
	public function login ( $username, $password ) {
		$post = array(
					  'lgname'	 => $username,
					  'lgpassword' => $password
					 );
					 
		while (true) {
			$return = $this->query( array('action' => 'login'), $post );
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
	 * @return mixed - Returns the account's edittoken on success or false on failure.
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

	// TODO: rewrite this function to be neat
	public function getTransclusions( $page, $sleep=null ) {
		$continue = '';
		$pages = array();
		while (true) {
			$q = array(
					'action' => 'query',
					'list' => 'embeddedin',
					'eititle' => $page,
					'eilimit' => 500
				);
				
			if ($continue != '')
				$q['eicontinue'] = $continue;
				
			$ret = $this->query($q);
			
			if ($sleep != null)
				sleep( $sleep );
				
			foreach ($ret['query']['embeddedin'] as $x) {
				$pages[] = $x['title'];
			}
			if (isset($ret['query-continue']['embeddedin']['eicontinue'])) {
				$continue = $ret['query-continue']['embeddedin']['eicontinue'];
			} else {
				return $pages;
			}
		}
	}

	public function getpage( $title ) {
		$pg = new MediawikiPage($this, $title);
		return $pg->content();
	}

}

class MediawikiPage {
	/** @var mediawiki */
	private $wiki;
	/** @var string */
	private $title;
	/** @var string */
	private $content;
	/** @var int */
	private $id;
	/** @var bool */
	private $exists;
	private $ecTimestamp = null;

	public function __construct ($wiki,$title) {
		echo "New page [[$title]].\n";
		$this->wiki = $wiki;
		$this->title = str_replace('_',' ',$title);
		$this->getContent();
	}
	
	private function getContent ($retry = 1) {
		echo "Getting content for [[".$this->title."]]\n";
		$this->exists = true;
		
		$x = $this->wiki->query( array(
									'action' => 'query',
									'prop' => 'revisions',
									'titles' => $this->title,
									'rvlimit' => 1,
									'rvprop' => 'content|timestamp'
								) );
		
		if ($this->wiki->http->http_code() != "200") {
			echo "HTTP Eror Code: " . $this->wiki->http->http_code() . "\n";
			if ($retry < 10) {
				echo "Error getting content, retrying.\n";
				sleep(($retry * 2));
				$this->getContent(++$retry);
			} else {
				echo "Error getting content, dying.\n";
				throw new Exception("HTTP Error.");
			}
		}
		
		if (isset($x['query']['pages'][-1]['missing']) || isset($x['query']['pages'][-1]['invalid'])) {
			echo "Page does not exist.\n";
			$this->exists = false;
			return;
		}
		
		@$this->id = key($x['query']['pages']);
		if (!isset($x['query']['pages'][$this->id]['revisions'][0]['*'])) {
			throw new Exception("Unknown Error retreiving page.");
		}
		
		$this->content = $x['query']['pages'][$this->id]['revisions'][0]['*'];
		$this->ecTimestamp = $x['query']['pages'][$this->id]['revisions'][0]['timestamp'];
	}
	
	public function __toString () {
		return $this->title;
	}
	
	public function title () {
		return $this->title;
	}
	
	public function content () {
		return $this->content;
	}

	/**
	 * @param string $content
	 * @param string $summary
	 * @param bool $minor
	 * @param bool $bot
	 * @param bool $retry whether to retry on bad token errors
	 * @return array
	 */
	public function edit ($content,$summary="",$minor=false,$bot=true,$retry=true) {
		echo "Saving [[".$this->title."]]\n";
		$post = array(
					  'title'   => $this->title,
					  'text'	=> $content,
					  'md5'	 => md5( $content ),
					  'summary' => $summary,
					  'token'   => $this->wiki->getedittoken(),
					  'basetimestamp' => $this->ecTimestamp
					 );
		if ( $minor )
			$post['minor'] = true;
		if ( $bot )
			$post['bot'] = true;
			
		$x = $this->wiki->query( array('action' => 'edit'), $post );
		
		if ( $x['error']['code'] == 'badtoken' && $retry ) {
			$this->wiki->getedittoken( true );
			return $this->edit($content,$summary,$minor,$bot,false);
		}
		
		$this->getContent();
		return $x;
	}

	/**
	 * Add a new section to the page
	 * @param $heading string
	 * @param $content string
	 * @param bool $minor
	 * @param bool $bot
	 * @param bool $retry
	 * @return mixed
	 */
	public function addSection ( $heading, $content, $minor=false, $bot=true, $retry=true ) {
		echo "Adding Section \"$heading\" to [[".$this->title."]]\n";
		$post = array(
					'title'   => $this->title,
					'text'	=> $content,
					'md5'	 => md5( $content ),
					'sectiontitle' => $heading,
					'section' => 'new',
					'token'   => $this->wiki->getedittoken(),
					'basetimestamp' => $this->ecTimestamp
					 );
		if ( $minor ) {
			$post['minor'] = true;
		}
		if ( $bot ) {
			$post['bot'] = true;
		}

		$x = $this->wiki->query( array('action' => 'edit'), $post );
		
		if ( $x['error']['code'] == 'badtoken' && $retry ) {
			$this->wiki->getedittoken( true );
			return $this->addSection( $heading, $content, $minor, $bot, false );
		}
		
		$this->getContent();
		return $x;
	}
	
	public function exists () {
		return $this->exists;
	}
	
	public function resolveRedirects () {
		echo "Resoloving redirect.\n";
		if (preg_match('/^\s*#redirect\s+\[\[(.+?)\]\]/i',$this->content,$m)) {
			echo "[[".$this->title."]] redirects to [[".$m[1]."]]\n";
			$this->__construct($this->wiki,$m[1]);
		}
	}
}
