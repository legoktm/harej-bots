<?php

class MediawikiPage {
	private $wiki, $title, $content, $id, $exists, $ecTimestamp=null;
	
	public function __construct ($wiki,$title) {
		$this->wiki = $wiki;
		$this->title = str_replace('_',' ',$title);
		$this->getContent();
	}
	
	private function getContent ($retry = 0) {
		$this->exists = true;
		
		$x = $this->wiki->query( array(
									'action' => 'query',
									'prop' => 'revisions',
									'titles' => $this->title,
									'rvlimit' => 1,
									'rvprop' => 'content|timestamp'
								) );
		
		if ($this->wiki->http->http_code() != "200") {
			if ($retry < 10) {
				$this->getContent(++$retry);
			} else {
				throw new Exception("HTTP Error.");
			}
		}
		
		if (isset($x['query']['pages'][-1]['missing']) || isset($x['query']['pages'][-1]['invalid'])) {
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
	
	public function edit ($content,$summary="",$minor=false,$bot=true,$retry=true) {
        $post = array(
                      'title'   => $this->title,
                      'text'    => $content,
                      'md5'     => md5( $content ),
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
            $this->wiki->getedittoken(true);
            return $this->edit($content,$summary,$minor,$bot,false);
        }
		
		$this->getContent();
	}
	
	public function addSection ($heading,$content,$minor=false,$bot=true,$retry=true) {
        $post = array(
                      'title'   => $this->title,
                      'appendtext'    => $content,
                      'md5'     => md5( $content ),
                      'sectiontitle' => $heading,
                      'section' => 'new',
                      'token'   => $this->wiki->getedittoken(),
					  'basetimestamp' => $this->ecTimestamp
                     );
        if ( $minor )
            $post['minor'] = true;
        if ( $bot )
            $post['bot'] = true;
            
        $x = $this->wiki->query( array('action' => 'edit'), $post );
        
        if ( $x['error']['code'] == 'badtoken' && $retry ) {
            $this->wiki->getedittoken(true);
            return $this->addSection($heading,$content,$minor,$bot,false);
        }
		
		$this->getContent();
	}
	
	public function exists () {
		return $this->exists;
	}
	
	public function resolveRedirects () {
		if (preg_match('/#redirect\s+\[\[(.+?)\]\]/i',$this->content,$m)) {
			$this->__construct($this->wiki,$m[1]);
		}
	}
}